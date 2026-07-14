<?php

namespace App\Values\Dining;

use App\Models\DiningTicket;
use App\Models\DiningTicketItem;

final readonly class DiningTicketItemPayload
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private array $data)
    {
    }

    public static function fromItem(DiningTicket $ticket, DiningTicketItem $item, array $extra = []): self
    {
        $product = $item->relationLoaded('product') ? $item->product : null;

        return new self(array_filter(array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'ticket_id' => $ticket->id,
            'tenant_id' => $ticket->tenant_id,
            'branch_id' => $ticket->branch_id,
            'ticket_number' => $ticket->ticket_number,
            'ticket_revision' => $ticket->ticket_revision,
            'item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_name' => $product?->name,
            'sku' => $product?->sku,
            'barcode' => $product?->barcode,
            'unit_of_measure' => $product?->unit_of_measure,
            'tax_category_id' => $product?->tax_category_id,
            'is_discountable' => $product?->is_discountable,
            'seat_number' => $item->seat_number,
            'quantity' => (string) $item->quantity,
            'unit_price_centavos' => $item->unit_price_centavos,
            'line_total_centavos' => $item->line_total_centavos,
            'status' => $item->status,
            'source_item_id' => $item->source_item_id,
            'course_no' => $item->course_no,
            'fire_group' => $item->fire_group,
            'hold_until' => optional($item->hold_until)->toIso8601String(),
            'preparation_station_id' => $item->preparation_station_id,
        ], $extra), fn ($value) => $value !== null));
    }

    public function with(array $extra): self
    {
        return new self(array_merge($this->data, $extra));
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
