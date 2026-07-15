<?php

namespace App\Http\Middleware;

use App\Models\PaymentMethod;
use App\Models\PosAdjustmentRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $isPaymentRequest = str_contains($request->path(), '/payments');
        $requiresIdempotency = !$isPaymentRequest || $this->containsStoreCreditTender($request);

        if (!$requiresIdempotency) {
            return $next($request);
        }

        if (!$idempotencyKey) {
            return response()->json([
                'success' => false,
                'code' => 'MISSING_IDEMPOTENCY_KEY',
                'message' => 'The request is missing the Idempotency-Key header.',
            ], 400);
        }

        $requestHash = hash('sha256', json_encode($request->all()));
        $sale = $request->route('sale');
        $saleId = is_object($sale) ? $sale->id : ($sale ?: $request->route('sale_id'));
        $cashierId = $request->user()?->id;

        $existingRequest = PosAdjustmentRequest::where('idempotency_key', $idempotencyKey)->first();

        if ($existingRequest) {
            if ($existingRequest->request_hash !== $requestHash) {
                return response()->json([
                    'success' => false,
                    'code' => 'IDEMPOTENCY_KEY_REUSE',
                    'message' => 'This Idempotency-Key has already been used for a request with a different payload.',
                ], 409);
            }

            if ($existingRequest->status === 'processing') {
                return response()->json([
                    'success' => false,
                    'code' => 'REQUEST_IN_PROGRESS',
                    'message' => 'A duplicate request is already in progress.',
                ], 409);
            }

            if ($existingRequest->status === 'completed') {
                $snapshot = $existingRequest->response_snapshot;
                $content = isset($snapshot['raw']) ? $snapshot['raw'] : json_encode($snapshot);
                return response($content, 200)
                    ->header('X-Cache-Lookup', 'HIT - Idempotent response')
                    ->header('Content-Type', 'application/json');
            }

            $existingRequest->update(['status' => 'processing']);
            $posRequest = $existingRequest;
        } else {
            $posRequest = PosAdjustmentRequest::create([
                'idempotency_key' => $idempotencyKey,
                'action_type' => $this->actionType($request),
                'sale_id' => $saleId,
                'cashier_id' => $cashierId,
                'request_hash' => $requestHash,
                'status' => 'processing',
            ]);
        }

        try {
            $response = $next($request);

            if ($response->isSuccessful() || $response->isRedirection()) {
                $content = $response->getContent();
                $json = json_decode($content, true);
                $posRequest->update([
                    'status' => 'completed',
                    'response_snapshot' => is_array($json) ? $json : ['raw' => $content],
                ]);
            } else {
                $posRequest->update(['status' => 'failed']);
            }

            return $response;
        } catch (\Throwable $e) {
            $posRequest->update(['status' => 'failed']);
            throw $e;
        }
    }

    private function containsStoreCreditTender(Request $request): bool
    {
        $methodIds = collect($request->input('payments', []))
            ->pluck('payment_method_id');

        if ($request->filled('payment_method_id')) {
            $methodIds->push($request->input('payment_method_id'));
        }

        $methodIds = $methodIds->filter()->unique()->values();
        if ($methodIds->isEmpty()) {
            return false;
        }

        return PaymentMethod::query()
            ->whereIn('id', $methodIds->all())
            ->get()
            ->contains(fn (PaymentMethod $method) => $method->isStoreCredit());
    }

    private function actionType(Request $request): string
    {
        if (str_contains($request->path(), '/payments')) {
            return 'payment';
        }

        if (str_contains($request->url(), 'void')) {
            return 'void';
        }

        return 'refund';
    }
}
