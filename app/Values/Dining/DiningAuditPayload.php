<?php

namespace App\Values\Dining;

use App\Models\DiningTicket;

final readonly class DiningAuditPayload
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private array $data)
    {
    }

    public static function fromTicket(DiningTicket $ticket, array $extra = []): self
    {
        $snapshot = DiningTicketSnapshot::fromTicket($ticket, $extra)->toArray();

        return new self(array_merge($snapshot, [
            'schema_version' => self::SCHEMA_VERSION,
        ]));
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
