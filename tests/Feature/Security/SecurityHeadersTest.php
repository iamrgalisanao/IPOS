<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app['router'];

        if (!$router->getRoutes()->getByName('test.security.headers.web')) {
            $router->middleware('web')->get('/test/security/headers/web', function () {
                return response('web-ok', 200);
            })->name('test.security.headers.web');
        }

        if (!$router->getRoutes()->getByName('test.security.headers.api')) {
            $router->middleware('api')->get('/test/security/headers/api', function () {
                return response()->json(['status' => 'api-ok']);
            })->name('test.security.headers.api');
        }
    }

    public function test_web_responses_include_expected_security_headers(): void
    {
        $response = $this->get('/test/security/headers/web');

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function test_api_responses_include_expected_security_headers(): void
    {
        $response = $this->getJson('/test/security/headers/api');

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function test_hsts_is_applied_only_for_secure_production_like_requests(): void
    {
        config()->set('app.env', 'production');

        $secureResponse = $this->withHeader('X-Forwarded-Proto', 'https')
            ->get('/test/security/headers/web');

        $secureResponse->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        $nonSecureResponse = $this->withoutHeader('X-Forwarded-Proto')
            ->get('/test/security/headers/web');

        $nonSecureResponse->assertOk();
        $this->assertFalse($nonSecureResponse->headers->has('Strict-Transport-Security'));
    }

    public function test_testing_http_behavior_remains_compatible_without_hsts(): void
    {
        config()->set('app.env', 'testing');

        $response = $this->withServerVariables([
            'HTTPS' => 'on',
        ])->get('/test/security/headers/web');

        $response->assertOk();
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }
}