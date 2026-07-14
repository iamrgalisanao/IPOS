<?php

namespace App\Values\Dining;

final readonly class ChangeDiningTicketItemQuantityCommand
{
    public function __construct(
        public string $quantity,
        public int $expectedTicketRevision,
    ) {
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            quantity: (string) $data['quantity'],
            expectedTicketRevision: (int) $data['expected_ticket_revision'],
        );
    }
}
