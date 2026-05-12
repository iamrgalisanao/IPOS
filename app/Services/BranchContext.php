<?php

namespace App\Services;

use App\Models\Branch;

class BranchContext
{
    protected ?Branch $branch = null;

    public function setBranch(Branch $branch): void
    {
        $this->branch = $branch;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function getBranchId(): ?string
    {
        return $this->branch?->id;
    }

    public function hasBranch(): bool
    {
        return !is_null($this->branch);
    }

    public function clear(): void
    {
        $this->branch = null;
    }
}
