<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs($user, $guard = null)
    {
        parent::actingAs($user, $guard);

        if (isset($user->tenant_id) && $user->tenant_id) {
            app(\App\Services\TenantContext::class)->setTenant($user->tenant);
        }

        return $this;
    }
}
