<?php

namespace App\Services\Accounting;

use App\Models\AccountingOutbox;
use App\Models\AccountingSyncAttempt;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\BranchContext;
use Illuminate\Database\Eloquent\Builder;

class AccountingOutboxQueryService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected BranchContext $branchContext
    ) {}

    /**
     * Query outbox records with filtering and strict isolation.
     */
    public function query(array $filters = [], ?User $user = null): Builder
    {
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            throw new \RuntimeException('Tenant context required for outbox query.');
        }

        $query = AccountingOutbox::where('tenant_id', $tenant->id);
        $allowedBranchIds = $this->allowedBranchIds($user);

        if ($allowedBranchIds !== null) {
            if ($allowedBranchIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('branch_id', $allowedBranchIds);
            }
        }

        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (!empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        if (!empty($filters['sync_status'])) {
            $query->where('sync_status', $filters['sync_status']);
        }

        if (!empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
    
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        return $query->latest();
    }

    /**
     * Get a single outbox record by ID with isolation.
     */
    public function find(string $id, ?User $user = null): ?AccountingOutbox
    {
        return $this->query([], $user)
            ->where('id', $id)
            ->first();
    }

    public function serializeSummary(AccountingOutbox $record): array
    {
        return [
            'id' => $record->id,
            'branch_id' => $record->branch_id,
            'event_type' => $record->event_type,
            'source_type' => $record->source_type,
            'source_id' => $record->source_id,
            'sync_status' => $record->sync_status,
            'sync_error' => $this->sanitizeValue($record->sync_error),
            'sync_error_category' => $record->sync_error_category,
            'attempt_count' => $record->attempt_count,
            'external_provider' => $record->external_provider,
            'external_reference' => $record->external_reference,
            'created_at' => optional($record->created_at)?->toIso8601String(),
            'last_attempted_at' => optional($record->last_attempted_at)?->toIso8601String(),
            'synced_at' => optional($record->synced_at)?->toIso8601String(),
        ];
    }

    public function serializeDetail(AccountingOutbox $record, bool $includeAttempts = false): array
    {
        $data = $this->serializeSummary($record) + [
            'tenant_id' => $record->tenant_id,
            'payload' => $this->sanitizeValue($record->payload),
            'available_at' => optional($record->available_at)?->toIso8601String(),
            'next_attempt_at' => optional($record->next_attempt_at)?->toIso8601String(),
            'external_id' => $record->external_id,
        ];

        if ($includeAttempts) {
            $data['attempts'] = $record->attempts->map(fn (AccountingSyncAttempt $attempt) => [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status,
                'error_category' => $attempt->error_category,
                'error_message' => $this->sanitizeValue($attempt->error_message),
                'started_at' => optional($attempt->started_at)?->toIso8601String(),
                'finished_at' => optional($attempt->finished_at)?->toIso8601String(),
                'duration_ms' => $attempt->duration_ms,
            ])->values()->all();
        }

        return $data;
    }

    protected function allowedBranchIds(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        if ($user->hasPermission('view_multi_branch_dashboard')) {
            return null;
        }

        return $user->branches()->pluck('branches.id')->map(fn ($id) => (string) $id)->all();
    }

    protected function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/access_token|refresh_token|client_secret|api[_-]?key|private[_-]?key|authorization/i', $key)) {
                    continue;
                }

                $sanitized[$key] = $this->sanitizeValue($item);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            $patterns = [
                '/(access_token|refresh_token|client_secret|api[_-]?key|private[_-]?key)\s*=\s*[^\s,;]+/i' => '$1=[redacted]',
                '/("(?:access_token|refresh_token|client_secret|api[_-]?key|private[_-]?key|authorization)"\s*:\s*")[^"]+(")/i' => '$1[redacted]$2',
                '/(Authorization\s*:\s*Bearer\s+)[^\s"]+/i' => '$1[redacted]',
            ];

            foreach ($patterns as $pattern => $replacement) {
                $value = preg_replace($pattern, $replacement, $value) ?? $value;
            }

            return $value;
        }

        return $value;
    }
}
