<?php

namespace App\Exceptions\Dining;

class DiningTicketTransitionException extends DiningDomainException
{
    public function __construct(string $currentStatus, string $targetStatus)
    {
        parent::__construct(
            'DINING_TICKET_INVALID_TRANSITION',
            'The requested dining ticket status transition is not allowed.',
            422,
            [
                'current_status' => $currentStatus,
                'target_status' => $targetStatus,
            ]
        );
    }
}
