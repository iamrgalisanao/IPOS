<?php

namespace App\Values\Dining;

final readonly class AssignDiningTicketItemSeatCommand
{
    public function __construct(
        public ?int $seatNumber,
        public int $expectedTicketRevision,
    ) {
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            seatNumber: $data['seat_number'] ?? null,
            expectedTicketRevision: (int) $data['expected_ticket_revision'],
        );
    }
}
