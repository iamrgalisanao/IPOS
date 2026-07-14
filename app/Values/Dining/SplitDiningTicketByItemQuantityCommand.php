<?php

namespace App\Values\Dining;

final readonly class SplitDiningTicketByItemQuantityCommand
{
    public function __construct(
        public int $expectedTicketRevision,
        public string $clientRequestUuid,
        public array $groups,
    ) {
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            (int) $data['expected_ticket_revision'],
            (string) $data['client_request_uuid'],
            array_values($data['groups']),
        );
    }
}
