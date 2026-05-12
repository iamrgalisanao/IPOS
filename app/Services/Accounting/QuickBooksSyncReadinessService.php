<?php

namespace App\Services\Accounting;

use App\Models\AccountingOutbox;
use App\Models\QuickBooksConnection;
use Throwable;

class QuickBooksSyncReadinessService
{
    public function __construct(
        protected QuickBooksConnectionService $connectionService,
        protected QuickBooksPayloadBuilderService $payloadBuilder
    ) {}

    public function analyze(AccountingOutbox $record): array
    {
        $connection = $this->connectionService->connectionForTenant();

        $connectionCheck = $this->connectionCheck($connection);
        $payloadCheck = $this->payloadCheck($record);
        $ready = $connectionCheck['status'] === 'pass' && $payloadCheck['status'] === 'pass';

        if (!$ready) {
            unset($payloadCheck['payload_preview']);
        }

        return [
            'ready' => $ready,
            'connection' => $connectionCheck,
            'payload' => $payloadCheck,
            'checks' => [
                $connectionCheck,
                $payloadCheck,
            ],
        ];
    }

    protected function connectionCheck(?QuickBooksConnection $connection): array
    {
        if (!$connection) {
            return [
                'key' => 'connection',
                'title' => 'QuickBooks connection',
                'status' => 'fail',
                'message' => 'QuickBooks is not connected for this tenant.',
            ];
        }

        if ($connection->status !== QuickBooksConnection::STATUS_CONNECTED) {
            return [
                'key' => 'connection',
                'title' => 'QuickBooks connection',
                'status' => 'fail',
                'message' => match ($connection->status) {
                    QuickBooksConnection::STATUS_DISCONNECTED => 'QuickBooks is disconnected for this tenant.',
                    QuickBooksConnection::STATUS_EXPIRED => 'QuickBooks tokens have expired and must be refreshed.',
                    QuickBooksConnection::STATUS_ERROR => $this->connectionService->sanitizeCallbackError($connection->last_error ?: 'QuickBooks connection error.'),
                    default => 'QuickBooks is not ready for syncing.',
                },
                'details' => $this->connectionDetails($connection),
            ];
        }

        if ($connection->refreshTokenExpired()) {
            return [
                'key' => 'connection',
                'title' => 'QuickBooks connection',
                'status' => 'fail',
                'message' => 'QuickBooks refresh token has expired. Reconnect QuickBooks.',
                'details' => $this->connectionDetails($connection),
            ];
        }

        if ($connection->accessTokenExpired()) {
            return [
                'key' => 'connection',
                'title' => 'QuickBooks connection',
                'status' => 'fail',
                'message' => 'QuickBooks access token has expired. Token refresh is required.',
                'details' => $this->connectionDetails($connection),
            ];
        }

        return [
            'key' => 'connection',
            'title' => 'QuickBooks connection',
            'status' => 'pass',
            'message' => 'Connected to QuickBooks company ' . ($connection->company_name ?: $connection->realm_id ?: 'unknown'),
            'details' => $this->connectionDetails($connection),
        ];
    }

    protected function payloadCheck(AccountingOutbox $record): array
    {
        try {
            $payload = $this->payloadBuilder->build($record);

            return [
                'key' => 'payload',
                'title' => 'Payload build',
                'status' => 'pass',
                'message' => 'QuickBooks payload can be generated for this outbox record.',
                'details' => [
                    'provider' => $payload['provider'] ?? null,
                    'entity' => $payload['entity'] ?? null,
                    'operation' => $payload['operation'] ?? null,
                    'idempotency_key' => $payload['idempotency_key'] ?? null,
                ],
                'payload_preview' => $payload,
            ];
        } catch (Throwable $e) {
            return [
                'key' => 'payload',
                'title' => 'Payload build',
                'status' => 'fail',
                'message' => $this->sanitize($e->getMessage()),
                'details' => [
                    'event_type' => $record->event_type,
                    'source_type' => $record->source_type,
                ],
            ];
        }
    }

    protected function connectionDetails(?QuickBooksConnection $connection): array
    {
        if (!$connection) {
            return [];
        }

        return array_filter([
            'realm_id' => $connection->realm_id,
            'company_name' => $connection->company_name,
            'status' => $connection->status,
            'connected_at' => $connection->connected_at?->toISOString(),
            'access_token_expires_at' => $connection->access_token_expires_at?->toISOString(),
            'refresh_token_expires_at' => $connection->refresh_token_expires_at?->toISOString(),
            'last_error' => $connection->last_error ? $this->sanitize($connection->last_error) : null,
        ], static fn ($value) => filled($value));
    }

    protected function sanitize(string $message): string
    {
        $message = preg_replace('/Authorization\s*:\s*Bearer\s+[^\s"]+/i', 'Authorization: Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/(access_token|refresh_token|client_secret|client_id|api[_-]?key|private[_-]?key)\s*[=:]\s*[^\s,;]+/i', '$1=[redacted]', $message) ?? $message;

        return mb_substr(trim($message), 0, 900);
    }
}
