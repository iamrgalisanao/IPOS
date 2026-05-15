<?php

namespace App\Services\Accounting;

use App\Models\QuickBooksConnection;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use RuntimeException;

class QuickBooksConnectionService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected AuditLogger $auditLogger
    ) {}

    public function authorizationUrl(): string
    {
        $this->assertConfigured();

        if (!$this->tenantContext->hasTenant()) {
            throw new RuntimeException('Tenant context is required to connect QuickBooks.');
        }

        $state = Str::uuid()->toString();
        Session::put('quickbooks_oauth_state', $state);
        Session::put('quickbooks_oauth_tenant_id', $this->tenantContext->getTenantId());

        return config('services.quickbooks.authorization_url') . '?' . http_build_query([
            'client_id' => config('services.quickbooks.client_id'),
            'scope' => 'com.intuit.quickbooks.accounting',
            'redirect_uri' => config('services.quickbooks.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    public function handleCallback(string $code, string $realmId, string $state): QuickBooksConnection
    {
        $this->assertConfigured();
        $this->assertExpectedState($state);

        if (!$this->tenantContext->hasTenant()) {
            throw new RuntimeException('Tenant context is required for QuickBooks callback handling.');
        }

        $response = Http::asForm()
            ->withBasicAuth(config('services.quickbooks.client_id'), config('services.quickbooks.client_secret'))
            ->post(config('services.quickbooks.token_url'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.quickbooks.redirect_uri'),
            ]);

        if (!$response->successful()) {
            $message = $response->json('error_description') ?: $response->body();
            $connection = $this->markError($message ?: 'QuickBooks token exchange failed.');
            throw new RuntimeException($connection->last_error);
        }

        $payload = $response->json();
        $now = now();

        $connection = QuickBooksConnection::withoutGlobalScope('tenant')->updateOrCreate(
            ['tenant_id' => $this->tenantContext->getTenantId()],
            [
                'realm_id' => $realmId,
                'status' => QuickBooksConnection::STATUS_CONNECTED,
                'access_token' => $payload['access_token'] ?? null,
                'refresh_token' => $payload['refresh_token'] ?? null,
                'access_token_expires_at' => $now->copy()->addSeconds((int) ($payload['expires_in'] ?? 3600)),
                'refresh_token_expires_at' => $now->copy()->addSeconds((int) ($payload['x_refresh_token_expires_in'] ?? 8726400)),
                'connected_at' => $now,
                'disconnected_at' => null,
                'last_error' => null,
                'metadata' => [
                    'environment' => config('services.quickbooks.environment'),
                    'token_type' => $payload['token_type'] ?? null,
                ],
            ]
        );

        $this->auditLogger->log(
            action: 'quickbooks_connected',
            auditable: $connection,
            afterValues: [
                'realm_id' => $connection->realm_id,
                'status' => $connection->status,
                'environment' => config('services.quickbooks.environment'),
            ]
        );

        Session::forget(['quickbooks_oauth_state', 'quickbooks_oauth_tenant_id']);

        return $connection;
    }

    public function disconnect(?string $reason = null): QuickBooksConnection
    {
        $connection = $this->connectionForTenant();

        if (!$connection) {
            $connection = QuickBooksConnection::create([
                'status' => QuickBooksConnection::STATUS_DISCONNECTED,
            ]);
        }

        $before = $connection->only(['status', 'realm_id']);

        $connection->forceFill([
            'status' => QuickBooksConnection::STATUS_DISCONNECTED,
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
            'disconnected_at' => now(),
            'last_error' => $reason,
        ])->save();

        $this->auditLogger->log(
            action: 'quickbooks_disconnected',
            auditable: $connection,
            beforeValues: $before,
            afterValues: $connection->only(['status', 'realm_id']),
            reason: $reason
        );

        return $connection;
    }

    public function connectionForTenant(?Tenant $tenant = null): ?QuickBooksConnection
    {
        $tenantId = $tenant?->id ?? $this->tenantContext->getTenantId();

        if (!$tenantId) {
            return null;
        }

        return QuickBooksConnection::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function assertConnectedForTenant(?Tenant $tenant = null): QuickBooksConnection
    {
        $connection = $this->connectionForTenant($tenant);

        if (!$connection) {
            throw new RuntimeException('QuickBooks is not connected for this tenant.');
        }

        if ($connection->status !== QuickBooksConnection::STATUS_CONNECTED) {
            throw new RuntimeException('QuickBooks is not connected for this tenant.');
        }

        if ($connection->refreshTokenExpired()) {
            $this->markConnectionExpired($connection, 'QuickBooks refresh token has expired.');
            throw new RuntimeException('QuickBooks refresh token has expired. Reconnect QuickBooks.');
        }

        if ($connection->accessTokenExpired()) {
            $this->markConnectionExpired($connection, 'QuickBooks access token has expired.');
            throw new RuntimeException('QuickBooks access token has expired. Token refresh is required.');
        }

        return $connection;
    }

    public function statusForTenant(?Tenant $tenant = null): array
    {
        $connection = $this->connectionForTenant($tenant);

        if (!$connection) {
            return [
                'connected' => false,
                'status' => QuickBooksConnection::STATUS_DISCONNECTED,
                'realm_id' => null,
                'environment' => config('services.quickbooks.environment'),
            ];
        }

        return [
            'connected' => $connection->isConnected(),
            'status' => $connection->status,
            'realm_id' => $connection->realm_id,
            'company_name' => $connection->company_name,
            'connected_at' => $connection->connected_at?->toISOString(),
            'disconnected_at' => $connection->disconnected_at?->toISOString(),
            'access_token_expires_at' => $connection->access_token_expires_at?->toISOString(),
            'refresh_token_expires_at' => $connection->refresh_token_expires_at?->toISOString(),
            'environment' => $connection->metadata['environment'] ?? config('services.quickbooks.environment'),
            'last_error' => $connection->last_error ? $this->sanitizeCallbackError($connection->last_error) : null,
        ];
    }

    public function sanitizeCallbackError(string $message): string
    {
        $sanitized = preg_replace('/Authorization\s*:\s*Bearer\s+[^\s"]+/i', 'Authorization: [redacted]', $message);
        $sanitized = preg_replace('/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i', '[redacted token]', $sanitized ?? $message);
        $sanitized = preg_replace('/(access_token|refresh_token|client_secret|client_id|api[_-]?key|app[_-]?key|private[_-]?key|db[_-]?password|mail[_-]?password|queue[_-]?password|cache[_-]?password|password)\s*[=:]\s*[^\s,;]+/i', '$1=[redacted]', $sanitized ?? $message);
        $sanitized = preg_replace('/("(?:access_token|refresh_token|client_secret|client_id|api[_-]?key|app[_-]?key|private[_-]?key|db[_-]?password|mail[_-]?password|queue[_-]?password|cache[_-]?password|password)"\s*:\s*")[^"]+(")/i', '$1[redacted]$2', $sanitized ?? $message);

        return $sanitized ?? $message;
    }

    protected function assertConfigured(): void
    {
        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (blank(config("services.quickbooks.{$key}"))) {
                throw new RuntimeException("QuickBooks {$key} is not configured.");
            }
        }
    }

    protected function assertExpectedState(string $state): void
    {
        if (!hash_equals((string) Session::get('quickbooks_oauth_state'), $state)) {
            throw new RuntimeException('Invalid QuickBooks OAuth state.');
        }

        if (Session::get('quickbooks_oauth_tenant_id') !== $this->tenantContext->getTenantId()) {
            throw new RuntimeException('QuickBooks OAuth tenant context changed.');
        }
    }

    protected function markError(string $message): QuickBooksConnection
    {
        $sanitizedMessage = $this->sanitizeCallbackError($message);

        return QuickBooksConnection::withoutGlobalScope('tenant')->updateOrCreate(
            ['tenant_id' => $this->tenantContext->getTenantId()],
            [
                'status' => QuickBooksConnection::STATUS_ERROR,
                'last_error' => $sanitizedMessage,
                'metadata' => [
                    'environment' => config('services.quickbooks.environment'),
                ],
            ]
        );
    }

    protected function markConnectionExpired(QuickBooksConnection $connection, string $message): void
    {
        $connection->forceFill([
            'status' => QuickBooksConnection::STATUS_EXPIRED,
            'last_error' => $message,
        ])->save();
    }
}
