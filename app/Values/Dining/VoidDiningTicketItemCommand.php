<?php

namespace App\Values\Dining;

final readonly class VoidDiningTicketItemCommand
{
    public function __construct(
        public string $reason,
        public int $expectedTicketRevision,
    ) {
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            reason: $data['reason'],
            expectedTicketRevision: (int) $data['expected_ticket_revision'],
        );
    }
}
