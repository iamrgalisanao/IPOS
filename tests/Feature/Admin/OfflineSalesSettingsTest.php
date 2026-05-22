<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Story 28.5: Epic 28 Phase 2 Slice A — Settings, Terminal Sequence Registry, and Admin Controls
 *
 * Test Matrix:
 *   TC-28.5-01  Migration columns and defaults
 *   TC-28.5-02  Cascading: Tenant disabled blocks all terminals
 *   TC-28.5-03  Cascading: Branch disabled blocks child terminals
 *   TC-28.5-04  Cascading: Terminal override disabled
 *   TC-28.5-05  Duplicate prefix rejected
 *   TC-28.5-06  Null prefix allowed but offline blocked
 *   TC-28.5-07  Cashier RBAC — 403 on update
 *   TC-28.5-08  Model guard: sequence next value cannot decrease
 */
class OfflineSalesSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $owner;
    protected User $cashier;
    protected SalesMachineProfile $profile;

    /**
     * Minimal reusable SalesMachineProfile attributes for tests.
     */
    private function machineProfileAttributes(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

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

        $this->owner = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->owner->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->owner->assignToBranch($this->branch);

        $this->cashier = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branch);

        $this->profile = SalesMachineProfile::create($this->machineProfileAttributes([
            'offline_sequence_prefix'     => 'INV-T01-',
            'offline_sequence_next_value' => 1,
            'offline_sequence_status'     => 'active',
        ]));

        // Leave context set for tests
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // TC-28.5-01: Migration columns and defaults
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_5_01_tenant_offline_sales_enabled_defaults_to_true(): void
    {
        // Tenant was already created in setUp with tenant context active
        $fresh = Tenant::find($this->tenant->id);
        $this->assertTrue((bool) $fresh->offline_sales_enabled);
    }

    /** @test */
    public function test_tc_28_5_01_branch_offline_sales_enabled_defaults_to_true(): void
    {
        $fresh = Branch::find($this->branch->id);
        $this->assertTrue((bool) $fresh->offline_sales_enabled);
    }

    /** @test */
    public function test_tc_28_5_01_sales_machine_profile_sequence_fields_have_correct_defaults(): void
    {
        // Create a new profile without explicit offline fields to test DB defaults
        $profile = SalesMachineProfile::create($this->machineProfileAttributes([
            'profile_code' => 'NEW-POS',
        ]));

        // Refresh from DB to pick up DB-level defaults
        $fresh = SalesMachineProfile::find($profile->id);

        $this->assertNull($fresh->offline_sales_enabled);
        $this->assertNull($fresh->offline_sequence_prefix);
        // offline_sequence_next_value defaults to 1 at DB level
        $this->assertEquals(1, (int) $fresh->offline_sequence_next_value);
        $this->assertEquals('active', $fresh->offline_sequence_status);
        $this->assertNull($fresh->last_offline_sync_at);
    }

    // -------------------------------------------------------------------------
    // TC-28.5-02: Cascading — Tenant disabled blocks all terminals
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_5_02_tenant_disabled_blocks_all_terminals(): void
    {
        $this->tenant->update(['offline_sales_enabled' => false]);
        $this->branch->update(['offline_sales_enabled' => true]);
        $this->profile->update(['offline_sales_enabled' => null]); // inherits

        $validator = app(\App\Services\POS\OfflineReadiness\OfflineSettingsValidator::class);
        $result    = $validator->validate(
            $this->tenant->fresh(),
            $this->branch->fresh(),
            $this->profile->fresh()
        );

        $this->assertFalse($result['allowed']);
        $this->assertEquals('tenant_disabled', $result['reason']);
    }

    // -------------------------------------------------------------------------
    // TC-28.5-03: Cascading — Branch disabled blocks child terminals
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_5_03_branch_disabled_blocks_terminals_within_it(): void
    {
        $this->tenant->update(['offline_sales_enabled' => true]);
        $this->branch->update(['offline_sales_enabled' => false]);
        $this->profile->update(['offline_sales_enabled' => null]); // inherits

        // Sibling branch — separate, should remain allowed
        $siblingBranch = Branch::factory()->create([
            'tenant_id'             => $this->tenant->id,
            'status'                => 'active',
            'offline_sales_enabled' => true,
        ]);

        $siblingProfile = SalesMachineProfile::create($this->machineProfileAttributes([
            'branch_id'                  => $siblingBranch->id,
            'profile_code'               => 'SIBLING-POS',
            'offline_sequence_prefix'    => 'INV-T02-',
        ]));

        $validator = app(\App\Services\POS\OfflineReadiness\OfflineSettingsValidator::class);

        // Disabled branch terminal — blocked
        $blockedResult = $validator->validate(
            $this->tenant->fresh(),
            $this->branch->fresh(),
            $this->profile->fresh()
        );
        $this->assertFalse($blockedResult['allowed']);
        $this->assertEquals('branch_disabled', $blockedResult['reason']);

        // Sibling terminal — still allowed
        $allowedResult = $validator->validate(
            $this->tenant->fresh(),
            $siblingBranch->fresh(),
            $siblingProfile->fresh()
        );
        $this->assertTrue($allowedResult['allowed']);
    }

    // -------------------------------------------------------------------------
    // TC-28.5-04: Cascading — Terminal override disabled
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_5_04_terminal_explicit_false_blocks_only_that_terminal(): void
    {
        $this->tenant->update(['offline_sales_enabled' => true]);
        $this->branch->update(['offline_sales_enabled' => true]);
        $this->profile->update(['offline_sales_enabled' => false]); // explicit override

        $sibling = SalesMachineProfile::create($this->machineProfileAttributes([
            'profile_code'               => 'OTHER-POS',
            'offline_sequence_prefix'    => 'INV-T02-',
            'offline_sales_enabled'      => null, // inherits
        ]));

        $validator = app(\App\Services\POS\OfflineReadiness\OfflineSettingsValidator::class);

        $blockedResult = $validator->validate(
            $this->tenant->fresh(),
            $this->branch->fresh(),
            $this->profile->fresh()
        );
        $this->assertFalse($blockedResult['allowed']);
        $this->assertEquals('terminal_disabled', $blockedResult['reason']);

        $allowedResult = $validator->validate(
            $this->tenant->fresh(),
            $this->branch->fresh(),
            $sibling->fresh()
        );
        $this->assertTrue($allowedResult['allowed']);
    }

    // -------------------------------------------------------------------------
    // TC-28.5-05: Duplicate prefix rejected
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_5_05_duplicate_prefix_in_same_tenant_is_rejected(): void
    {
        // profile already holds 'INV-T01-' prefix
        $secondProfile = SalesMachineProfile::create($this->machineProfileAttributes([
            'profile_code'            => 'POS-02',
            'offline_sequence_prefix' => null,
        ]));

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->put(route('admin.sales-machine-profiles.update', $secondProfile->id), [
                'offline_sequence_prefix' => 'INV-T01-', // duplicate
            ]);

        $response->assertSessionHasErrors(['offline_sequence_prefix']);
        $this->assertNull($secondProfile->fresh()->offline_sequence_prefix);
    }

    /** @test */
    public function test_tc_28_5_05_unique_prefix_is_accepted(): void
    {
        $secondProfile = SalesMachineProfile::create($this->machineProfileAttributes([
            'profile_code'            => 'POS-02',
            'offline_sequence_prefix' => null,
        ]));

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->put(route('admin.sales-machine-profiles.update', $secondProfile->id), [
                'offline_sequence_prefix' => 'INV-T02-', // unique
            ]);

        $response->assertRedirect();
        $this->assertEquals('INV-T02-', $secondProfile->fresh()->offline_sequence_prefix);
    }

    // -------------------------------------------------------------------------
    // TC-28.5-06: Null prefix allowed but offline blocked
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_5_06_null_prefix_saves_but_offline_check_is_false(): void
    {
        $this->profile->update(['offline_sequence_prefix' => null]);

        $validator = app(\App\Services\POS\OfflineReadiness\OfflineSettingsValidator::class);
        $result    = $validator->validate(
            $this->tenant->fresh(),
            $this->branch->fresh(),
            $this->profile->fresh()
        );

        $this->assertFalse($result['allowed']);
        $this->assertEquals('missing_prefix', $result['reason']);
    }

    // -------------------------------------------------------------------------
    // TC-28.5-07: Cashier RBAC — 403 Forbidden on update
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_5_07_cashier_cannot_update_offline_settings(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->put(route('admin.sales-machine-profiles.update', $this->profile->id), [
                'offline_sales_enabled'   => false,
                'offline_sequence_prefix' => 'HACKED-',
            ]);

        $response->assertForbidden();

        // DB must be unchanged
        $this->assertEquals('INV-T01-', $this->profile->fresh()->offline_sequence_prefix);
        $this->assertNull($this->profile->fresh()->offline_sales_enabled); // original was null
    }

    /** @test */
    public function test_tc_28_5_07_cashier_cannot_list_terminals(): void
    {
        $response = $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('admin.sales-machine-profiles.index'));

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // TC-28.5-08: Model guard — sequence next value cannot decrease
    // -------------------------------------------------------------------------

    /** @test */
    public function test_tc_28_5_08_model_rejects_decrement_of_offline_sequence_next_value(): void
    {
        $this->profile->update(['offline_sequence_next_value' => 50]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('offline_sequence_next_value cannot be decreased.');

        $this->profile->update(['offline_sequence_next_value' => 49]);
    }

    /** @test */
    public function test_tc_28_5_08_controller_rejects_decrement_of_sequence_next_value(): void
    {
        $this->profile->update(['offline_sequence_next_value' => 50]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->put(route('admin.sales-machine-profiles.update', $this->profile->id), [
                'offline_sequence_next_value' => 10, // lower than current 50
            ]);

        $response->assertSessionHasErrors(['offline_sequence_next_value']);
        $this->assertEquals(50, $this->profile->fresh()->offline_sequence_next_value);
    }

    /** @test */
    public function test_tc_28_5_08_increment_of_sequence_next_value_is_allowed(): void
    {
        $this->profile->update(['offline_sequence_next_value' => 50]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->put(route('admin.sales-machine-profiles.update', $this->profile->id), [
                'offline_sequence_next_value' => 100,
            ]);

        $response->assertRedirect();
        $this->assertEquals(100, $this->profile->fresh()->offline_sequence_next_value);
    }
}
