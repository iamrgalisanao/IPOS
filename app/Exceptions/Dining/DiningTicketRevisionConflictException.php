<?php

namespace App\Exceptions\Dining;

class DiningTicketRevisionConflictException extends DiningDomainException
{
    public function __construct(int $currentRevision)
    {
        parent::__construct(
            'DINING_TICKET_REVISION_CONFLICT',
            'The dining ticket was updated by another user.',
            409,
            ['current_ticket_revision' => $currentRevision]
        );
    }
}
