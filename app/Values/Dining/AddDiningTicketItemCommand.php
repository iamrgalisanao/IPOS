<?php

namespace App\Values\Dining;

final readonly class AddDiningTicketItemCommand
{
    public function __construct(
        public string $productId,
        public string $quantity,
        public ?int $seatNumber,
        public ?int $courseNo,
        public ?string $fireGroup,
        public ?string $holdUntil,
        public ?string $preparationStationId,
        public int $expectedTicketRevision,
    ) {
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            productId: $data['product_id'],
            quantity: (string) $data['quantity'],
            seatNumber: $data['seat_number'] ?? null,
            courseNo: $data['course_no'] ?? null,
            fireGroup: $data['fire_group'] ?? null,
            holdUntil: $data['hold_until'] ?? null,
            preparationStationId: $data['preparation_station_id'] ?? null,
            expectedTicketRevision: (int) $data['expected_ticket_revision'],
        );
    }
}
