<?php

namespace App\Exceptions\Dining;

use RuntimeException;

class DiningDomainException extends RuntimeException
{
    public function __construct(
        protected string $errorCode,
        string $message,
        protected int $status = 409,
        protected array $context = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function toResponsePayload(): array
    {
        return array_merge([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->context);
    }
}
