<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\PrinterProfile;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\POS\OfflineReadiness\CacheBootstrapService;
use App\Services\TenantContext;
use App\Services\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrinterProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch1;
    protected Branch $branch2;
    protected User $admin;
    protected User $branchManager;
    protected User $cashier;
    protected SalesMachineProfile $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::factory()->create([
            'status' => 'active',
        ]);

        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch1 = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch A',
            'status' => 'active',
        ]);

        $this->branch2 = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Branch B',
            'status' => 'active',
        ]);

        $this->terminal = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'profile_code' => 'TERM-A',
            'terminal_identifier' => 'TERM-A-IDENT',
            'status' => 'active',
            'offline_sales_enabled' => true,
            'offline_sequence_prefix' => 'TERMA-',
            'offline_sequence_next_value' => 1,
            'offline_sequence_status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->admin->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());
        $this->admin->assignToBranch($this->branch1);

        $this->branchManager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->branchManager->assignRole(Role::where('name', 'Branch Manager')->firstOrFail());
        $this->branchManager->assignToBranch($this->branch1);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $this->cashier->assignToBranch($this->branch1);
    }

    /** @test */
    public function test_admin_can_perform_printer_profiles_crud(): void
    {
        $this->actingAs($this->admin);

        // 1. Create Profile
        $response = $this->post(route('admin.printer-profiles.store'), [
            'branch_id' => $this->branch1->id,
            'name' => 'Receipt Printer A',
            'connection_type' => 'network',
            'identifier' => '192.168.1.10',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => true,
        ]);

        $response->assertStatus(302);

        $this->assertDatabaseHas('printer_profiles', [
            'name' => 'Receipt Printer A',
            'is_default' => true,
        ]);

        $printer = PrinterProfile::where('name', 'Receipt Printer A')->firstOrFail();

        // 2. Edit View
        $response = $this->get(route('admin.printer-profiles.index'));
        $response->assertStatus(200);

        // 3. Update
        $response = $this->put(route('admin.printer-profiles.update', $printer->id), [
            'branch_id' => $this->branch1->id,
            'name' => 'Receipt Printer Updated',
            'connection_type' => 'network',
            'identifier' => '192.168.1.11',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => true,
        ]);

        $response->assertStatus(302);
        $this->assertDatabaseHas('printer_profiles', [
            'id' => $printer->id,
            'name' => 'Receipt Printer Updated',
            'identifier' => '192.168.1.11',
        ]);

        // 4. Deactivate (Destroy)
        $response = $this->delete(route('admin.printer-profiles.destroy', $printer->id));
        $response->assertStatus(302);

        $printer->refresh();
        $this->assertFalse($printer->is_active);
        $this->assertFalse($printer->is_default);
    }

    /** @test */
    public function test_branch_manager_can_only_manage_own_branch_profiles(): void
    {
        $this->actingAs($this->branchManager);

        // Can manage own branch printer profile
        $response = $this->post(route('admin.printer-profiles.store'), [
            'branch_id' => $this->branch1->id,
            'name' => 'Receipt A',
            'connection_type' => 'network',
            'identifier' => '192.168.1.5',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => false,
        ]);
        $response->assertStatus(302);

        // Cannot create for another branch
        $response = $this->post(route('admin.printer-profiles.store'), [
            'branch_id' => $this->branch2->id,
            'name' => 'Receipt B Mismatched',
            'connection_type' => 'network',
            'identifier' => '192.168.1.6',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => false,
        ]);
        $response->assertStatus(403);
    }

    /** @test */
    public function test_cannot_create_printer_profile_for_another_tenant(): void
    {
        $this->actingAs($this->admin);

        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id]);
        app(TenantContext::class)->setTenant($this->tenant);

        $response = $this->post(route('admin.printer-profiles.store'), [
            'branch_id' => $otherBranch->id,
            'name' => 'Cross Tenant Printer',
            'connection_type' => 'usb',
            'identifier' => 'usb0',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => false,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['branch_id']);
    }

    /** @test */
    public function test_cannot_assign_printer_profile_from_another_branch(): void
    {
        $this->actingAs($this->admin);

        // Create printer profile in Branch B
        $printer = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch2->id,
            'name' => 'Branch B Printer',
            'connection_type' => 'network',
            'identifier' => '10.0.0.1',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => false,
        ]);

        // Attempting to assign Branch B's printer to Branch A's terminal
        $response = $this->put(route('admin.sales-machine-profiles.update', $this->terminal->id), [
            'printer_profile_id' => $printer->id,
        ]);

        $response->assertSessionHasErrors(['printer_profile_id']);
    }

    /** @test */
    public function test_inactive_printer_profile_cannot_be_assigned_to_a_terminal(): void
    {
        $this->actingAs($this->admin);

        $printer = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Inactive Printer',
            'connection_type' => 'network',
            'identifier' => '10.0.0.1',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => false,
            'is_default' => false,
        ]);

        $response = $this->put(route('admin.sales-machine-profiles.update', $this->terminal->id), [
            'printer_profile_id' => $printer->id,
        ]);

        $response->assertSessionHasErrors(['printer_profile_id']);
    }

    /** @test */
    public function test_terminal_with_assigned_printer_uses_terminal_override(): void
    {
        $printerOverride = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Override Printer',
            'connection_type' => 'network',
            'identifier' => '192.168.1.99',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => false,
        ]);

        $branchDefault = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Branch Default Printer',
            'connection_type' => 'network',
            'identifier' => '192.168.1.100',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->terminal->update(['printer_profile_id' => $printerOverride->id]);

        $bootstrapService = app(CacheBootstrapService::class);
        [$resolvedPrinter, $source] = $bootstrapService->resolvePrinterProfile(
            $this->tenant->id,
            $this->branch1->id,
            $this->terminal->id
        );

        $this->assertSame($printerOverride->id, $resolvedPrinter->id);
        $this->assertSame('terminal_override', $source);
    }

    /** @test */
    public function test_terminal_without_assigned_printer_uses_branch_default(): void
    {
        $branchDefault = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Branch Default Printer',
            'connection_type' => 'network',
            'identifier' => '192.168.1.100',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->terminal->update(['printer_profile_id' => null]);

        $bootstrapService = app(CacheBootstrapService::class);
        [$resolvedPrinter, $source] = $bootstrapService->resolvePrinterProfile(
            $this->tenant->id,
            $this->branch1->id,
            $this->terminal->id
        );

        $this->assertSame($branchDefault->id, $resolvedPrinter->id);
        $this->assertSame('branch_default', $source);
    }

    /** @test */
    public function test_terminal_with_no_available_printer_returns_null_printer_payload_and_stable_hash(): void
    {
        $this->terminal->update(['printer_profile_id' => null]);

        $bootstrapService = app(CacheBootstrapService::class);
        [$resolvedPrinter, $source] = $bootstrapService->resolvePrinterProfile(
            $this->tenant->id,
            $this->branch1->id,
            $this->terminal->id
        );

        $this->assertNull($resolvedPrinter);
        $this->assertSame('none', $source);

        $hash = $bootstrapService->calculatePrinterProfileVersionHash(
            $this->tenant->id,
            $this->branch1->id,
            $this->terminal->id
        );
        $this->assertSame('no-printer-profile', $hash);
    }

    /** @test */
    public function test_printer_profile_hash_changes_when_identifier_changes(): void
    {
        $printer = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Test Hash Printer',
            'connection_type' => 'network',
            'identifier' => '192.168.1.50',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => true,
        ]);

        $bootstrapService = app(CacheBootstrapService::class);

        $hash1 = $bootstrapService->calculatePrinterProfileVersionHash(
            $this->tenant->id,
            $this->branch1->id,
            $this->terminal->id
        );

        // Update identifier
        $printer->update(['identifier' => '192.168.1.51']);

        $hash2 = $bootstrapService->calculatePrinterProfileVersionHash(
            $this->tenant->id,
            $this->branch1->id,
            $this->terminal->id
        );

        $this->assertNotSame($hash1, $hash2);
    }

    /** @test */
    public function test_printer_profile_hash_changes_when_terminal_assignment_changes(): void
    {
        $printerDefault = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Default Printer',
            'connection_type' => 'network',
            'identifier' => '192.168.1.100',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => true,
        ]);

        $printerOverride = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Override Printer',
            'connection_type' => 'network',
            'identifier' => '192.168.1.200',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => false,
        ]);

        $bootstrapService = app(CacheBootstrapService::class);

        // Scenario 1: Using branch default
        $this->terminal->update(['printer_profile_id' => null]);
        $hash1 = $bootstrapService->calculatePrinterProfileVersionHash(
            $this->tenant->id,
            $this->branch1->id,
            $this->terminal->id
        );

        // Scenario 2: Assigned override
        $this->terminal->update(['printer_profile_id' => $printerOverride->id]);
        $hash2 = $bootstrapService->calculatePrinterProfileVersionHash(
            $this->tenant->id,
            $this->branch1->id,
            $this->terminal->id
        );

        $this->assertNotSame($hash1, $hash2);
    }

    /** @test */
    public function test_user_without_manage_permission_receives_403(): void
    {
        $this->actingAs($this->cashier);

        $response = $this->get(route('admin.printer-profiles.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function test_cross_tenant_profile_route_binding_does_not_expose_the_profile(): void
    {
        $otherTenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherPrinter = PrinterProfile::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'name' => 'Other Tenant Printer',
            'connection_type' => 'usb',
            'identifier' => 'usb0',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => false,
        ]);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->actingAs($this->admin)
            ->put(route('admin.printer-profiles.update', $otherPrinter->id), [
                'branch_id' => $this->branch1->id,
                'name' => 'Leaked Printer',
                'connection_type' => 'usb',
                'identifier' => 'usb1',
                'paper_width' => '80mm',
                'role' => 'receipt',
                'template_type' => 'standard',
                'is_active' => true,
                'is_default' => false,
            ])
            ->assertNotFound();
    }

    /** @test */
    public function test_branch_manager_cannot_mutate_a_terminal_outside_their_branches(): void
    {
        $otherTerminal = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch2->id,
            'profile_code' => 'TERM-B',
            'terminal_identifier' => 'TERM-B-IDENT',
            'status' => 'active',
        ]);

        $this->actingAs($this->branchManager)
            ->put(route('admin.sales-machine-profiles.update', $otherTerminal->id), [
                'printer_profile_id' => null,
            ])
            ->assertForbidden();
    }

    /** @test */
    public function test_setting_a_new_receipt_default_clears_the_previous_default(): void
    {
        $first = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'First Default',
            'connection_type' => 'network',
            'identifier' => '192.168.1.20',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.printer-profiles.store'), [
                'branch_id' => $this->branch1->id,
                'name' => 'Second Default',
                'connection_type' => 'network',
                'identifier' => '192.168.1.21',
                'paper_width' => '80mm',
                'role' => 'receipt',
                'template_type' => 'standard',
                'is_active' => true,
                'is_default' => true,
            ])
            ->assertRedirect();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(1, PrinterProfile::where('branch_id', $this->branch1->id)
            ->where('role', 'receipt')
            ->where('is_default', true)
            ->count());
    }

    /** @test */
    public function test_inactive_or_non_receipt_profile_cannot_be_a_default(): void
    {
        $base = [
            'branch_id' => $this->branch1->id,
            'name' => 'Invalid Default',
            'connection_type' => 'network',
            'identifier' => '192.168.1.30',
            'paper_width' => '80mm',
            'template_type' => 'standard',
            'is_default' => true,
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.printer-profiles.store'), $base + [
                'role' => 'receipt',
                'is_active' => false,
            ])
            ->assertSessionHasErrors('is_default');

        $this->post(route('admin.printer-profiles.store'), $base + [
            'role' => 'kitchen',
            'is_active' => true,
        ])->assertSessionHasErrors('is_default');

        $this->assertDatabaseMissing('printer_profiles', ['name' => 'Invalid Default']);
    }

    /** @test */
    public function test_non_receipt_profile_cannot_be_assigned_to_a_terminal(): void
    {
        $printer = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Kitchen Printer',
            'connection_type' => 'network',
            'identifier' => '192.168.1.40',
            'paper_width' => '80mm',
            'role' => 'kitchen',
            'template_type' => 'kitchen',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.sales-machine-profiles.update', $this->terminal->id), [
                'printer_profile_id' => $printer->id,
            ])
            ->assertSessionHasErrors('printer_profile_id');
    }

    /** @test */
    public function test_printer_assignment_requires_printer_management_permission(): void
    {
        $offlineOnlyRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Offline Settings Only',
            'description' => 'May edit offline settings but not printer assignments.',
        ]);
        $offlineOnlyRole->permissions()->attach(
            Permission::where('name', 'manage_offline_sales_settings')->firstOrFail()
        );
        $this->cashier->assignRole($offlineOnlyRole);

        $this->actingAs($this->cashier)
            ->put(route('admin.sales-machine-profiles.update', $this->terminal->id), [
                'printer_profile_id' => null,
            ])
            ->assertForbidden();
    }

    /** @test */
    public function test_assigned_printer_cannot_move_branches_or_change_role(): void
    {
        $printer = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Assigned Receipt',
            'connection_type' => 'network',
            'identifier' => '192.168.1.45',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => false,
        ]);
        $this->terminal->update(['printer_profile_id' => $printer->id]);

        $payload = [
            'branch_id' => $this->branch2->id,
            'name' => $printer->name,
            'connection_type' => $printer->connection_type,
            'identifier' => $printer->identifier,
            'paper_width' => $printer->paper_width,
            'role' => 'receipt',
            'template_type' => $printer->template_type,
            'is_active' => true,
            'is_default' => false,
        ];

        $this->actingAs($this->admin)
            ->put(route('admin.printer-profiles.update', $printer->id), $payload)
            ->assertSessionHasErrors('branch_id');

        $payload['branch_id'] = $this->branch1->id;
        $payload['role'] = 'kitchen';
        $this->put(route('admin.printer-profiles.update', $printer->id), $payload)
            ->assertSessionHasErrors('role');

        $this->assertSame($this->branch1->id, $printer->fresh()->branch_id);
        $this->assertSame('receipt', $printer->fresh()->role);
    }

    /** @test */
    public function test_legacy_non_receipt_override_is_ignored_by_bootstrap(): void
    {
        $printer = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Legacy Kitchen Profile',
            'connection_type' => 'network',
            'identifier' => '192.168.1.46',
            'paper_width' => '80mm',
            'role' => 'kitchen',
            'template_type' => 'kitchen',
            'is_active' => true,
            'is_default' => false,
        ]);
        $this->terminal->update(['printer_profile_id' => $printer->id]);

        [$resolvedPrinter, $source] = app(CacheBootstrapService::class)->resolvePrinterProfile(
            $this->tenant->id,
            $this->branch1->id,
            $this->terminal->id
        );

        $this->assertNull($resolvedPrinter);
        $this->assertSame('none', $source);
    }

    /** @test */
    public function test_deactivating_assigned_override_falls_back_and_updates_snapshot_hashes(): void
    {
        $default = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Default Receipt',
            'connection_type' => 'network',
            'identifier' => '192.168.1.50',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => true,
        ]);
        $override = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Terminal Receipt',
            'connection_type' => 'network',
            'identifier' => '192.168.1.51',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => true,
            'is_default' => false,
        ]);
        $this->terminal->update(['printer_profile_id' => $override->id]);

        $service = app(CacheBootstrapService::class);
        $before = $service->generatePayload($this->tenant, $this->branch1, $this->admin, $this->terminal->fresh());
        $repeat = $service->generatePayload($this->tenant, $this->branch1, $this->admin, $this->terminal->fresh());

        $this->assertSame('terminal_override', $before['printer_profile']['resolution_source']);
        $this->assertSame($before['printer_profile_version_hash'], $repeat['printer_profile_version_hash']);
        $this->assertSame($before['config_snapshot_hash'], $repeat['config_snapshot_hash']);

        $this->actingAs($this->admin)
            ->delete(route('admin.printer-profiles.destroy', $override->id))
            ->assertRedirect();

        $after = $service->generatePayload($this->tenant, $this->branch1, $this->admin, $this->terminal->fresh());

        $this->assertSame($default->id, $after['printer_profile']['id']);
        $this->assertSame('branch_default', $after['printer_profile']['resolution_source']);
        $this->assertNotSame($before['printer_profile_version_hash'], $after['printer_profile_version_hash']);
        $this->assertNotSame($before['config_snapshot_hash'], $after['config_snapshot_hash']);
        $this->assertSame($after['printer_profile_version_hash'], $after['config_snapshot']['printer_profile_version_hash']);
        $this->assertSame($after['config_snapshot_hash'], $after['config_snapshot']['config_snapshot_hash']);
    }

    /** @test */
    public function test_deactivated_printer_profile_is_excluded_from_bootstrap_fallback(): void
    {
        // Branch default deactivated
        $branchDefault = PrinterProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch1->id,
            'name' => 'Deactivated Default Printer',
            'connection_type' => 'network',
            'identifier' => '192.168.1.100',
            'paper_width' => '80mm',
            'role' => 'receipt',
            'template_type' => 'standard',
            'is_active' => false,
            'is_default' => true,
        ]);

        $this->terminal->update(['printer_profile_id' => null]);

        $bootstrapService = app(CacheBootstrapService::class);
        [$resolvedPrinter, $source] = $bootstrapService->resolvePrinterProfile(
            $this->tenant->id,
            $this->branch1->id,
            $this->terminal->id
        );

        $this->assertNull($resolvedPrinter);
        $this->assertSame('none', $source);
    }
}
