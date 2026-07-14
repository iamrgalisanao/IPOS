<?php

namespace App\Values\Dining;

use App\Models\DiningTicket;

final readonly class DiningTicketSnapshot
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private array $data)
    {
    }

    public static function fromTicket(DiningTicket $ticket, array $extra = []): self
    {
        $primary = $ticket->primaryTableMapping?->table;

        return new self(self::sanitize(array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'ticket_id' => $ticket->id,
            'tenant_id' => $ticket->tenant_id,
            'branch_id' => $ticket->branch_id,
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->status,
            'guest_count' => $ticket->guest_count,
            'ticket_revision' => $ticket->ticket_revision,
            'subtotal_centavos' => $ticket->subtotal_centavos,
            'discount_centavos' => $ticket->discount_centavos,
            'service_charge_centavos' => $ticket->service_charge_centavos,
            'tax_centavos' => $ticket->tax_centavos,
            'grand_total_centavos' => $ticket->grand_total_centavos,
            'opened_by' => $ticket->opened_by,
            'opened_at' => optional($ticket->opened_at)->toIso8601String(),
            'closed_at' => optional($ticket->closed_at)->toIso8601String(),
            'terminal_id' => $ticket->terminal_id,
            'primary_table_id' => $primary?->id,
            'primary_table_number' => $primary?->table_number,
            'service_area_id' => $primary?->service_area_id,
        ], $extra)));
    }

    public function toArray(): array
    {
        return $this->data;
    }

    private static function sanitize(array $payload): array
    {
        $blocked = [
            'approval_token',
            'card_number',
            'cvv',
            'password',
            'payment_token',
            'pin',
            'raw_payment_data',
            'secret',
            'token',
        ];

        return collect($payload)
            ->reject(fn ($value, $key) => in_array($key, $blocked, true))
            ->map(function ($value) {
                if (is_array($value)) {
                    return self::sanitize($value);
                }

                if (is_string($value) && mb_strlen($value) > 500) {
                    return mb_substr($value, 0, 500);
                }

                return $value;
            })
            ->all();
    }
}
