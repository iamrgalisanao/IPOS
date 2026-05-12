<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\SupportAccessSession;
use App\Models\Tenant;
use App\Models\User;

class SupportContext
{
    protected ?SupportAccessSession $session = null;

    public function setSession(SupportAccessSession $session): void
    {
        $this->session = $session->loadMissing(['supportUser', 'tenant', 'branch', 'approvedBy']);
    }

    public function getSession(): ?SupportAccessSession
    {
        return $this->session;
    }

    public function getSessionId(): ?string
    {
        return $this->session?->id;
    }

    public function hasSession(): bool
    {
        return !is_null($this->session);
    }

    public function getSupportUser(): ?User
    {
        return $this->session?->supportUser;
    }

    public function getSupportUserId(): ?string
    {
        return $this->session?->support_user_id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->session?->tenant;
    }

    public function getTenantId(): ?string
    {
        return $this->session?->tenant_id;
    }

    public function hasTenant(): bool
    {
        return !is_null($this->getTenantId());
    }

    public function getBranch(): ?Branch
    {
        return $this->session?->branch;
    }

    public function getBranchId(): ?string
    {
        return $this->session?->branch_id;
    }

    public function hasBranch(): bool
    {
        return !is_null($this->getBranchId());
    }

    public function clear(): void
    {
        $this->session = null;
    }
}