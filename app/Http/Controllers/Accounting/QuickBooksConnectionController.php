<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\QuickBooksConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class QuickBooksConnectionController extends Controller
{
    public function __construct(
        protected QuickBooksConnectionService $connectionService
    ) {}

    public function connect(Request $request): RedirectResponse
    {
        $this->authorizeQuickBooksConnection($request);

        return redirect()->away($this->connectionService->authorizationUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->authorizeQuickBooksConnection($request);

        if ($request->filled('error')) {
            return redirect('/dashboard')->with('error', $request->string('error_description', 'QuickBooks authorization was cancelled.'));
        }

        $request->validate([
            'code' => ['required', 'string'],
            'realmId' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $this->connectionService->handleCallback(
                code: $request->string('code')->toString(),
                realmId: $request->string('realmId')->toString(),
                state: $request->string('state')->toString()
            );
        } catch (RuntimeException $exception) {
            return redirect('/dashboard')->with('error', $exception->getMessage());
        }

        return redirect('/dashboard')->with('status', 'QuickBooks connected.');
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->authorizeQuickBooksConnection($request);

        $connection = $this->connectionService->disconnect($request->input('reason'));

        return response()->json([
            'status' => $connection->status,
            'connected' => false,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermission('view_sync_dashboard')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($this->connectionService->statusForTenant());
    }

    protected function authorizeQuickBooksConnection(Request $request): void
    {
        if (!$request->user()->hasPermission('connect_quickbooks')) {
            abort(403, 'Unauthorized');
        }
    }
}
