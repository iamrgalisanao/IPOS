<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyReversalSetting;
use App\Services\TenantContext;

class LoyaltyReversalSettingService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function forCurrentTenant(): LoyaltyReversalSetting
    {
        $tenantId = $this->tenantContext->getTenantId();

        return LoyaltyReversalSetting::firstOrCreate(
            ['tenant_id' => $tenantId],
            LoyaltyReversalSetting::defaults($tenantId)
        );
    }
}
