<?php

namespace Tests\Feature\Security;

use App\Services\Security\ProductionConfigurationGuard;
use Tests\TestCase;

class ProductionConfigurationGuardTest extends TestCase
{
    public function test_production_like_environment_rejects_debug_mode(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', true);
        config()->set('session.secure', true);
        config()->set('session.http_only', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG must be false outside local/testing.');

        app(ProductionConfigurationGuard::class)->assertSafeConfiguration();
    }

    public function test_production_like_environment_rejects_insecure_session_cookie(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('session.secure', false);
        config()->set('session.http_only', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SESSION_SECURE_COOKIE must be true outside local/testing.');

        app(ProductionConfigurationGuard::class)->assertSafeConfiguration();
    }

    public function test_production_like_environment_rejects_non_http_only_session_cookie(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('session.secure', true);
        config()->set('session.http_only', false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SESSION_HTTP_ONLY must be true outside local/testing.');

        app(ProductionConfigurationGuard::class)->assertSafeConfiguration();
    }

    public function test_local_environment_is_exempt(): void
    {
        config()->set('app.env', 'local');
        config()->set('app.debug', true);
        config()->set('session.secure', false);
        config()->set('session.http_only', false);

        app(ProductionConfigurationGuard::class)->assertSafeConfiguration();

        $this->assertTrue(true);
    }

    public function test_testing_environment_is_exempt(): void
    {
        config()->set('app.env', 'testing');
        config()->set('app.debug', true);
        config()->set('session.secure', false);
        config()->set('session.http_only', false);

        app(ProductionConfigurationGuard::class)->assertSafeConfiguration();

        $this->assertTrue(true);
    }
}
