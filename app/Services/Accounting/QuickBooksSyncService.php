<?php

namespace App\Services\Accounting;

use App\Models\AccountingOutbox;
use App\Services\Observability\RequestCorrelation;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class QuickBooksSyncService
{
    public function __construct(
        protected QuickBooksConnectionService $connectionService,
        protected QuickBooksPayloadBuilderService $payloadBuilder
    ) {}

    public function sync(AccountingOutbox $record): array
    {
        $command = $this->payloadBuilder->build($record);

        try {
            $connection = $this->connectionService->assertConnectedForTenant();
        } catch (RuntimeException $exception) {
            Log::warning('accounting.quickbooks.connection.failed', $this->failureContext($record, $command, [
                'error_category' => 'auth',
                'error_detail' => $this->connectionService->sanitizeCallbackError($exception->getMessage()),
            ]));

            throw $exception;
        }

        try {
            return match ($command['operation']) {
                'create' => $this->createEntity($connection->realm_id, $command),
                'void' => $this->voidEntity($connection->realm_id, $command),
                default => throw new RuntimeException("Unsupported QuickBooks operation: {$command['operation']}"),
            };
        } catch (RuntimeException $exception) {
            if ($this->shouldLogSyncFailure($exception->getMessage())) {
                Log::warning('accounting.quickbooks.sync.failed', $this->failureContext($record, $command, [
                    'error_category' => $this->classifyProviderFailure($exception->getMessage()),
                    'error_detail' => $this->sanitizeErrorDetail($exception->getMessage()),
                ]));
            }

            throw $exception;
        }
    }

    protected function createEntity(string $realmId, array $command): array
    {
        $response = $this->request($realmId)
            ->post($this->entityPath($realmId, $command['entity']), $command['payload']);

        $this->ensureSuccessful($response);

        $data = $this->decodeResponse($response->json(), $command['entity']);

        return $this->externalReference($command['entity'], $data);
    }

    protected function voidEntity(string $realmId, array $command): array
    {
        $existing = $this->findByDocumentNumber($realmId, $command['entity'], (string) Arr::get($command, 'payload.DocNumber'));

        $voidPayload = [
            'Id' => Arr::get($existing, 'Id'),
            'SyncToken' => Arr::get($existing, 'SyncToken'),
            'sparse' => true,
        ];

        $response = $this->request($realmId)
            ->post($this->entityPath($realmId, $command['entity'], ['operation' => 'void']), $voidPayload);

        $this->ensureSuccessful($response);

        $data = $this->decodeResponse($response->json(), $command['entity']);

        return $this->externalReference($command['entity'], $data);
    }

    protected function findByDocumentNumber(string $realmId, string $entity, string $documentNumber): array
    {
        if (blank($documentNumber)) {
            throw new RuntimeException('QuickBooks document number is required.');
        }

        $query = sprintf("select * from %s where DocNumber = '%s'", $entity, str_replace("'", "\\'", $documentNumber));

        $response = $this->request($realmId)
            ->get($this->queryPath($realmId), ['query' => $query]);

        $this->ensureSuccessful($response);

        $body = $response->json('QueryResponse');
        $records = $body[$entity] ?? [];
        $record = $records[0] ?? null;

        if (!$record) {
            throw new RuntimeException("QuickBooks {$entity} not found for DocNumber {$documentNumber}.");
        }

        return $record;
    }

    protected function request(string $realmId)
    {
        $connection = $this->connectionService->assertConnectedForTenant();

        return Http::acceptJson()
            ->withToken($connection->access_token)
            ->baseUrl($this->apiBaseUrl())
            ->withQueryParameters([
                'minorversion' => 75,
            ]);
    }

    protected function entityPath(string $realmId, string $entity, array $query = []): string
    {
        $path = sprintf('/v3/company/%s/%s', $realmId, strtolower($entity));

        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query);
    }

    protected function queryPath(string $realmId): string
    {
        return sprintf('/v3/company/%s/query', $realmId);
    }

    protected function decodeResponse(array $payload, string $entity): array
    {
        $record = $payload[$entity] ?? null;

        if (!$record || blank($record['Id'] ?? null)) {
            throw new RuntimeException("QuickBooks {$entity} response did not include an external id.");
        }

        return $record;
    }

    protected function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $detail = $response->json('Fault.Error.0.Detail')
            ?? $response->json('Fault.Error.0.Message')
            ?? $response->json('message')
            ?? $response->body();

        $label = match ($status) {
            400 => 'validation error',
            401 => 'unauthorized',
            403 => 'forbidden',
            429 => 'rate limit',
            500, 502, 503, 504 => 'provider error',
            default => 'request failed',
        };

        $safeDetail = $this->sanitizeErrorDetail($detail);

        throw new RuntimeException("QuickBooks {$status} {$label}: {$safeDetail}");
    }

    protected function externalReference(string $entity, array $record): array
    {
        return [
            'external_provider' => 'quickbooks',
            'external_id' => (string) $record['Id'],
            'external_reference' => $entity . ':' . $record['Id'],
        ];
    }

    protected function apiBaseUrl(): string
    {
        $configured = config('services.quickbooks.api_base_url');

        if (filled($configured)) {
            return rtrim($configured, '/');
        }

        return config('services.quickbooks.environment') === 'production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com';
    }

    protected function sanitizeErrorDetail(mixed $detail): string
    {
        $text = trim((string) $detail);

        if ($text === '') {
            return 'No provider error detail returned.';
        }

        $text = preg_replace('/Authorization\s*:\s*Bearer\s+[^\s"]+/i', 'Authorization: [redacted]', $text) ?? $text;
        $text = preg_replace('/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i', '[redacted token]', $text) ?? $text;
        $text = preg_replace('/(access_token|refresh_token|client_secret|client_id|api[_-]?key|app[_-]?key|private[_-]?key|db[_-]?password|mail[_-]?password|queue[_-]?password|cache[_-]?password|password)\s*[=:]\s*[^\s,;]+/i', '$1=[redacted]', $text) ?? $text;
        $text = preg_replace('/("(?:access_token|refresh_token|client_secret|client_id|api[_-]?key|app[_-]?key|private[_-]?key|db[_-]?password|mail[_-]?password|queue[_-]?password|cache[_-]?password|password)"\s*:\s*")[^"]+(")/i', '$1[redacted]$2', $text) ?? $text;
        $text = preg_replace('/provider payload[^\n\r]*/i', '[redacted provider detail]', $text) ?? $text;

        return mb_substr($text, 0, 900);
    }

    protected function failureContext(AccountingOutbox $record, array $command, array $extra = []): array
    {
        return array_merge([
            'correlation_id' => app(RequestCorrelation::class)->current(),
            'outbox_id' => $record->id,
            'tenant_id' => $record->tenant_id,
            'branch_id' => $record->branch_id,
            'provider' => 'quickbooks',
            'operation' => $command['operation'] ?? null,
            'entity' => $command['entity'] ?? null,
        ], $extra);
    }

    protected function shouldLogSyncFailure(string $message): bool
    {
        return str_contains($message, 'QuickBooks ');
    }

    protected function classifyProviderFailure(string $message): string
    {
        $normalized = strtolower($message);

        return match (true) {
            str_contains($normalized, '401'),
            str_contains($normalized, '403'),
            str_contains($normalized, 'unauthorized'),
            str_contains($normalized, 'forbidden') => 'auth',

            str_contains($normalized, '429'),
            str_contains($normalized, 'rate limit') => 'rate_limit',

            str_contains($normalized, '400'),
            str_contains($normalized, 'validation error') => 'validation',

            str_contains($normalized, '500'),
            str_contains($normalized, '502'),
            str_contains($normalized, '503'),
            str_contains($normalized, '504'),
            str_contains($normalized, 'provider error') => 'provider',

            default => 'system',
        };
    }
}