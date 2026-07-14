<?php

namespace App\Values\Dining;

final readonly class MoveDiningTicketItemSeatCommand
{
    public function __construct(
        public int $seatNumber,
        public int $expectedTicketRevision,
    ) {
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            seatNumber: (int) $data['seat_number'],
            expectedTicketRevision: (int) $data['expected_ticket_revision'],
        );
    }
}
