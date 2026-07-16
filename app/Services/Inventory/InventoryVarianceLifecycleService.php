<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\InventoryVarianceCorrectionLink;
use App\Models\InventoryVarianceLog;
use App\Models\InventoryVarianceStatusEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryVarianceLifecycleService
{
    protected array $allowedTransitions = [
        InventoryVarianceLog::STATUS_OPEN => [
            InventoryVarianceLog::STATUS_ACKNOWLEDGED,
            InventoryVarianceLog::STATUS_ACTION_PLANNED,
            InventoryVarianceLog::STATUS_LINKED_TO_CORRECTION,
            InventoryVarianceLog::STATUS_VOIDED,
            InventoryVarianceLog::STATUS_DISMISSED,
        ],
        InventoryVarianceLog::STATUS_ACKNOWLEDGED => [
            InventoryVarianceLog::STATUS_ACTION_PLANNED,
            InventoryVarianceLog::STATUS_LINKED_TO_CORRECTION,
            InventoryVarianceLog::STATUS_RESOLVED,
            InventoryVarianceLog::STATUS_VOIDED,
            InventoryVarianceLog::STATUS_DISMISSED,
        ],
        InventoryVarianceLog::STATUS_ACTION_PLANNED => [
            InventoryVarianceLog::STATUS_LINKED_TO_CORRECTION,
            InventoryVarianceLog::STATUS_RESOLVED,
            InventoryVarianceLog::STATUS_VOIDED,
            InventoryVarianceLog::STATUS_DISMISSED,
        ],
        InventoryVarianceLog::STATUS_LINKED_TO_CORRECTION => [
            InventoryVarianceLog::STATUS_RESOLVED,
            InventoryVarianceLog::STATUS_VOIDED,
        ],
    ];

    public function __construct(protected AuditLogger $auditLogger) {}

    public function acknowledge(InventoryVarianceLog $variance, User $actor, array $data = []): InventoryVarianceStatusEvent
    {
        return $this->transition($variance, InventoryVarianceLog::STATUS_ACKNOWLEDGED, 'acknowledged', $actor, $data);
    }

    public function planAction(InventoryVarianceLog $variance, User $actor, array $data = []): InventoryVarianceStatusEvent
    {
        return $this->transition($variance, InventoryVarianceLog::STATUS_ACTION_PLANNED, 'action_planned', $actor, $data);
    }

    public function resolve(InventoryVarianceLog $variance, User $actor, array $data = []): InventoryVarianceStatusEvent
    {
        return $this->transition($variance, InventoryVarianceLog::STATUS_RESOLVED, 'resolved', $actor, $data);
    }

    public function dismiss(InventoryVarianceLog $variance, User $actor, array $data = []): InventoryVarianceStatusEvent
    {
        return $this->transition($variance, InventoryVarianceLog::STATUS_DISMISSED, 'dismissed', $actor, $data);
    }

    public function void(InventoryVarianceLog $variance, User $actor, InventoryMovement $movement, array $data = []): InventoryVarianceStatusEvent
    {
        $this->linkCorrection($variance, $movement, $actor, array_merge($data, [
            'relationship_type' => 'reverses_source',
            'correction_type' => 'void_reversal',
        ]));

        return $this->transition($variance, InventoryVarianceLog::STATUS_VOIDED, 'voided', $actor, $data, $movement);
    }

    public function linkCorrection(InventoryVarianceLog $variance, InventoryMovement $movement, User $actor, array $data = []): InventoryVarianceCorrectionLink
    {
        return DB::transaction(function () use ($variance, $movement, $actor, $data) {
            $variance = InventoryVarianceLog::whereKey($variance->id)->lockForUpdate()->firstOrFail();
            $this->assertSameScope($variance, $movement);

            $relationshipType = $data['relationship_type'] ?? 'addresses';
            $correctionType = $data['correction_type'] ?? $movement->movement_type;

            $existing = InventoryVarianceCorrectionLink::query()
                ->where('inventory_variance_log_id', $variance->id)
                ->where('inventory_movement_id', $movement->id)
                ->where('relationship_type', $relationshipType)
                ->first();

            if ($existing) {
                return $existing;
            }

            $link = InventoryVarianceCorrectionLink::create([
                'tenant_id' => $variance->tenant_id,
                'branch_id' => $variance->branch_id,
                'inventory_variance_log_id' => $variance->id,
                'inventory_movement_id' => $movement->id,
                'correction_type' => $correctionType,
                'linked_quantity' => $data['linked_quantity'] ?? abs((float) $movement->quantity_change),
                'relationship_type' => $relationshipType,
                'reason_code' => $data['reason_code'] ?? null,
                'actor_id' => $actor->id,
                'link_snapshot' => [
                    'movement_uuid' => $movement->movement_uuid,
                    'movement_sequence' => $movement->movement_sequence,
                    'movement_type' => $movement->movement_type,
                    'source_reference' => $movement->source_reference,
                ],
            ]);

            if ($variance->current_status === InventoryVarianceLog::STATUS_LINKED_TO_CORRECTION) {
                $this->recordSameStatusLinkEvent($variance, $actor, $data, $movement);
            } else {
                $this->transition($variance, InventoryVarianceLog::STATUS_LINKED_TO_CORRECTION, 'linked_to_correction', $actor, $data, $movement);
            }

            return $link;
        });
    }

    protected function recordSameStatusLinkEvent(
        InventoryVarianceLog $variance,
        User $actor,
        array $data,
        InventoryMovement $movement
    ): InventoryVarianceStatusEvent {
        return InventoryVarianceStatusEvent::create([
            'tenant_id' => $variance->tenant_id,
            'branch_id' => $variance->branch_id,
            'inventory_variance_log_id' => $variance->id,
            'from_status' => InventoryVarianceLog::STATUS_LINKED_TO_CORRECTION,
            'to_status' => InventoryVarianceLog::STATUS_LINKED_TO_CORRECTION,
            'event_type' => 'linked_to_correction',
            'reason_code' => $data['reason_code'] ?? null,
            'notes' => $data['notes'] ?? null,
            'request_uuid' => $data['request_uuid'] ?? null,
            'request_fingerprint' => isset($data['request_uuid'])
                ? $this->fingerprint(InventoryVarianceLog::STATUS_LINKED_TO_CORRECTION, 'linked_to_correction', $data, $movement)
                : null,
            'actor_id' => $actor->id,
            'event_snapshot' => [
                'movement_id' => $movement->id,
                'movement_uuid' => $movement->movement_uuid,
                'relationship_type' => $data['relationship_type'] ?? null,
            ],
        ]);
    }

    protected function transition(
        InventoryVarianceLog $variance,
        string $toStatus,
        string $eventType,
        User $actor,
        array $data = [],
        ?InventoryMovement $movement = null
    ): InventoryVarianceStatusEvent {
        return DB::transaction(function () use ($variance, $toStatus, $eventType, $actor, $data, $movement) {
            $variance = InventoryVarianceLog::whereKey($variance->id)->lockForUpdate()->firstOrFail();
            $fromStatus = $variance->current_status;
            $requestUuid = $data['request_uuid'] ?? null;
            $fingerprint = $this->fingerprint($toStatus, $eventType, $data, $movement);

            if ($requestUuid) {
                $existing = InventoryVarianceStatusEvent::query()
                    ->where('inventory_variance_log_id', $variance->id)
                    ->where('event_type', $eventType)
                    ->where('request_uuid', $requestUuid)
                    ->first();

                if ($existing) {
                    if ($existing->request_fingerprint !== $fingerprint) {
                        throw new RuntimeException('Inventory variance lifecycle replay drift detected.');
                    }

                    return $existing;
                }
            }

            $this->assertTransitionAllowed($fromStatus, $toStatus);

            $event = InventoryVarianceStatusEvent::create([
                'tenant_id' => $variance->tenant_id,
                'branch_id' => $variance->branch_id,
                'inventory_variance_log_id' => $variance->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'event_type' => $eventType,
                'reason_code' => $data['reason_code'] ?? null,
                'notes' => $data['notes'] ?? null,
                'request_uuid' => $requestUuid,
                'request_fingerprint' => $requestUuid ? $fingerprint : null,
                'actor_id' => $actor->id,
                'event_snapshot' => [
                    'movement_id' => $movement?->id,
                    'movement_uuid' => $movement?->movement_uuid,
                    'relationship_type' => $data['relationship_type'] ?? null,
                ],
            ]);

            $projection = ['current_status' => $toStatus];
            if (!$variance->first_reviewed_at && in_array($toStatus, [
                InventoryVarianceLog::STATUS_ACKNOWLEDGED,
                InventoryVarianceLog::STATUS_ACTION_PLANNED,
                InventoryVarianceLog::STATUS_LINKED_TO_CORRECTION,
                InventoryVarianceLog::STATUS_RESOLVED,
                InventoryVarianceLog::STATUS_DISMISSED,
            ], true)) {
                $projection['first_reviewed_by'] = $actor->id;
                $projection['first_reviewed_at'] = now();
            }

            if (in_array($toStatus, [
                InventoryVarianceLog::STATUS_RESOLVED,
                InventoryVarianceLog::STATUS_VOIDED,
                InventoryVarianceLog::STATUS_DISMISSED,
            ], true)) {
                $projection['resolved_at'] = now();
                $projection['terminal_status_reason'] = $data['reason_code'] ?? $toStatus;
            }

            $variance->update($projection);

            $this->auditLogger->log(
                action: "inventory_negative_exception_{$eventType}",
                auditable: $variance,
                metadata: [
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'event_id' => $event->id,
                    'movement_id' => $movement?->id,
                ],
                actor: $actor
            );

            return $event;
        });
    }

    protected function assertTransitionAllowed(string $fromStatus, string $toStatus): void
    {
        if ($fromStatus === $toStatus) {
            throw new RuntimeException('Inventory variance is already in the requested status.');
        }

        if (!in_array($toStatus, $this->allowedTransitions[$fromStatus] ?? [], true)) {
            throw new RuntimeException("Inventory variance cannot transition from {$fromStatus} to {$toStatus}.");
        }
    }

    protected function assertSameScope(InventoryVarianceLog $variance, InventoryMovement $movement): void
    {
        if ($variance->tenant_id !== $movement->tenant_id || $variance->branch_id !== $movement->branch_id) {
            throw new RuntimeException('Correction movement belongs to another tenant or branch.');
        }

        if ($variance->ingredient_product_id && $variance->ingredient_product_id !== $movement->product_id) {
            throw new RuntimeException('Correction movement does not match the exception product.');
        }
    }

    protected function fingerprint(string $toStatus, string $eventType, array $data, ?InventoryMovement $movement): string
    {
        return hash('sha256', json_encode([
            'to_status' => $toStatus,
            'event_type' => $eventType,
            'reason_code' => $data['reason_code'] ?? null,
            'notes' => $data['notes'] ?? null,
            'movement_id' => $movement?->id,
            'relationship_type' => $data['relationship_type'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }
}
