<?php

namespace App\Services\Dining;

use App\Exceptions\Dining\DiningDomainException;
use App\Models\BillSplitAllocation;
use App\Models\DiningTicket;
use App\Models\DiningTicketItem;
use App\Models\DiningTicketTable;
use App\Models\SalesMachineProfile;
use App\Models\User;
use App\Values\Dining\BillSplitAllocationPlan;
use App\Values\Dining\DiningTimelinePayload;
use App\Values\Dining\SplitDiningTicketByItemQuantityCommand;
use App\Values\Dining\SplitDiningTicketBySeatCommand;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BillSplitAllocatorService
{
    public function __construct(
        private readonly DiningTicketNumberService $numberService,
        private readonly DiningOperationAuditService $operationAuditService,
        private readonly DiningTicketRevisionService $revisionService,
        private readonly DiningTicketTimelineService $timelineService,
    ) {
    }

    public function splitBySeat(
        DiningTicket $ticket,
        SplitDiningTicketBySeatCommand $command,
        User $actor,
        SalesMachineProfile $terminal
    ): array {
        return $this->split(
            $ticket,
            BillSplitAllocation::METHOD_SEAT,
            $command->expectedTicketRevision,
            $command->clientRequestUuid,
            $command->groups,
            $actor,
            $terminal,
        );
    }

    public function splitByItemQuantity(
        DiningTicket $ticket,
        SplitDiningTicketByItemQuantityCommand $command,
        User $actor,
        SalesMachineProfile $terminal
    ): array {
        return $this->split(
            $ticket,
            BillSplitAllocation::METHOD_ITEM_QUANTITY,
            $command->expectedTicketRevision,
            $command->clientRequestUuid,
            $command->groups,
            $actor,
            $terminal,
        );
    }

    private function split(
        DiningTicket $ticket,
        string $method,
        int $expectedRevision,
        string $clientRequestUuid,
        array $groups,
        User $actor,
        SalesMachineProfile $terminal
    ): array {
        return DB::transaction(function () use ($ticket, $method, $expectedRevision, $clientRequestUuid, $groups, $actor, $terminal) {
            /** @var DiningTicket $lockedTicket */
            $lockedTicket = DiningTicket::query()
                ->with(['branch', 'primaryTableMapping.table'])
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanAccessSplit($lockedTicket, $actor, $terminal);
            $fingerprint = $this->requestFingerprint($lockedTicket, $method, $expectedRevision, $groups);

            if ($replay = $this->idempotentReplay($lockedTicket, $clientRequestUuid, $fingerprint)) {
                return $replay;
            }

            $this->revisionService->assertExpectedRevision($lockedTicket, $expectedRevision);

            if ($this->hasSplitChildren($lockedTicket)) {
                throw new DiningDomainException(
                    'DINING_TICKET_ALREADY_SPLIT',
                    'This ticket has split child bills and can no longer be changed directly.',
                    409
                );
            }

            $sourceItems = $this->lockedSourceItems($lockedTicket);
            if ($sourceItems->isEmpty()) {
                throw new DiningDomainException('DINING_SPLIT_NO_ACTIVE_ITEMS', 'A ticket must have active items before it can be split.', 409);
            }

            $this->assertNoStatutoryDiscount($lockedTicket, $sourceItems);

            $plans = $method === BillSplitAllocation::METHOD_SEAT
                ? $this->buildSeatPlans($sourceItems, $groups)
                : $this->buildItemQuantityPlans($sourceItems, $groups);

            $beforeSnapshot = $this->revisionService->snapshot($lockedTicket);
            $beforeAudit = $this->operationAuditService->payloadForTicket($lockedTicket);
            $children = $this->persistChildrenAndAllocations(
                $lockedTicket,
                $plans,
                $method,
                $clientRequestUuid,
                $fingerprint,
                $groups,
                $actor,
                $terminal,
            );

            $lockedTicket->status = DiningTicket::STATUS_SETTLING;
            $lockedTicket->ticket_revision++;
            $lockedTicket->save();
            $lockedTicket->load('primaryTableMapping.table');

            $summary = $this->summary($lockedTicket, $children, $plans, $method, $clientRequestUuid);
            $afterSnapshot = $this->revisionService->snapshot($lockedTicket, $summary);
            $afterAudit = $this->operationAuditService->payloadForTicket($lockedTicket, $summary);

            $this->operationAuditService->recordOperation(
                DiningOperationAuditService::BILL_SPLIT_CREATED,
                $lockedTicket,
                $beforeAudit,
                $afterAudit,
                $actor,
                $terminal,
                metadata: $summary,
            );
            $this->revisionService->recordMutationVersion(
                $lockedTicket,
                $actor,
                $terminal,
                'bill_split_created',
                $beforeSnapshot,
                $afterSnapshot,
                metadata: $summary,
            );
            $this->timelineService->recordEvent(
                $lockedTicket,
                DiningTicketTimelineService::BILL_SPLIT_CREATED,
                $actor,
                $terminal,
                DiningTimelinePayload::fromTicket($lockedTicket, $summary),
            );

            return $this->result($lockedTicket->fresh(['childTickets', 'splitAllocations']), $children, $plans, $method, false);
        });
    }

    private function assertCanAccessSplit(
        DiningTicket $ticket,
        User $actor,
        SalesMachineProfile $terminal
    ): void {
        if (!$actor->canAccessBranch($ticket->branch)) {
            abort(404);
        }

        if ($terminal->tenant_id !== $ticket->tenant_id || $terminal->branch_id !== $ticket->branch_id) {
            throw new DiningDomainException('TERMINAL_CONTEXT_INVALID', 'Invalid terminal context.', 403);
        }

        if ($ticket->parent_ticket_id !== null) {
            throw new DiningDomainException('DINING_SPLIT_CHILD_TICKET_NOT_SPLITTABLE', 'Split child bills cannot be split again.', 409);
        }

        if (!in_array($ticket->status, DiningTicket::ACTIVE_STATUSES, true)) {
            throw new DiningDomainException('DINING_TICKET_NOT_ACTIVE', 'Closed or voided dining tickets cannot be split.', 409);
        }
    }

    private function idempotentReplay(DiningTicket $ticket, string $clientRequestUuid, string $fingerprint): ?array
    {
        $allocations = BillSplitAllocation::query()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('branch_id', $ticket->branch_id)
            ->where('parent_ticket_id', $ticket->id)
            ->where('split_request_uuid', $clientRequestUuid)
            ->orderBy('allocation_sequence')
            ->get();

        if ($allocations->isEmpty()) {
            return null;
        }

        if ($allocations->first()->request_fingerprint !== $fingerprint) {
            throw new DiningDomainException(
                'DINING_SPLIT_IDEMPOTENCY_DRIFT',
                'The split request uuid was already used with different split details.',
                409
            );
        }

        $children = DiningTicket::query()
            ->with('childSplitAllocations')
            ->whereIn('id', $allocations->pluck('child_ticket_id')->unique()->values())
            ->orderBy('ticket_number')
            ->get()
            ->values();

        $ticket->setAttribute('idempotent_replay', true);

        return $this->result($ticket->fresh(), $children, $allocations, $allocations->first()->allocation_method, true);
    }

    private function lockedSourceItems(DiningTicket $ticket): Collection
    {
        return DiningTicketItem::query()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('branch_id', $ticket->branch_id)
            ->where('dining_ticket_id', $ticket->id)
            ->activeForTotals()
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function buildSeatPlans(Collection $sourceItems, array $groups): array
    {
        $seenSeats = [];
        $seatToGroup = [];

        foreach (array_values($groups) as $groupIndex => $group) {
            foreach ($group['seat_numbers'] as $seatNumber) {
                $seatKey = $seatNumber === null ? '__unassigned__' : (string) $seatNumber;
                if (isset($seenSeats[$seatKey])) {
                    throw new DiningDomainException('DINING_SPLIT_ALLOCATION_MISMATCH', 'Seat groups must not overlap.', 409);
                }

                $seenSeats[$seatKey] = true;
                $seatToGroup[$seatKey] = $groupIndex;
            }
        }

        $entriesByItem = [];
        foreach ($sourceItems as $item) {
            $seatKey = $item->seat_number === null ? '__unassigned__' : (string) $item->seat_number;
            if (!array_key_exists($seatKey, $seatToGroup)) {
                throw new DiningDomainException('DINING_SPLIT_ALLOCATION_MISMATCH', 'Every active item seat must be assigned to a split group.', 409);
            }

            $entriesByItem[$item->id][] = [
                'group_index' => $seatToGroup[$seatKey],
                'quantity_units' => $this->quantityUnits((string) $item->quantity),
            ];
        }

        return $this->plansFromEntries($sourceItems, $entriesByItem);
    }

    private function buildItemQuantityPlans(Collection $sourceItems, array $groups): array
    {
        $sourceById = $sourceItems->keyBy('id');
        $entriesByItem = [];

        foreach (array_values($groups) as $groupIndex => $group) {
            foreach ($group['items'] as $entry) {
                $itemId = $entry['dining_ticket_item_id'];
                if (!$sourceById->has($itemId)) {
                    throw new DiningDomainException('DINING_SPLIT_ALLOCATION_MISMATCH', 'Split items must belong to active rows on the parent ticket.', 409);
                }

                $entriesByItem[$itemId][] = [
                    'group_index' => $groupIndex,
                    'quantity_units' => $this->quantityUnits((string) $entry['quantity']),
                ];
            }
        }

        foreach ($sourceItems as $item) {
            if (!isset($entriesByItem[$item->id])) {
                throw new DiningDomainException('DINING_SPLIT_ALLOCATION_MISMATCH', 'Every active parent item must be fully allocated.', 409);
            }
        }

        return $this->plansFromEntries($sourceItems, $entriesByItem);
    }

    private function plansFromEntries(Collection $sourceItems, array $entriesByItem): array
    {
        $plans = [];
        $sequence = 1;
        $entriesByGroup = [];

        foreach ($sourceItems as $item) {
            $entries = $entriesByItem[$item->id] ?? [];
            usort($entries, fn (array $left, array $right) => $left['group_index'] <=> $right['group_index']);

            $sourceQuantityUnits = $this->quantityUnits((string) $item->quantity);
            $allocatedUnits = array_sum(array_column($entries, 'quantity_units'));
            if ($allocatedUnits !== $sourceQuantityUnits) {
                throw new DiningDomainException('DINING_SPLIT_ALLOCATION_MISMATCH', 'Allocated quantities must exactly match parent item quantities.', 409);
            }

            $amounts = $this->allocateCentavos($item->line_total_centavos, array_column($entries, 'quantity_units'), $sourceQuantityUnits);
            $promotionDiscount = $this->promotionDiscountCentavos($item->promotion_allocation_snapshot);
            $promotionAmounts = $this->allocateCentavos($promotionDiscount, array_column($entries, 'quantity_units'), $sourceQuantityUnits);

            foreach ($entries as $index => $entry) {
                $baseAmount = intdiv($item->line_total_centavos * $entry['quantity_units'], $sourceQuantityUnits);
                $promotionSnapshot = $this->allocatedPromotionSnapshot($item->promotion_allocation_snapshot, $promotionAmounts[$index]);
                $entriesByGroup[$entry['group_index']][] = new BillSplitAllocationPlan(
                    $item,
                    $entry['group_index'],
                    $this->quantityString($entry['quantity_units']),
                    $entry['quantity_units'],
                    $amounts[$index],
                    $promotionAmounts[$index],
                    $amounts[$index] - $baseAmount,
                    $promotionSnapshot,
                    0,
                );
            }
        }

        ksort($entriesByGroup);
        foreach ($entriesByGroup as $groupPlans) {
            usort($groupPlans, fn (BillSplitAllocationPlan $left, BillSplitAllocationPlan $right) => strcmp($left->sourceItem->id, $right->sourceItem->id));
            foreach ($groupPlans as $plan) {
                $plans[] = new BillSplitAllocationPlan(
                    $plan->sourceItem,
                    $plan->childGroupIndex,
                    $plan->quantity,
                    $plan->quantityUnits,
                    $plan->allocatedAmountCentavos,
                    $plan->promotionDiscountCentavos,
                    $plan->roundingAdjustmentCentavos,
                    $plan->promotionAllocationSnapshot,
                    $sequence++,
                );
            }
        }

        $representedGroups = [];
        foreach ($plans as $plan) {
            $representedGroups[$plan->childGroupIndex] = true;
        }

        if (count($representedGroups) < 2) {
            throw new DiningDomainException('DINING_SPLIT_ALLOCATION_MISMATCH', 'A split must create at least two child bills.', 409);
        }

        return $plans;
    }

    private function persistChildrenAndAllocations(
        DiningTicket $parent,
        array $plans,
        string $method,
        string $clientRequestUuid,
        string $fingerprint,
        array $groups,
        User $actor,
        SalesMachineProfile $terminal
    ): Collection {
        $plansByGroup = collect($plans)->groupBy(fn (BillSplitAllocationPlan $plan) => $plan->childGroupIndex);
        $children = new Collection();
        $primaryTable = $parent->primaryTableMapping?->table;

        foreach ($plansByGroup->sortKeys() as $groupIndex => $groupPlans) {
            $child = DiningTicket::create([
                'branch_id' => $parent->branch_id,
                'ticket_number' => $this->numberService->nextForBranch($parent->tenant_id, $parent->branch_id),
                'status' => DiningTicket::STATUS_OPEN,
                'guest_count' => 1,
                'subtotal_centavos' => 0,
                'discount_centavos' => 0,
                'service_charge_centavos' => 0,
                'tax_centavos' => 0,
                'grand_total_centavos' => 0,
                'opened_by' => $actor->id,
                'opened_at' => now(),
                'parent_ticket_id' => $parent->id,
                'terminal_id' => $terminal->id,
                'ticket_revision' => 1,
                'reservation_id' => $parent->reservation_id,
                'pricing_engine_version' => $parent->pricing_engine_version,
                'tax_engine_version' => $parent->tax_engine_version,
                'discount_engine_version' => $parent->discount_engine_version,
                'notes' => null,
            ]);

            if ($primaryTable) {
                DiningTicketTable::create([
                    'branch_id' => $parent->branch_id,
                    'dining_ticket_id' => $child->id,
                    'dining_table_id' => $primaryTable->id,
                    'role' => DiningTicketTable::ROLE_PRIMARY,
                    'attached_at' => now(),
                ]);
            }

            $subtotal = 0;
            foreach ($groupPlans as $plan) {
                $childItem = DiningTicketItem::create([
                    'branch_id' => $parent->branch_id,
                    'dining_ticket_id' => $child->id,
                    'product_id' => $plan->sourceItem->product_id,
                    'seat_number' => $plan->sourceItem->seat_number,
                    'quantity' => $plan->quantity,
                    'unit_price_centavos' => $plan->sourceItem->unit_price_centavos,
                    'line_total_centavos' => $plan->allocatedAmountCentavos,
                    'status' => DiningTicketItem::STATUS_OPEN,
                    'source_item_id' => $plan->sourceItem->id,
                    'course_no' => $plan->sourceItem->course_no,
                    'fire_group' => $plan->sourceItem->fire_group,
                    'hold_until' => $plan->sourceItem->hold_until,
                    'preparation_station_id' => $plan->sourceItem->preparation_station_id,
                    'promotion_allocation_snapshot' => $plan->promotionAllocationSnapshot,
                ]);

                BillSplitAllocation::create([
                    'branch_id' => $parent->branch_id,
                    'split_request_uuid' => $clientRequestUuid,
                    'request_fingerprint' => $fingerprint,
                    'parent_ticket_id' => $parent->id,
                    'child_ticket_id' => $child->id,
                    'child_ticket_item_id' => $childItem->id,
                    'source_ticket_item_id' => $plan->sourceItem->id,
                    'allocation_method' => $method,
                    'allocation_sequence' => $plan->allocationSequence,
                    'allocated_quantity' => $plan->quantity,
                    'allocated_amount_centavos' => $plan->allocatedAmountCentavos,
                    'promotion_discount_centavos' => $plan->promotionDiscountCentavos,
                    'rounding_adjustment_centavos' => $plan->roundingAdjustmentCentavos,
                    'promotion_allocation_snapshot' => $plan->promotionAllocationSnapshot,
                    'created_by' => $actor->id,
                    'created_at' => now(),
                ]);

                $subtotal += $plan->allocatedAmountCentavos;
            }

            $child->subtotal_centavos = $subtotal;
            $child->discount_centavos = 0;
            $child->service_charge_centavos = 0;
            $child->tax_centavos = 0;
            $child->grand_total_centavos = $subtotal;
            $child->save();
            $child->load('primaryTableMapping.table');

            $metadata = [
                'parent_ticket_id' => $parent->id,
                'parent_ticket_number' => $parent->ticket_number,
                'allocation_method' => $method,
                'split_group_index' => $groupIndex,
                'split_group_label' => $groups[$groupIndex]['label'] ?? null,
                'split_request_uuid' => $clientRequestUuid,
            ];
            $this->revisionService->recordInitialVersion(
                $child,
                $actor,
                $terminal,
                'child_bill_created',
                $this->revisionService->snapshot($child, $metadata),
                $metadata,
            );
            $this->timelineService->recordEvent(
                $child,
                DiningTicketTimelineService::CHILD_BILL_CREATED,
                $actor,
                $terminal,
                DiningTimelinePayload::fromTicket($child, $metadata),
            );

            $children->push($child);
        }

        return $children;
    }

    private function result(DiningTicket $parent, Collection $children, iterable $plansOrAllocations, string $method, bool $idempotentReplay): array
    {
        $allocatedAmount = 0;
        $promotionDiscount = 0;
        $roundingAdjustment = 0;
        $allocationCount = 0;

        foreach ($plansOrAllocations as $entry) {
            $allocatedAmount += (int) ($entry->allocatedAmountCentavos ?? $entry->allocated_amount_centavos);
            $promotionDiscount += (int) ($entry->promotionDiscountCentavos ?? $entry->promotion_discount_centavos);
            $roundingAdjustment += (int) ($entry->roundingAdjustmentCentavos ?? $entry->rounding_adjustment_centavos);
            $allocationCount++;
        }

        return [
            'idempotent_replay' => $idempotentReplay,
            'parent_ticket' => [
                'id' => $parent->id,
                'ticket_number' => $parent->ticket_number,
                'status' => $parent->status,
                'ticket_revision' => $parent->ticket_revision,
                'subtotal_centavos' => $parent->subtotal_centavos,
                'discount_centavos' => $parent->discount_centavos,
                'service_charge_centavos' => $parent->service_charge_centavos,
                'tax_centavos' => $parent->tax_centavos,
                'grand_total_centavos' => $parent->grand_total_centavos,
            ],
            'children' => $children
                ->sortBy('ticket_number')
                ->values()
                ->map(fn (DiningTicket $child) => [
                    'id' => $child->id,
                    'ticket_number' => $child->ticket_number,
                    'status' => $child->status,
                    'ticket_revision' => $child->ticket_revision,
                    'subtotal_centavos' => $child->subtotal_centavos,
                    'discount_centavos' => $child->discount_centavos,
                    'service_charge_centavos' => $child->service_charge_centavos,
                    'tax_centavos' => $child->tax_centavos,
                    'grand_total_centavos' => $child->grand_total_centavos,
                ])
                ->all(),
            'allocation_summary' => [
                'allocation_method' => $method,
                'allocation_count' => $allocationCount,
                'allocated_amount_centavos' => $allocatedAmount,
                'promotion_discount_centavos' => $promotionDiscount,
                'rounding_adjustment_centavos' => $roundingAdjustment,
            ],
        ];
    }

    private function summary(DiningTicket $parent, Collection $children, array $plans, string $method, string $clientRequestUuid): array
    {
        return [
            'parent_ticket_id' => $parent->id,
            'parent_ticket_number' => $parent->ticket_number,
            'allocation_method' => $method,
            'child_ticket_count' => $children->count(),
            'child_ticket_ids' => $children->pluck('id')->values()->all(),
            'child_ticket_numbers' => $children->pluck('ticket_number')->values()->all(),
            'source_item_count' => collect($plans)->pluck('sourceItem.id')->unique()->count(),
            'allocation_count' => count($plans),
            'parent_grand_total_centavos' => $parent->grand_total_centavos,
            'allocated_amount_centavos' => array_sum(array_map(fn (BillSplitAllocationPlan $plan) => $plan->allocatedAmountCentavos, $plans)),
            'promotion_discount_centavos' => array_sum(array_map(fn (BillSplitAllocationPlan $plan) => $plan->promotionDiscountCentavos, $plans)),
            'rounding_adjustment_centavos' => array_sum(array_map(fn (BillSplitAllocationPlan $plan) => $plan->roundingAdjustmentCentavos, $plans)),
            'client_request_uuid' => $clientRequestUuid,
        ];
    }

    private function requestFingerprint(DiningTicket $ticket, string $method, int $expectedRevision, array $groups): string
    {
        $materialGroups = array_map(function (array $group) use ($method) {
            if ($method === BillSplitAllocation::METHOD_SEAT) {
                return [
                    'seat_numbers' => array_values($group['seat_numbers']),
                ];
            }

            return [
                'items' => array_map(fn (array $item) => [
                    'dining_ticket_item_id' => $item['dining_ticket_item_id'],
                    'quantity' => $this->quantityString($this->quantityUnits((string) $item['quantity'])),
                ], $group['items']),
            ];
        }, array_values($groups));

        return hash('sha256', json_encode([
            'tenant_id' => $ticket->tenant_id,
            'branch_id' => $ticket->branch_id,
            'parent_ticket_id' => $ticket->id,
            'allocation_method' => $method,
            'expected_ticket_revision' => $expectedRevision,
            'groups' => $materialGroups,
        ], JSON_THROW_ON_ERROR));
    }

    private function allocateCentavos(int $totalCentavos, array $quantityUnits, int $sourceQuantityUnits): array
    {
        $amounts = [];
        $remaining = $totalCentavos;
        $lastIndex = count($quantityUnits) - 1;

        foreach ($quantityUnits as $index => $units) {
            if ($index === $lastIndex) {
                $amounts[] = $remaining;
                break;
            }

            $amount = intdiv($totalCentavos * $units, $sourceQuantityUnits);
            $amounts[] = $amount;
            $remaining -= $amount;
        }

        return $amounts;
    }

    private function allocatedPromotionSnapshot(?array $snapshot, int $promotionDiscountCentavos): array
    {
        $allocated = $snapshot ?? [];
        $allocated['promotion_snapshot_version'] = $allocated['promotion_snapshot_version'] ?? null;
        $allocated['promotion_discount_centavos'] = $promotionDiscountCentavos;

        return $allocated;
    }

    private function promotionDiscountCentavos(?array $snapshot): int
    {
        if (!$snapshot) {
            return 0;
        }

        foreach (['promotion_discount_centavos', 'discount_centavos', 'allocated_discount_centavos', 'amount_centavos'] as $key) {
            if (isset($snapshot[$key]) && is_numeric($snapshot[$key])) {
                return (int) $snapshot[$key];
            }
        }

        return 0;
    }

    private function assertNoStatutoryDiscount(DiningTicket $ticket, Collection $sourceItems): void
    {
        if ($ticket->discount_centavos > 0 || str_contains(strtolower((string) $ticket->discount_engine_version), 'statutory')) {
            throw new DiningDomainException(
                'DINING_TICKET_STATUTORY_DISCOUNT_SPLIT_BLOCKED',
                'Tickets with pre-applied statutory discounts cannot be split.',
                409
            );
        }

        foreach ($sourceItems as $item) {
            if ($this->containsStatutoryMarker($item->promotion_allocation_snapshot)) {
                throw new DiningDomainException(
                    'DINING_TICKET_STATUTORY_DISCOUNT_SPLIT_BLOCKED',
                    'Tickets with pre-applied statutory discounts cannot be split.',
                    409
                );
            }
        }
    }

    private function containsStatutoryMarker(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains(strtolower($value), 'statutory');
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $nested) {
            if (is_string($key) && str_contains(strtolower($key), 'statutory')) {
                return true;
            }

            if ($this->containsStatutoryMarker($nested)) {
                return true;
            }
        }

        return false;
    }

    private function hasSplitChildren(DiningTicket $ticket): bool
    {
        return DiningTicket::query()
            ->where('parent_ticket_id', $ticket->id)
            ->exists()
            || BillSplitAllocation::query()
                ->where('parent_ticket_id', $ticket->id)
                ->exists();
    }

    private function quantityUnits(string $quantity): int
    {
        $quantity = trim($quantity);
        [$whole, $decimal] = array_pad(explode('.', $quantity, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $decimal = str_pad(substr($decimal, 0, 3), 3, '0');

        return ((int) $whole * 1000) + (int) $decimal;
    }

    private function quantityString(int $quantityUnits): string
    {
        return sprintf('%d.%03d', intdiv($quantityUnits, 1000), $quantityUnits % 1000);
    }
}
