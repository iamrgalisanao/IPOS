<?php

namespace Tests\Feature\Support;

use App\Models\Branch;
use App\Models\SupportAccessSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupportAccessSessionDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    public function test_support_access_sessions_table_exists_with_required_fields(): void
    {
        $this->assertTrue(Schema::hasTable('support_access_sessions'));
        $this->assertTrue(Schema::hasColumns('support_access_sessions', [
            'id',
            'support_user_id',
            'tenant_id',
            'branch_id',
            'reason',
            'approved_by',
            'started_at',
            'expires_at',
            'ended_at',
            'status',
            'masking_profile',
            'metadata',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_support_access_session_model_exposes_status_constants(): void
    {
        $this->assertSame('active', SupportAccessSession::STATUS_ACTIVE);
        $this->assertSame('expired', SupportAccessSession::STATUS_EXPIRED);
        $this->assertSame('revoked', SupportAccessSession::STATUS_REVOKED);
        $this->assertSame('ended', SupportAccessSession::STATUS_ENDED);
    }

    public function test_support_access_session_relationships_and_defaults_work(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        app(TenantContext::class)->clear();
        $supportUser = User::factory()->platformSupport()->create();
        $approver = User::factory()->platformSupport()->create();

        $session = SupportAccessSession::create([
            'support_user_id' => $supportUser->id,
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'reason' => 'Investigate sync state for tenant support.',
            'approved_by' => $approver->id,
            'expires_at' => now()->addMinutes(30),
            'masking_profile' => 'default',
            'metadata' => ['ticket' => 'SUP-131'],
        ]);

        $freshSession = $session->fresh();

        $this->assertSame(SupportAccessSession::STATUS_ACTIVE, $freshSession->status);
        $this->assertNotNull($freshSession->started_at);
        $this->assertNull($freshSession->ended_at);
        $this->assertSame(['ticket' => 'SUP-131'], $freshSession->metadata);

        $this->assertInstanceOf(User::class, $freshSession->supportUser);
        $this->assertInstanceOf(Tenant::class, $freshSession->tenant);
        $this->assertInstanceOf(Branch::class, $freshSession->branch);
        $this->assertInstanceOf(User::class, $freshSession->approvedBy);

        $this->assertSame($supportUser->id, $freshSession->supportUser->id);
        $this->assertSame($tenant->id, $freshSession->tenant->id);
        $this->assertSame($branch->id, $freshSession->branch->id);
        $this->assertSame($approver->id, $freshSession->approvedBy->id);
    }

    public function test_branch_and_approved_by_are_nullable(): void
    {
        $tenant = Tenant::factory()->create();
        $supportUser = User::factory()->platformSupport()->create();

        $session = SupportAccessSession::create([
            'support_user_id' => $supportUser->id,
            'tenant_id' => $tenant->id,
            'reason' => 'Validate tenant-scoped support session foundation.',
            'expires_at' => now()->addHour(),
            'masking_profile' => 'default',
        ]);

        $freshSession = $session->fresh();

        $this->assertNull($freshSession->branch_id);
        $this->assertNull($freshSession->approved_by);
        $this->assertNull($freshSession->branch);
        $this->assertNull($freshSession->approvedBy);
    }

    public function test_creating_support_access_session_does_not_trigger_side_effects(): void
    {
        $tenant = Tenant::factory()->create();
        $supportUser = User::factory()->platformSupport()->create();

        $beforeCounts = [
            'accounting_outbox' => DB::table('accounting_outbox')->count(),
            'sales' => DB::table('sales')->count(),
            'sale_payments' => DB::table('sale_payments')->count(),
            'branch_inventories' => DB::table('branch_inventories')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'sale_refunds' => DB::table('sale_refunds')->count(),
            'sale_voids' => DB::table('sale_voids')->count(),
        ];

        SupportAccessSession::create([
            'support_user_id' => $supportUser->id,
            'tenant_id' => $tenant->id,
            'reason' => 'Pure persistence foundation check.',
            'expires_at' => now()->addMinutes(15),
            'masking_profile' => 'default',
            'metadata' => ['source' => 'test'],
        ]);

        $this->assertSame($beforeCounts['accounting_outbox'], DB::table('accounting_outbox')->count());
        $this->assertSame($beforeCounts['sales'], DB::table('sales')->count());
        $this->assertSame($beforeCounts['sale_payments'], DB::table('sale_payments')->count());
        $this->assertSame($beforeCounts['branch_inventories'], DB::table('branch_inventories')->count());
        $this->assertSame($beforeCounts['inventory_movements'], DB::table('inventory_movements')->count());
        $this->assertSame($beforeCounts['sale_refunds'], DB::table('sale_refunds')->count());
        $this->assertSame($beforeCounts['sale_voids'], DB::table('sale_voids')->count());
        $this->assertDatabaseCount('support_access_sessions', 1);
    }
}