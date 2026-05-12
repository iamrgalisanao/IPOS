<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\QuickBooksConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class QuickBooksConnectionController extends Controller
{
    public function __construct(
        protected QuickBooksConnectionService $connectionService
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeQuickBooksConnection($request);

        return Inertia::render('Accounting/QuickBooks/Connection', [
            'connection' => $this->connectionService->statusForTenant(),
            'flash' => [
                'status' => session('status'),
                'error' => session('error'),
            ],
        ]);
    }

    public function connect(Request $request): HttpResponse
    {
        $this->authorizeQuickBooksConnection($request);

        return Inertia::location($this->connectionService->authorizationUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->authorizeQuickBooksConnection($request);

        if ($request->filled('error')) {
            return redirect()->route('accounting.quickbooks.index')
                ->with('error', $this->connectionService->sanitizeCallbackError(
                    $request->string('error_description', 'QuickBooks authorization was cancelled.')->toString()
                ));
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
            return redirect()->route('accounting.quickbooks.index')->with('error', $this->connectionService->sanitizeCallbackError($exception->getMessage()));
        }

        return redirect()->route('accounting.quickbooks.index')->with('status', 'QuickBooks connected.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->authorizeQuickBooksConnection($request);

        $this->connectionService->disconnect($request->input('reason'));

        return redirect()->route('accounting.quickbooks.index')->with('status', 'QuickBooks disconnected.');
    }

    protected function authorizeQuickBooksConnection(Request $request): void
    {
        if (!$request->user()->hasPermission('manage_quickbooks_connection')) {
            abort(403, 'Unauthorized');
        }
    }
}
