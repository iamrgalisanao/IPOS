<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\CashDrawerEvent;
use App\Models\CashDrawerReason;
use App\Models\Shift;
use App\Models\User;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenants;

class POSDrawerReasonTest extends TestCase
{
    use RefreshDatabase, InteractsWithTenants;

    protected User $cashier;
    protected User $manager;
    protected Branch $branch;
    protected Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->setupTenantContext();

        config(['app.enforce_timecards' => false]);
        config(['app.enforce_terminal_binding' => false]);

        $this->tenant->update([
            'subscription_metadata' => [
                'plan' => 'professional',
                'features' => [
                    'sales.pos' => true,
                ],
            ]
        ]);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'branch_code' => 'MAIN',
            'name' => 'Main Branch',
        ]);

        $this->cashier = $this->createTenantUser('cashier', [
            'email' => 'cashier@bmad.coffee',
            'password' => bcrypt('password'),
        ]);

        $this->manager = $this->createTenantUser('manager', [
            'email' => 'admin@bmad.coffee',
            'password' => bcrypt('password'),
        ]);

        $this->cashier->branches()->attach($this->branch->id);
        $this->manager->branches()->attach($this->branch->id);

        $this->givePermissionTo($this->cashier, 'create_sale');
        $this->givePermissionTo($this->manager, 'manage_cash_drawer');
        $this->givePermissionTo($this->manager, 'approve_shift');

        // Create seed reasons for bootstrap testing
        CashDrawerReason::create([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'cash_drop',
            'code' => 'SKIM',
            'name' => 'Skim (Excess Cash)',
            'branch_id' => null,
            'requires_manager_approval' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        CashDrawerReason::create([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'cash_top_up',
            'code' => 'REPLENISH',
            'name' => 'Replenish Change',
            'branch_id' => null,
            'requires_manager_approval' => false,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Create an open shift for the cashier
        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cashier_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'status' => Shift::STATUS_OPEN,
            'opened_at' => now(),
            'opening_cash_amount' => '100.0000',
        ]);
    }

    public function test_offline_readiness_bootstrap_includes_reasons_and_correct_hash(): void
    {
        $bootstrapService = app(CacheBootstrapService::class);
        $payload = $bootstrapService->generatePayload($this->tenant, $this->branch, $this->cashier);

        $this->assertArrayHasKey('cash_drawer_reasons', $payload);
        $this->assertArrayHasKey('cash_drawer_reasons_version_hash', $payload);
        
        $this->assertNotEmpty($payload['cash_drawer_reasons']);
        $this->assertNotNull($payload['cash_drawer_reasons_version_hash']);

        // Check resolved list contains default seeds
        $codes = collect($payload['cash_drawer_reasons'])->pluck('code')->all();
        $this->assertContains('SKIM', $codes);
        $this->assertContains('REPLENISH', $codes);
    }

    public function test_record_drawer_event_validation_fails_with_invalid_reason_code(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->postJson("/api/pos/shifts/{$this->shift->id}/drawer-events", [
                'event_type' => 'cash_drop',
                'amount' => 100,
                'reason_code' => 'INVALID_REASON',
                'reason_notes' => 'Some notes',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Invalid or inactive cash drawer reason code.');
    }

    public function test_record_drawer_event_requires_manager_approval_if_reason_flagged(): void
    {
        // SKIM reason requires manager approval
        // Submit drop without manager credentials
        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->postJson("/api/pos/shifts/{$this->shift->id}/drawer-events", [
                'event_type' => 'cash_drop',
                'amount' => 100, // below normal limit of 5000
                'reason_code' => 'SKIM',
                'reason_notes' => 'Skimming drawer',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Unauthorized: this transaction requires manager approval.');

        // Submit with correct manager credentials
        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->postJson("/api/pos/shifts/{$this->shift->id}/drawer-events", [
                'event_type' => 'cash_drop',
                'amount' => 100,
                'reason_code' => 'SKIM',
                'reason_notes' => 'Skimming drawer',
                'manager_email' => 'admin@bmad.coffee',
                'manager_password' => 'password',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('cash_drawer_events', [
            'shift_id' => $this->shift->id,
            'event_type' => 'cash_drop',
            'amount' => '100.0000',
            'reason_code' => 'SKIM',
        ]);
    }

    public function test_record_drawer_event_blocks_self_approval(): void
    {
        // If the shift owner (cashier) attempts to self-approve with their own credentials
        // Let's make cashier a manager too to test security block
        $this->givePermissionTo($this->cashier, 'manage_cash_drawer');

        $response = $this->actingAs($this->cashier)
            ->withHeaders([
                'X-Tenant-ID' => $this->tenant->id,
                'X-Branch-ID' => $this->branch->id,
            ])
            ->postJson("/api/pos/shifts/{$this->shift->id}/drawer-events", [
                'event_type' => 'cash_drop',
                'amount' => 100,
                'reason_code' => 'SKIM',
                'reason_notes' => 'Skim',
                'manager_email' => 'cashier@bmad.coffee',
                'manager_password' => 'password',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Security Block: Cashiers cannot approve their own manager-required drawer event.');
    }
}
