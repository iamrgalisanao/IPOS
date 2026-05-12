<?php

namespace Tests\Feature\Support;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\Support\SupportPayloadMasker;
use App\Services\SupportAccessSessionService;
use App\Services\SupportContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportPayloadMaskingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        app(SupportContext::class)->clear();
    }

    public function test_default_masking_redacts_sensitive_keys_recursively_and_does_not_mutate_input(): void
    {
        $masker = app(SupportPayloadMasker::class);

        $input = [
            'connection_status' => 'connected',
            'realm_id' => '123456789',
            'access_token' => 'abc',
            'refresh_token' => 'def',
            'headers' => [
                'Authorization' => 'Bearer xyz',
            ],
            'metadata' => [
                'client_secret' => 'secret-value',
                'provider_payload' => [
                    'token' => 'nested-token',
                    'safe_status' => 'ok',
                ],
            ],
            'gross_total' => 1000.25,
            'notes' => 'Authorization: Bearer keep-this-hidden',
        ];

        $original = $input;
        $masked = $masker->mask($input);

        $this->assertSame('connected', $masked['connection_status']);
        $this->assertSame('123456789', $masked['realm_id']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $masked['access_token']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $masked['refresh_token']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $masked['headers']['Authorization']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $masked['metadata']['client_secret']);
        $this->assertSame(SupportPayloadMasker::REDACTED_PAYLOAD, $masked['metadata']['provider_payload']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $masked['gross_total']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $masked['notes']);
        $this->assertSame($original, $input);
    }

    public function test_strict_masking_profile_redacts_identifier_fields_deterministically(): void
    {
        $masker = app(SupportPayloadMasker::class);

        $input = [
            'realm_id' => '123456789',
            'support_session_id' => 'session-uuid',
            'tenant_id' => 'tenant-uuid',
            'branch_id' => 'branch-uuid',
            'status' => 'connected',
            'nested' => [
                'email' => 'support@example.com',
                'safe_status' => 'ok',
            ],
        ];

        $masked = $masker->mask($input, SupportPayloadMasker::PROFILE_STRICT);

        $this->assertSame(SupportPayloadMasker::REDACTED, $masked['realm_id']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $masked['support_session_id']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $masked['tenant_id']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $masked['branch_id']);
        $this->assertSame('connected', $masked['status']);
        $this->assertSame(SupportPayloadMasker::REDACTED, $masked['nested']['email']);
        $this->assertSame('ok', $masked['nested']['safe_status']);
        $this->assertSame($masked, $masker->mask($input, SupportPayloadMasker::PROFILE_STRICT));
    }

    public function test_probe_route_returns_masked_payload_without_side_effects_or_provider_calls(): void
    {
        Http::fake();

        $supportUser = User::factory()->platformSupport()->create();
        $tenant = Tenant::factory()->create();
        $branch = $this->createBranch($tenant);
        $session = app(SupportAccessSessionService::class)->startSession(
            supportUser: $supportUser,
            tenant: $tenant,
            branch: $branch,
            reason: 'Masking probe integration test.',
            maskingProfile: SupportPayloadMasker::PROFILE_STRICT
        );

        $counts = [
            'accounting_outbox' => DB::table('accounting_outbox')->count(),
            'sales' => DB::table('sales')->count(),
            'sale_payments' => DB::table('sale_payments')->count(),
            'branch_inventories' => DB::table('branch_inventories')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'sale_refunds' => DB::table('sale_refunds')->count(),
            'sale_voids' => DB::table('sale_voids')->count(),
        ];

        $this->actingAs($supportUser)
            ->getJson(route('support.assisted.probe', $session))
            ->assertOk()
            ->assertJson([
                'support_session_id' => $session->id,
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'mode' => 'support_assisted',
                'masking_profile' => SupportPayloadMasker::PROFILE_STRICT,
                'masked_payload' => [
                    'connection_status' => 'connected',
                    'realm_id' => SupportPayloadMasker::REDACTED,
                    'access_token' => SupportPayloadMasker::REDACTED,
                    'refresh_token' => SupportPayloadMasker::REDACTED,
                    'headers' => [
                        'Authorization' => SupportPayloadMasker::REDACTED,
                    ],
                    'metadata' => [
                        'client_secret' => SupportPayloadMasker::REDACTED,
                        'provider_payload' => SupportPayloadMasker::REDACTED_PAYLOAD,
                    ],
                    'gross_total' => SupportPayloadMasker::REDACTED,
                    'support_session_id' => SupportPayloadMasker::REDACTED,
                    'tenant_id' => SupportPayloadMasker::REDACTED,
                ],
            ]);

        $this->assertSame($counts['accounting_outbox'], DB::table('accounting_outbox')->count());
        $this->assertSame($counts['sales'], DB::table('sales')->count());
        $this->assertSame($counts['sale_payments'], DB::table('sale_payments')->count());
        $this->assertSame($counts['branch_inventories'], DB::table('branch_inventories')->count());
        $this->assertSame($counts['inventory_movements'], DB::table('inventory_movements')->count());
        $this->assertSame($counts['sale_refunds'], DB::table('sale_refunds')->count());
        $this->assertSame($counts['sale_voids'], DB::table('sale_voids')->count());

        Http::assertNothingSent();
    }

    protected function createBranch(Tenant $tenant): Branch
    {
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();

        return $branch;
    }
}