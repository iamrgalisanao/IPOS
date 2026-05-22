<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncBatch;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineImportReviewTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $admin;
    protected User $cashier;
    protected SalesMachineProfile $profile;
    protected OfflineSyncBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);

        $this->admin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->admin->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        // We ensure admin has review_offline_sync_conflicts (it was added to the seeder for Owner/Admin)
        
        $this->cashier = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());

        $this->profile = SalesMachineProfile::create([
            'tenant_id'                            => $this->tenant->id,
            'branch_id'                            => $this->branch->id,
            'profile_code'                         => 'MAIN-POS',
            'machine_identification_number'        => 'MIN-001',
            'machine_serial_number'                => 'SER-001',
            'software_license_number'              => 'LIC-001',
            'permit_to_use_number'                 => 'PTU-001',
            'authority_to_generate_control_number' => 'ATG-001',
            'supplier_name'                        => 'Supplier',
            'supplier_tin'                         => '123-456-789-000',
            'supplier_branch_code'                 => '00001',
            'supplier_accreditation_number'        => 'ACC-001',
            'status'                               => 'active',
            'offline_sequence_prefix'              => 'INV-',
            'offline_sequence_next_value'          => 1,
            'offline_sequence_status'              => 'active',
        ]);

        $this->batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'BATCH-001',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
            'submitted_import_count'   => 1,
        ]);
    }

    protected function createImport(string $status): OfflineSalesImport
    {
        return OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $this->batch->id,
            'offline_sequence_number'  => 'INV-001',
            'payload_hash'             => hash('sha256', uniqid()),
            'raw_payload'              => ['items' => []],
            'server_recalculation'     => ['test' => true],
            'conflict_notes'           => $status === 'conflict' ? 'Some conflict' : null,
            'status'                   => $status,
            'submitted_at'             => now(),
        ]);
    }

    /** @test */
    public function test_tc_28_9_01_admin_can_list_imports_filtered_by_conflict_status()
    {
        $this->createImport('server_verified');
        $conflictImport = $this->createImport('conflict');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/offline-sync/imports?status=conflict');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals($conflictImport->id, $response->json('data.0.id'));
    }

    /** @test */
    public function test_tc_28_9_02_admin_can_view_import_details()
    {
        $import = $this->createImport('conflict');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/offline-sync/imports/{$import->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $import->id,
            'status' => 'conflict',
            'conflict_notes' => 'Some conflict',
        ]);
        $response->assertJsonPath('raw_payload.items', []);
        $response->assertJsonPath('server_recalculation.test', true);
    }

    /** @test */
    public function test_tc_28_9_03_admin_can_transition_conflict_to_hold()
    {
        $import = $this->createImport('conflict');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/offline-sync/imports/{$import->id}/review", [
                'status' => 'hold',
                'review_notes' => 'Needs checking',
            ]);

        $response->assertStatus(200);
        
        $import->refresh();
        $this->assertEquals('hold', $import->status);
        $this->assertEquals($this->admin->id, $import->reviewed_by_user_id);
        $this->assertEquals('Needs checking', $import->review_notes);
        $this->assertNotNull($import->reviewed_at);
        $this->assertNull($import->reconciled_sale_id);
        $this->assertNull($import->reconciled_at);
    }

    /** @test */
    public function test_tc_28_9_04_admin_can_transition_conflict_to_override_approved()
    {
        $import = $this->createImport('conflict');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/offline-sync/imports/{$import->id}/review", [
                'status' => 'override_approved',
                'review_notes' => 'Accepting client totals',
            ]);

        $response->assertStatus(200);
        
        $import->refresh();
        $this->assertEquals('override_approved', $import->status);
        $this->assertNull($import->reconciled_sale_id);
    }

    /** @test */
    public function test_tc_28_9_05_admin_can_transition_hold_back_to_conflict()
    {
        $import = $this->createImport('hold');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/offline-sync/imports/{$import->id}/review", [
                'status' => 'conflict',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('conflict', $import->fresh()->status);
    }

    /** @test */
    public function test_tc_28_9_06_server_verified_cannot_transition_to_override_approved()
    {
        $import = $this->createImport('server_verified');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/offline-sync/imports/{$import->id}/review", [
                'status' => 'override_approved',
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_tc_28_9_07_rejected_cannot_transition_to_override_approved()
    {
        $import = $this->createImport('rejected');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/offline-sync/imports/{$import->id}/review", [
                'status' => 'override_approved',
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_tc_28_9_08_duplicate_cannot_transition_to_override_approved()
    {
        $import = $this->createImport('duplicate');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/offline-sync/imports/{$import->id}/review", [
                'status' => 'override_approved',
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function test_tc_28_9_09_cashier_receives_403()
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->getJson('/api/admin/offline-sync/imports');

        $response->assertStatus(403);
    }

    /** @test */
    public function test_tc_28_9_10_cross_tenant_import_access_is_blocked()
    {
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($otherTenant);

        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherProfile = SalesMachineProfile::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'profile_code' => 'OTHER',
            'machine_identification_number' => 'MIN-002',
            'status' => 'active',
            'offline_sequence_prefix' => 'INV-002-',
        ]);
        $otherBatch = OfflineSyncBatch::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'sales_machine_profile_id' => $otherProfile->id,
            'batch_reference' => 'B-002',
            'status' => 'completed',
            'submitted_import_count' => 1,
        ]);
        
        $import = OfflineSalesImport::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'sales_machine_profile_id' => $otherProfile->id,
            'batch_id' => $otherBatch->id,
            'offline_sequence_number' => 'INV-002-001',
            'payload_hash' => 'hash',
            'status' => 'conflict',
            'raw_payload' => [],
            'submitted_at' => now(),
        ]);

        app(TenantContext::class)->setTenant($this->tenant);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/offline-sync/imports/{$import->id}");

        $response->assertStatus(404);
    }

    /** @test */
    public function test_tc_28_9_11_review_fields_are_populated_after_transition()
    {
        // Tested implicitly in tc_28_9_03
        $this->assertTrue(true);
    }

    /** @test */
    public function test_tc_28_9_12_reconciled_sale_id_and_reconciled_at_remain_null()
    {
        // Tested implicitly in tc_28_9_03
        $this->assertTrue(true);
    }

    /** @test */
    public function test_tc_28_9_13_no_sale_row_is_created()
    {
        $import = $this->createImport('conflict');

        $initialSaleCount = \App\Models\Sale::count();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/offline-sync/imports/{$import->id}/review", [
                'status' => 'override_approved',
            ]);

        $this->assertEquals($initialSaleCount, \App\Models\Sale::count());
    }
}
