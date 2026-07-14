<?php

namespace App\Services\Dining;

use App\Exceptions\Dining\DiningDomainException;
use App\Models\DiningTicket;
use App\Models\DiningTicketItem;
use App\Models\Product;
use App\Models\SalesMachineProfile;
use App\Models\User;
use App\Values\Dining\AddDiningTicketItemCommand;
use App\Values\Dining\AssignDiningTicketItemSeatCommand;
use App\Values\Dining\ChangeDiningTicketItemQuantityCommand;
use App\Values\Dining\DiningTimelinePayload;
use App\Values\Dining\MoveDiningTicketItemSeatCommand;
use App\Values\Dining\VoidDiningTicketItemCommand;
use Illuminate\Support\Facades\DB;

class DiningTicketItemService
{
    public function __construct(
        private readonly DiningOperationAuditService $operationAuditService,
        private readonly DiningTicketRevisionService $revisionService,
        private readonly DiningTicketTimelineService $timelineService,
    ) {
    }

    public function addItem(
        DiningTicket $ticket,
        AddDiningTicketItemCommand $command,
        User $actor,
        SalesMachineProfile $terminal
    ): DiningTicketItem {
        return DB::transaction(function () use ($ticket, $command, $actor, $terminal) {
            $lockedTicket = $this->lockedTicket($ticket);
            $this->assertCanMutateTicket($lockedTicket, $actor, $terminal, $command->expectedTicketRevision);

            $product = Product::query()
                ->with('taxCategory')
                ->where('tenant_id', $lockedTicket->tenant_id)
                ->whereKey($command->productId)
                ->where('status', 'active')
                ->where('is_sellable', true)
                ->firstOrFail();

            $quantityUnits = $this->quantityUnits($command->quantity);
            $product->getSaleSnapshotBase();
            $unitPriceCentavos = $this->moneyCentavos((string) $product->selling_price);
            $lineTotalCentavos = $this->lineTotalCentavos($unitPriceCentavos, $quantityUnits);

            $beforeSnapshot = $this->revisionService->snapshot($lockedTicket);

            $item = DiningTicketItem::create([
                'tenant_id' => $lockedTicket->tenant_id,
                'branch_id' => $lockedTicket->branch_id,
                'dining_ticket_id' => $lockedTicket->id,
                'product_id' => $product->id,
                'seat_number' => $command->seatNumber,
                'quantity' => $this->quantityString($quantityUnits),
                'unit_price_centavos' => $unitPriceCentavos,
                'line_total_centavos' => $lineTotalCentavos,
                'status' => DiningTicketItem::STATUS_OPEN,
                'course_no' => $command->courseNo,
                'fire_group' => $command->fireGroup,
                'hold_until' => $command->holdUntil,
                'preparation_station_id' => $command->preparationStationId,
            ]);

            $item->load('product.taxCategory');
            $this->incrementRevisionAndRecalculate($lockedTicket);
            $lockedTicket->load('primaryTableMapping.table');

            $afterSnapshot = $this->revisionService->snapshot($lockedTicket, [
                'item' => $this->operationPayload($lockedTicket, $item),
            ]);
            $afterAudit = $this->operationAuditService->payloadForItem($lockedTicket, $item);
            $metadata = ['item_id' => $item->id, 'product_id' => $item->product_id];

            $this->operationAuditService->recordOperation(
                DiningOperationAuditService::ITEM_ADDED,
                $lockedTicket,
                null,
                $afterAudit,
                $actor,
                $terminal,
                metadata: $metadata,
            );
            $this->revisionService->recordMutationVersion(
                $lockedTicket,
                $actor,
                $terminal,
                'item_added',
                $beforeSnapshot,
                $afterSnapshot,
                metadata: $metadata,
            );
            $this->timelineService->recordEvent(
                $lockedTicket,
                DiningTicketTimelineService::ITEM_ADDED,
                $actor,
                $terminal,
                DiningTimelinePayload::fromTicket($lockedTicket, $this->operationPayload($lockedTicket, $item)),
            );

            return $item->fresh(['product']);
        });
    }

    public function changeQuantity(
        DiningTicket $ticket,
        DiningTicketItem $item,
        ChangeDiningTicketItemQuantityCommand $command,
        User $actor,
        SalesMachineProfile $terminal
    ): DiningTicketItem {
        return DB::transaction(function () use ($ticket, $item, $command, $actor, $terminal) {
            $lockedTicket = $this->lockedTicket($ticket);
            $lockedItem = $this->lockedItem($lockedTicket, $item);
            $this->assertCanMutateTicket($lockedTicket, $actor, $terminal, $command->expectedTicketRevision);
            $this->assertOpenItem($lockedItem);

            $quantityUnits = $this->quantityUnits($command->quantity);
            if ($this->quantityUnits((string) $lockedItem->quantity) === $quantityUnits) {
                return $lockedItem->fresh(['product']);
            }

            $beforeSnapshot = $this->revisionService->snapshot($lockedTicket, [
                'item' => $this->operationPayload($lockedTicket, $lockedItem),
            ]);
            $beforeAudit = $this->operationAuditService->payloadForItem($lockedTicket, $lockedItem, [
                'before_quantity' => (string) $lockedItem->quantity,
            ]);
            $beforeQuantity = (string) $lockedItem->quantity;

            $lockedItem->quantity = $this->quantityString($quantityUnits);
            $lockedItem->line_total_centavos = $this->lineTotalCentavos($lockedItem->unit_price_centavos, $quantityUnits);
            $lockedItem->save();
            $lockedItem->load('product');

            $this->incrementRevisionAndRecalculate($lockedTicket);
            $lockedTicket->load('primaryTableMapping.table');

            $metadata = [
                'item_id' => $lockedItem->id,
                'before_quantity' => $beforeQuantity,
                'after_quantity' => (string) $lockedItem->quantity,
            ];
            $afterSnapshot = $this->revisionService->snapshot($lockedTicket, [
                'item' => $this->operationPayload($lockedTicket, $lockedItem),
            ]);
            $afterAudit = $this->operationAuditService->payloadForItem($lockedTicket, $lockedItem, $metadata);

            $this->recordItemMutation(
                DiningOperationAuditService::ITEM_QUANTITY_CHANGED,
                'item_quantity_changed',
                DiningTicketTimelineService::ITEM_QUANTITY_CHANGED,
                $lockedTicket,
                $beforeSnapshot,
                $afterSnapshot,
                $beforeAudit,
                $afterAudit,
                $actor,
                $terminal,
                null,
                $metadata,
            );

            return $lockedItem->fresh(['product']);
        });
    }

    public function assignSeat(
        DiningTicket $ticket,
        DiningTicketItem $item,
        AssignDiningTicketItemSeatCommand $command,
        User $actor,
        SalesMachineProfile $terminal
    ): DiningTicketItem {
        return DB::transaction(function () use ($ticket, $item, $command, $actor, $terminal) {
            $lockedTicket = $this->lockedTicket($ticket);
            $lockedItem = $this->lockedItem($lockedTicket, $item);
            $this->assertCanMutateTicket($lockedTicket, $actor, $terminal, $command->expectedTicketRevision);
            $this->assertOpenItem($lockedItem);

            if ($lockedItem->seat_number === $command->seatNumber) {
                return $lockedItem->fresh(['product']);
            }

            $beforeSnapshot = $this->revisionService->snapshot($lockedTicket, [
                'item' => $this->operationPayload($lockedTicket, $lockedItem),
            ]);
            $beforeAudit = $this->operationAuditService->payloadForItem($lockedTicket, $lockedItem, [
                'before_seat_number' => $lockedItem->seat_number,
            ]);
            $beforeSeat = $lockedItem->seat_number;

            $lockedItem->seat_number = $command->seatNumber;
            $lockedItem->save();
            $lockedItem->load('product');

            $this->incrementRevisionAndRecalculate($lockedTicket);
            $lockedTicket->load('primaryTableMapping.table');

            $metadata = [
                'item_id' => $lockedItem->id,
                'before_seat_number' => $beforeSeat,
                'after_seat_number' => $lockedItem->seat_number,
            ];
            $afterSnapshot = $this->revisionService->snapshot($lockedTicket, [
                'item' => $this->operationPayload($lockedTicket, $lockedItem),
            ]);
            $afterAudit = $this->operationAuditService->payloadForItem($lockedTicket, $lockedItem, $metadata);

            $this->recordItemMutation(
                DiningOperationAuditService::ITEM_SEAT_ASSIGNED,
                'seat_assigned',
                DiningTicketTimelineService::SEAT_ASSIGNED,
                $lockedTicket,
                $beforeSnapshot,
                $afterSnapshot,
                $beforeAudit,
                $afterAudit,
                $actor,
                $terminal,
                null,
                $metadata,
            );

            return $lockedItem->fresh(['product']);
        });
    }

    public function moveToSeat(
        DiningTicket $ticket,
        DiningTicketItem $item,
        MoveDiningTicketItemSeatCommand $command,
        User $actor,
        SalesMachineProfile $terminal
    ): DiningTicketItem {
        return DB::transaction(function () use ($ticket, $item, $command, $actor, $terminal) {
            $lockedTicket = $this->lockedTicket($ticket);
            $lockedItem = $this->lockedItem($lockedTicket, $item);
            $this->assertCanMutateTicket($lockedTicket, $actor, $terminal, $command->expectedTicketRevision);
            $this->assertOpenItem($lockedItem);

            if ($lockedItem->seat_number === $command->seatNumber) {
                throw new DiningDomainException('DINING_ITEM_MOVE_SAME_SEAT', 'Item is already assigned to that seat.', 422);
            }

            if ($this->hasActiveReplacement($lockedItem)) {
                throw new DiningDomainException('DINING_ITEM_LINEAGE_CONFLICT', 'Moved item already has an active replacement.', 409);
            }

            $lockedItem->load('product');
            $beforeSnapshot = $this->revisionService->snapshot($lockedTicket, [
                'item' => $this->operationPayload($lockedTicket, $lockedItem),
            ]);
            $beforeAudit = $this->operationAuditService->payloadForItem($lockedTicket, $lockedItem, [
                'before_seat_number' => $lockedItem->seat_number,
            ]);
            $beforeSeat = $lockedItem->seat_number;

            $lockedItem->status = DiningTicketItem::STATUS_MOVED;
            $lockedItem->save();

            $replacement = DiningTicketItem::create([
                'tenant_id' => $lockedTicket->tenant_id,
                'branch_id' => $lockedTicket->branch_id,
                'dining_ticket_id' => $lockedTicket->id,
                'product_id' => $lockedItem->product_id,
                'seat_number' => $command->seatNumber,
                'quantity' => $lockedItem->quantity,
                'unit_price_centavos' => $lockedItem->unit_price_centavos,
                'line_total_centavos' => $lockedItem->line_total_centavos,
                'status' => DiningTicketItem::STATUS_OPEN,
                'source_item_id' => $lockedItem->id,
                'course_no' => $lockedItem->course_no,
                'fire_group' => $lockedItem->fire_group,
                'hold_until' => $lockedItem->hold_until,
                'preparation_station_id' => $lockedItem->preparation_station_id,
                'promotion_allocation_snapshot' => $lockedItem->promotion_allocation_snapshot,
            ]);
            $replacement->load('product');

            $this->incrementRevisionAndRecalculate($lockedTicket);
            $lockedTicket->load('primaryTableMapping.table');

            $metadata = [
                'source_item_id' => $lockedItem->id,
                'replacement_item_id' => $replacement->id,
                'before_seat_number' => $beforeSeat,
                'after_seat_number' => $replacement->seat_number,
            ];
            $afterSnapshot = $this->revisionService->snapshot($lockedTicket, [
                'source_item' => $this->operationPayload($lockedTicket, $lockedItem),
                'item' => $this->operationPayload($lockedTicket, $replacement),
            ]);
            $afterAudit = $this->operationAuditService->payloadForItem($lockedTicket, $replacement, $metadata);

            $this->recordItemMutation(
                DiningOperationAuditService::ITEM_MOVED,
                'item_moved',
                DiningTicketTimelineService::ITEM_MOVED,
                $lockedTicket,
                $beforeSnapshot,
                $afterSnapshot,
                $beforeAudit,
                $afterAudit,
                $actor,
                $terminal,
                null,
                $metadata,
            );

            return $replacement->fresh(['product']);
        });
    }

    public function voidItem(
        DiningTicket $ticket,
        DiningTicketItem $item,
        VoidDiningTicketItemCommand $command,
        User $actor,
        SalesMachineProfile $terminal
    ): DiningTicketItem {
        return DB::transaction(function () use ($ticket, $item, $command, $actor, $terminal) {
            $lockedTicket = $this->lockedTicket($ticket);
            $lockedItem = $this->lockedItem($lockedTicket, $item);
            $this->assertCanMutateTicket($lockedTicket, $actor, $terminal, $command->expectedTicketRevision);
            $this->assertOpenItem($lockedItem);
            $lockedItem->load('product');

            $beforeSnapshot = $this->revisionService->snapshot($lockedTicket, [
                'item' => $this->operationPayload($lockedTicket, $lockedItem),
            ]);
            $beforeAudit = $this->operationAuditService->payloadForItem($lockedTicket, $lockedItem);

            $lockedItem->status = DiningTicketItem::STATUS_VOIDED;
            $lockedItem->save();

            $this->incrementRevisionAndRecalculate($lockedTicket);
            $lockedTicket->load('primaryTableMapping.table');

            $metadata = [
                'item_id' => $lockedItem->id,
                'void_reason' => $command->reason,
            ];
            $afterSnapshot = $this->revisionService->snapshot($lockedTicket, [
                'item' => $this->operationPayload($lockedTicket, $lockedItem),
            ]);
            $afterAudit = $this->operationAuditService->payloadForItem($lockedTicket, $lockedItem, $metadata);

            $this->recordItemMutation(
                DiningOperationAuditService::ITEM_VOIDED,
                'item_voided',
                DiningTicketTimelineService::ITEM_VOIDED,
                $lockedTicket,
                $beforeSnapshot,
                $afterSnapshot,
                $beforeAudit,
                $afterAudit,
                $actor,
                $terminal,
                $command->reason,
                $metadata,
            );

            return $lockedItem->fresh(['product']);
        });
    }

    private function lockedTicket(DiningTicket $ticket): DiningTicket
    {
        return DiningTicket::query()
            ->with(['branch', 'primaryTableMapping.table'])
            ->whereKey($ticket->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockedItem(DiningTicket $ticket, DiningTicketItem $item): DiningTicketItem
    {
        return DiningTicketItem::query()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('branch_id', $ticket->branch_id)
            ->where('dining_ticket_id', $ticket->id)
            ->whereKey($item->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertCanMutateTicket(
        DiningTicket $ticket,
        User $actor,
        SalesMachineProfile $terminal,
        int $expectedRevision
    ): void {
        if (!$actor->canAccessBranch($ticket->branch)) {
            abort(404);
        }

        if ($terminal->tenant_id !== $ticket->tenant_id || $terminal->branch_id !== $ticket->branch_id) {
            throw new DiningDomainException('TERMINAL_CONTEXT_INVALID', 'Invalid terminal context.', 403);
        }

        if (!in_array($ticket->status, DiningTicket::ACTIVE_STATUSES, true)) {
            throw new DiningDomainException('DINING_TICKET_NOT_ACTIVE', 'Closed or voided dining tickets cannot be changed.', 409);
        }

        if ($this->hasSplitChildren($ticket)) {
            throw new DiningDomainException(
                'DINING_TICKET_ALREADY_SPLIT',
                'This ticket has split child bills and can no longer be changed directly.',
                409
            );
        }

        $this->revisionService->assertExpectedRevision($ticket, $expectedRevision);
    }

    private function assertOpenItem(DiningTicketItem $item): void
    {
        if ($item->status !== DiningTicketItem::STATUS_OPEN) {
            throw new DiningDomainException('DINING_ITEM_NOT_OPEN', 'Only open dining ticket items can be changed.', 409);
        }
    }

    private function incrementRevisionAndRecalculate(DiningTicket $ticket): void
    {
        $subtotal = (int) DiningTicketItem::query()
            ->where('dining_ticket_id', $ticket->id)
            ->activeForTotals()
            ->sum('line_total_centavos');

        $ticket->ticket_revision++;
        $ticket->subtotal_centavos = $subtotal;
        $ticket->discount_centavos = 0;
        $ticket->service_charge_centavos = 0;
        $ticket->tax_centavos = 0;
        $ticket->grand_total_centavos = $subtotal;
        $ticket->save();
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

    private function lineTotalCentavos(int $unitPriceCentavos, int $quantityUnits): int
    {
        return intdiv(($unitPriceCentavos * $quantityUnits) + 500, 1000);
    }

    private function moneyCentavos(string $amount): int
    {
        $amount = trim($amount);
        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $decimal = str_pad(substr($decimal, 0, 4), 4, '0');
        $centavos = ((int) $whole * 100) + intdiv(((int) substr($decimal, 0, 2) * 100) + (int) substr($decimal, 2, 2) + 50, 100);

        return $centavos;
    }

    private function hasActiveReplacement(DiningTicketItem $item): bool
    {
        return DiningTicketItem::query()
            ->where('source_item_id', $item->id)
            ->where('status', '!=', DiningTicketItem::STATUS_VOIDED)
            ->exists();
    }

    private function hasSplitChildren(DiningTicket $ticket): bool
    {
        return DiningTicket::query()
            ->where('parent_ticket_id', $ticket->id)
            ->exists();
    }

    private function operationPayload(DiningTicket $ticket, DiningTicketItem $item): array
    {
        return $this->operationAuditService->payloadForItem($ticket, $item)->toArray();
    }

    private function recordItemMutation(
        string $auditAction,
        string $revisionOperation,
        string $timelineEvent,
        DiningTicket $ticket,
        $beforeSnapshot,
        $afterSnapshot,
        $beforeAudit,
        $afterAudit,
        User $actor,
        SalesMachineProfile $terminal,
        ?string $reason,
        array $metadata
    ): void {
        $this->operationAuditService->recordOperation(
            $auditAction,
            $ticket,
            $beforeAudit,
            $afterAudit,
            $actor,
            $terminal,
            $reason,
            $metadata,
        );
        $this->revisionService->recordMutationVersion(
            $ticket,
            $actor,
            $terminal,
            $revisionOperation,
            $beforeSnapshot,
            $afterSnapshot,
            $reason,
            $metadata,
        );
        $this->timelineService->recordEvent(
            $ticket,
            $timelineEvent,
            $actor,
            $terminal,
            DiningTimelinePayload::fromTicket($ticket, $metadata),
        );
    }
}
