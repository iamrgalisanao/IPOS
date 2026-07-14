<?php

namespace App\Exceptions\Dining;

class DiningTicketIdempotencyDriftException extends DiningDomainException
{
    public function __construct()
    {
        parent::__construct(
            'IDEMPOTENCY_DRIFT',
            'This request UUID was already used with different dining ticket details.',
            409
        );
    }
}
