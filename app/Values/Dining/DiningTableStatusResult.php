<?php

namespace App\Values\Dining;

final readonly class DiningTableStatusResult
{
    public const VACANT = 'vacant';
    public const OCCUPIED = 'occupied';
    public const RESERVED = 'reserved';
    public const CLEANING = 'cleaning';
    public const INACTIVE = 'inactive';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $status,
        public string $reason,
        public ?array $activeTicket = null,
        public array $diagnostics = [],
    ) {
    }

    public static function vacant(): self
    {
        return new self(self::VACANT, 'table_available');
    }

    public static function occupied(array $activeTicket): self
    {
        return new self(self::OCCUPIED, 'active_primary_ticket', $activeTicket);
    }

    public static function reserved(): self
    {
        return new self(self::RESERVED, 'table_reserved');
    }

    public static function cleaning(): self
    {
        return new self(self::CLEANING, 'table_cleaning');
    }

    public static function inactive(): self
    {
        return new self(self::INACTIVE, 'table_inactive');
    }

    public static function unavailable(string $reason, array $diagnostics = []): self
    {
        return new self(self::UNAVAILABLE, $reason, null, $diagnostics);
    }

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'status_reason' => $this->reason,
            'active_ticket' => $this->activeTicket,
            'diagnostics' => $this->diagnostics ?: null,
        ], fn ($value) => $value !== null);
    }
}
