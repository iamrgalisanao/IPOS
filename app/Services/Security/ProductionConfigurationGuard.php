<?php

namespace App\Services\Security;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

class ProductionConfigurationGuard
{
    public function __construct(
        protected Application $app,
    ) {}

    public function assertSafeConfiguration(): void
    {
        $environment = (string) config('app.env', $this->app->environment());

        if (in_array($environment, ['local', 'testing'], true)) {
            return;
        }

        $violations = [];

        if ((bool) config('app.debug')) {
            $violations[] = 'APP_DEBUG must be false outside local/testing.';
        }

        if (! (bool) config('session.secure')) {
            $violations[] = 'SESSION_SECURE_COOKIE must be true outside local/testing.';
        }

        if (! (bool) config('session.http_only')) {
            $violations[] = 'SESSION_HTTP_ONLY must be true outside local/testing.';
        }

        if ($violations === []) {
            return;
        }

        throw new RuntimeException('Unsafe production configuration detected: '.implode(' ', $violations));
    }
}