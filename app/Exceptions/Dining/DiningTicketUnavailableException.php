<?php

namespace App\Exceptions\Dining;

class DiningTicketUnavailableException extends DiningDomainException
{
    public static function inactiveServiceArea(): self
    {
        return new self(
            'SERVICE_AREA_INACTIVE',
            'Dining tickets cannot be opened in an inactive service area.'
        );
    }

    public static function inactiveTable(): self
    {
        return new self(
            'DINING_TABLE_INACTIVE',
            'Dining tickets cannot be opened for an inactive table.'
        );
    }

    public static function tableNotAvailable(string $state): self
    {
        return new self(
            'DINING_TABLE_NOT_AVAILABLE',
            'Only available tables can open a dining ticket.',
            409,
            ['operational_state' => $state]
        );
    }
}
