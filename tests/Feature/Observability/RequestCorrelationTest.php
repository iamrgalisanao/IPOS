<?php

namespace Tests\Feature\Observability;

use App\Services\Observability\RequestCorrelation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RequestCorrelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app['router'];

        if (!$router->getRoutes()->getByName('test.observability.correlation')) {
            $router->middleware('web')->get('/test/observability/correlation', function (Request $request, RequestCorrelation $requestCorrelation) {
                return response()->json([
                    'request_attribute' => $request->attributes->get('correlation_id'),
                    'current' => $requestCorrelation->current(),
                    'log_context' => $requestCorrelation->context($request),
                ]);
            })->name('test.observability.correlation');
        }
    }

    public function test_request_without_correlation_header_receives_generated_uuid_and_response_header(): void
    {
        $response = $this->getJson('/test/observability/correlation');

        $header = $response->headers->get('X-Correlation-ID');

        $response->assertOk()
            ->assertHeader('X-Correlation-ID')
            ->assertJsonPath('request_attribute', $header)
            ->assertJsonPath('current', $header)
            ->assertJsonPath('log_context.correlation_id', $header)
            ->assertJsonPath('log_context.tenant_id', null)
            ->assertJsonPath('log_context.branch_id', null)
            ->assertJsonPath('log_context.actor_id', null)
            ->assertJsonPath('log_context.actor_type', null)
            ->assertJsonPath('log_context.route_name', 'test.observability.correlation');

        $this->assertTrue(Str::isUuid($header));
    }

    public function test_valid_incoming_correlation_id_is_preserved_and_invalid_values_are_replaced(): void
    {
        $valid = 'trace-123:abc_DEF.1';

        $this->withHeader('X-Correlation-ID', $valid)
            ->getJson('/test/observability/correlation')
            ->assertOk()
            ->assertHeader('X-Correlation-ID', $valid)
            ->assertJsonPath('request_attribute', $valid)
            ->assertJsonPath('current', $valid);

        $invalidResponse = $this->withoutHeader('X-Correlation-ID')
            ->withHeader('X-Request-ID', 'bad value with spaces')
            ->getJson('/test/observability/correlation');

        $invalidHeader = $invalidResponse->headers->get('X-Correlation-ID');

        $invalidResponse->assertOk()
            ->assertHeader('X-Correlation-ID')
            ->assertJsonPath('request_attribute', $invalidHeader)
            ->assertJsonPath('current', $invalidHeader);

        $this->assertNotSame('bad value with spaces', $invalidHeader);
        $this->assertTrue(Str::isUuid($invalidHeader));

        $tooLong = str_repeat('a', 192);
        $tooLongResponse = $this->withoutHeader('X-Request-ID')
            ->withHeader('X-Correlation-ID', $tooLong)
            ->getJson('/test/observability/correlation');

        $tooLongHeader = $tooLongResponse->headers->get('X-Correlation-ID');

        $tooLongResponse->assertOk()
            ->assertHeader('X-Correlation-ID')
            ->assertJsonPath('request_attribute', $tooLongHeader);

        $this->assertNotSame($tooLong, $tooLongHeader);
        $this->assertTrue(Str::isUuid($tooLongHeader));
    }

    public function test_log_context_shares_correlation_id_safely_without_exposing_authorization_values(): void
    {
        Log::spy();

        $response = $this->withHeaders([
            'X-Correlation-ID' => 'safe-correlation-id',
            'Authorization' => 'Bearer secret-token',
        ])->getJson('/test/observability/correlation');

        $response->assertOk()
            ->assertHeader('X-Correlation-ID', 'safe-correlation-id')
            ->assertJsonPath('log_context.correlation_id', 'safe-correlation-id');

        $this->assertArrayNotHasKey('Authorization', $response->json('log_context'));
        $this->assertArrayNotHasKey('authorization', $response->json('log_context'));
        $this->assertStringNotContainsString('secret-token', json_encode($response->json(), JSON_THROW_ON_ERROR));

        Log::shouldHaveReceived('shareContext')
            ->atLeast()
            ->once()
            ->with(Mockery::on(function (array $context) {
                return ($context['correlation_id'] ?? null) === 'safe-correlation-id'
                    && !array_key_exists('Authorization', $context)
                    && !array_key_exists('authorization', $context)
                    && ($context['route_name'] ?? null) === 'test.observability.correlation';
            }));
    }
}