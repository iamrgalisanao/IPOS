<?php

namespace App\Exceptions\Dining;

class DiningTableAlreadyOccupiedException extends DiningDomainException
{
    public function __construct(string $tableId, ?string $activeTicketId = null)
    {
        parent::__construct(
            'DINING_TABLE_ALREADY_HAS_ACTIVE_TICKET',
            'This table already has an active dining ticket.',
            409,
            array_filter([
                'dining_table_id' => $tableId,
                'active_ticket_id' => $activeTicketId,
            ])
        );
    }
}
