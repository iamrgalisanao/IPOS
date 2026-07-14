<?php

namespace App\Values\Dining;

use App\Models\DiningTicket;

final readonly class DiningTimelinePayload
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

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
