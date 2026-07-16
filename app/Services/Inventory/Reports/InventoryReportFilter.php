<?php

namespace App\Services\Inventory\Reports;

use Illuminate\Support\Arr;

class InventoryReportFilter
{
    public function __construct(
        public readonly array $values,
    ) {}

    public static function from(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->values, $key, $default);
    }

    public function all(): array
    {
        return $this->values;
    }

    public function fingerprint(): string
    {
        $values = $this->values;
        ksort($values);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
