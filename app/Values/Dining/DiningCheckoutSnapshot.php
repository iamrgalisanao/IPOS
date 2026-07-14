<?php

namespace App\Values\Dining;

use App\Models\BillSplitAllocation;
use App\Models\DiningTicket;
use App\Models\DiningTicketItem;
use App\Models\Product;
use App\Models\SalesMachineProfile;
use App\Models\User;

final readonly class DiningCheckoutSnapshot
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private array $data)
    {
    }

    public static function fromTicket(DiningTicket $ticket, User $actor, ?SalesMachineProfile $terminal, string $checkoutRequestUuid, bool $isTrainingMode = false): self
    {
        $ticket->loadMissing([
            'items.product',
            'childSplitAllocations.sourceTicketItem',
            'primaryTableMapping.table',
        ]);

        $allocations = $ticket->childSplitAllocations->keyBy('child_ticket_item_id');
        $items = $ticket->items
            ->filter(fn (DiningTicketItem $item) => ! in_array($item->status, [
                DiningTicketItem::STATUS_VOIDED,
                DiningTicketItem::STATUS_MOVED,
            ], true))
            ->sortBy('created_at')
            ->values()
            ->map(function (DiningTicketItem $item) use ($allocations) {
                /** @var BillSplitAllocation|null $allocation */
                $allocation = $allocations->get($item->id);
                /** @var Product|null $product */
                $product = $item->product;
                $productSnapshot = $product?->getSaleSnapshotBase() ?? [];
                $promotionDiscountCentavos = (int) ($allocation?->promotion_discount_centavos
                    ?? ($item->promotion_allocation_snapshot['promotion_discount_centavos'] ?? 0));
                $allocatedAmountCentavos = (int) ($allocation?->allocated_amount_centavos ?? $item->line_total_centavos);
                $allocatedQuantity = (string) ($allocation?->allocated_quantity ?? $item->quantity);

                return [
                    'dining_ticket_item_id' => $item->id,
                    'source_ticket_item_id' => $allocation?->source_ticket_item_id ?? $item->source_item_id,
                    'product_id' => $item->product_id,
                    'quantity' => number_format((float) $allocatedQuantity, 4, '.', ''),
                    'seat_number' => $item->seat_number,
                    'unit_price_centavos' => (int) $item->unit_price_centavos,
                    'allocated_amount_centavos' => $allocatedAmountCentavos,
                    'promotion_discount_centavos' => $promotionDiscountCentavos,
                    'rounding_adjustment_centavos' => (int) ($allocation?->rounding_adjustment_centavos ?? 0),
                    'promotion_allocation_snapshot' => $allocation?->promotion_allocation_snapshot
                        ?? $item->promotion_allocation_snapshot,
                    'product_snapshot' => $productSnapshot,
                ];
            })
            ->all();

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $ticket->tenant_id,
            'branch_id' => $ticket->branch_id,
            'ticket_id' => $ticket->id,
            'child_ticket_id' => $ticket->parent_ticket_id ? $ticket->id : null,
            'parent_ticket_id' => $ticket->parent_ticket_id,
            'ticket_number' => $ticket->ticket_number,
            'ticket_status' => $ticket->status,
            'checkout_request_uuid' => $checkoutRequestUuid,
            'ticket_revision' => $ticket->ticket_revision,
            'actor_user_id' => $actor->id,
            'terminal_id' => $terminal?->id,
            'is_training_mode' => $isTrainingMode,
            'items' => $items,
            'totals' => [
                'subtotal_centavos' => (int) $ticket->subtotal_centavos,
                'discount_centavos' => (int) $ticket->discount_centavos,
                'service_charge_centavos' => (int) $ticket->service_charge_centavos,
                'tax_centavos' => (int) $ticket->tax_centavos,
                'grand_total_centavos' => (int) $ticket->grand_total_centavos,
            ],
        ];

        return new self($payload);
    }

    public function tenantId(): string
    {
        return $this->data['tenant_id'];
    }

    public function branchId(): string
    {
        return $this->data['branch_id'];
    }

    public function userId(): string
    {
        return $this->data['actor_user_id'];
    }

    public function checkoutRequestUuid(): string
    {
        return $this->data['checkout_request_uuid'];
    }

    public function terminalId(): ?string
    {
        return $this->data['terminal_id'];
    }

    public function isTrainingMode(): bool
    {
        return (bool) $this->data['is_training_mode'];
    }

    public function items(): array
    {
        return $this->data['items'];
    }

    public function totals(): array
    {
        return $this->data['totals'];
    }

    public function materialHash(): string
    {
        return hash('sha256', json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $this->data['tenant_id'],
            'branch_id' => $this->data['branch_id'],
            'ticket_id' => $this->data['ticket_id'],
            'child_ticket_id' => $this->data['child_ticket_id'],
            'parent_ticket_id' => $this->data['parent_ticket_id'],
            'checkout_request_uuid' => $this->data['checkout_request_uuid'],
            'ticket_revision' => $this->data['ticket_revision'],
            'is_training_mode' => $this->data['is_training_mode'],
            'items' => collect($this->data['items'])
                ->map(fn (array $item) => [
                    'dining_ticket_item_id' => $item['dining_ticket_item_id'],
                    'source_ticket_item_id' => $item['source_ticket_item_id'],
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'allocated_amount_centavos' => $item['allocated_amount_centavos'],
                    'promotion_discount_centavos' => $item['promotion_discount_centavos'],
                    'rounding_adjustment_centavos' => $item['rounding_adjustment_centavos'],
                ])
                ->sortBy('dining_ticket_item_id')
                ->values()
                ->all(),
            'totals' => $this->data['totals'],
        ], JSON_THROW_ON_ERROR));
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
