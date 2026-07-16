<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\InventoryAdjustmentApprovalRule;
use App\Models\InventoryAdjustmentReason;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\Inventory\InventoryAdjustmentApprovalService;
use App\Services\Inventory\InventoryAdjustmentReasonService;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\InventoryService;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryAdjustmentGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $manager;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        app(BranchContext::class)->setBranch($this->branch);

        (new RbacSeeder())->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $managerRole = Role::where('tenant_id', $this->tenant->id)->where('name', 'Branch Manager')->firstOrFail();
        $ownerRole = Role::where('tenant_id', $this->tenant->id)->where('name', 'Owner/Admin')->firstOrFail();

        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'password' => Hash::make('Secret-1234'),
            'status' => 'active',
            'actor_type' => 'tenant_user',
        ]);
        $this->manager->assignRole($managerRole);
        $this->manager->branches()->attach($this->branch);

        $this->approver = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'inventory-approver@example.test',
            'password' => Hash::make('Secret-1234'),
            'status' => 'active',
            'actor_type' => 'tenant_user',
        ]);
        $this->approver->assignRole($ownerRole);
        $this->approver->branches()->attach($this->branch);
    }

    public function test_direction_policy_and_negative_stock_are_enforced(): void
    {
        $inventory = $this->inventory(10);
        $reason = $this->reason('DAMAGED', InventoryAdjustmentReason::CATEGORY_DAMAGE, InventoryAdjustmentReason::DIRECTION_DECREASE);

        $this->actingAs($this->manager);

        $this->expectExceptionMessage('Selected reason only allows stock decreases.');
        app(InventoryAdjustmentService::class)->adjust([
            'branch_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'quantity_change' => 1,
            'reason_code' => $reason->code,
            'remarks' => 'Wrong direction.',
            'client_request_uuid' => (string) Str::orderedUuid(),
        ]);
    }

    public function test_manual_adjustment_cannot_create_negative_stock(): void
    {
        $inventory = $this->inventory(2);
        $reason = $this->reason('SHRINKAGE', InventoryAdjustmentReason::CATEGORY_SHRINKAGE, InventoryAdjustmentReason::DIRECTION_DECREASE);

        $this->actingAs($this->manager);

        $this->expectExceptionMessage('negative inventory');
        app(InventoryAdjustmentService::class)->adjust([
            'branch_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'quantity_change' => -3,
            'reason_code' => $reason->code,
            'remarks' => 'Too much shrinkage.',
            'client_request_uuid' => (string) Str::orderedUuid(),
        ]);
    }

    public function test_reserved_workflow_category_is_rejected(): void
    {
        $this->actingAs($this->manager);

        $this->expectException(ValidationException::class);
        app(InventoryAdjustmentReasonService::class)->create([
            'code' => 'SUPPLIER_RETURN',
            'name' => 'Supplier Return',
            'reason_category' => 'supplier_return',
            'direction_policy' => InventoryAdjustmentReason::DIRECTION_DECREASE,
            'requires_notes' => true,
            'evidence_required' => false,
            'is_opening_balance' => false,
            'is_active' => true,
        ]);
    }

    public function test_high_risk_adjustment_requires_and_consumes_context_bound_approval(): void
    {
        $inventory = $this->inventory(100);
        $reason = $this->reason('LARGE_LOSS', InventoryAdjustmentReason::CATEGORY_THEFT_OR_LOSS, InventoryAdjustmentReason::DIRECTION_DECREASE);
        InventoryAdjustmentApprovalRule::create([
            'tenant_id' => $this->tenant->id,
            'reason_id' => $reason->id,
            'minimum_percentage_of_stock' => 20,
            'required_permission' => 'inventory.adjustment.approve',
            'requires_distinct_approver' => true,
            'is_active' => true,
        ]);

        $command = [
            'branch_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'quantity_change' => -30,
            'reason_code' => $reason->code,
            'remarks' => 'Incident report pending.',
            'client_request_uuid' => (string) Str::orderedUuid(),
        ];

        $this->actingAs($this->manager);
        $this->expectExceptionMessage('Manager approval is required');
        app(InventoryAdjustmentService::class)->adjust($command);
    }

    public function test_valid_manager_approval_allows_posting(): void
    {
        $inventory = $this->inventory(100);
        $reason = $this->reason('THEFT_LOSS', InventoryAdjustmentReason::CATEGORY_THEFT_OR_LOSS, InventoryAdjustmentReason::DIRECTION_DECREASE);
        InventoryAdjustmentApprovalRule::create([
            'tenant_id' => $this->tenant->id,
            'reason_id' => $reason->id,
            'minimum_percentage_of_stock' => 20,
            'required_permission' => 'inventory.adjustment.approve',
            'is_active' => true,
        ]);

        $command = [
            'branch_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'quantity_change' => -30,
            'reason_code' => $reason->code,
            'remarks' => 'Documented loss.',
            'client_request_uuid' => (string) Str::orderedUuid(),
        ];

        $this->actingAs($this->manager);
        $approval = app(InventoryAdjustmentApprovalService::class)->issue(
            $this->manager,
            $inventory,
            $reason,
            $command,
            $this->approver->email,
            'Secret-1234'
        );

        $result = app(InventoryAdjustmentService::class)->adjust(array_merge($command, [
            'manager_approval_id' => $approval->id,
        ]));

        $this->assertSame('posted', $result['status']);
        $this->assertEquals(70.0000, (float) $inventory->fresh()->current_stock);
        $this->assertSame('consumed', $approval->fresh()->status);
    }

    public function test_policy_can_allow_same_user_approval_when_distinct_approver_is_not_required(): void
    {
        $inventory = $this->inventory(100);
        $reason = $this->reason('CONTROLLED_LOSS', InventoryAdjustmentReason::CATEGORY_THEFT_OR_LOSS, InventoryAdjustmentReason::DIRECTION_DECREASE);
        InventoryAdjustmentApprovalRule::create([
            'tenant_id' => $this->tenant->id,
            'reason_id' => $reason->id,
            'minimum_percentage_of_stock' => 20,
            'required_permission' => 'inventory.adjustment.approve',
            'requires_distinct_approver' => false,
            'is_active' => true,
        ]);
        $permission = Permission::where('name', 'inventory.adjustment.approve')->firstOrFail();
        $approvalRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inventory Adjustment Self Approver',
            'description' => 'Test role for same-user inventory adjustment approval.',
        ]);
        $approvalRole->permissions()->attach($permission);
        $this->manager->assignRole($approvalRole);

        $command = [
            'branch_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'quantity_change' => -30,
            'reason_code' => $reason->code,
            'remarks' => 'Self-approved under tenant policy.',
            'client_request_uuid' => (string) Str::orderedUuid(),
        ];

        $this->actingAs($this->manager);
        $approval = app(InventoryAdjustmentApprovalService::class)->issue(
            $this->manager,
            $inventory,
            $reason,
            $command,
            $this->manager->email,
            'Secret-1234'
        );

        $this->assertSame($this->manager->id, $approval->user_id);
    }

    public function test_exact_replay_survives_reason_deactivation(): void
    {
        $inventory = $this->inventory(10);
        $reason = $this->reason('FOUND_STOCK', InventoryAdjustmentReason::CATEGORY_FOUND_STOCK, InventoryAdjustmentReason::DIRECTION_INCREASE, requiresNotes: false);
        $command = [
            'branch_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'quantity_change' => 2,
            'reason_code' => $reason->code,
            'client_request_uuid' => (string) Str::orderedUuid(),
        ];

        $this->actingAs($this->manager);
        $posted = app(InventoryAdjustmentService::class)->adjust($command);
        $reason->update(['is_active' => false]);
        $replayed = app(InventoryAdjustmentService::class)->adjust($command);

        $this->assertSame('replayed', $replayed['status']);
        $this->assertEquals($posted['movement']->id, $replayed['movement']->id);
        $this->assertEquals(12.0000, (float) $inventory->fresh()->current_stock);
        $this->assertEquals(1, InventoryMovement::where('source_id', $command['client_request_uuid'])->count());
    }

    public function test_opening_balance_exact_replay_returns_original_movement(): void
    {
        $inventory = $this->inventory(0);
        $reason = $this->reason('OPENING_BALANCE', InventoryAdjustmentReason::CATEGORY_OPENING_BALANCE, InventoryAdjustmentReason::DIRECTION_OPENING_BALANCE, requiresNotes: false);
        $command = [
            'branch_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'quantity_change' => 15,
            'reason_code' => $reason->code,
            'client_request_uuid' => (string) Str::orderedUuid(),
        ];

        $this->actingAs($this->manager);
        $posted = app(InventoryAdjustmentService::class)->adjust($command);
        $replayed = app(InventoryAdjustmentService::class)->adjust($command);

        $this->assertSame('replayed', $replayed['status']);
        $this->assertSame('inventory_opening_balance', $posted['movement']->movement_type);
        $this->assertEquals($posted['movement']->id, $replayed['movement']->id);
        $this->assertEquals(15.0000, (float) $inventory->fresh()->current_stock);
        $this->assertEquals(1, InventoryMovement::where('source_id', $command['client_request_uuid'])->count());
    }

    public function test_replay_with_changed_reason_code_is_rejected_as_drift(): void
    {
        $inventory = $this->inventory(10);
        $firstReason = $this->reason('FOUND_STOCK', InventoryAdjustmentReason::CATEGORY_FOUND_STOCK, InventoryAdjustmentReason::DIRECTION_INCREASE, requiresNotes: false);
        $this->reason('COUNT_FOUND', InventoryAdjustmentReason::CATEGORY_FOUND_STOCK, InventoryAdjustmentReason::DIRECTION_INCREASE, requiresNotes: false);
        $command = [
            'branch_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'quantity_change' => 2,
            'reason_code' => $firstReason->code,
            'client_request_uuid' => (string) Str::orderedUuid(),
        ];

        $this->actingAs($this->manager);
        app(InventoryAdjustmentService::class)->adjust($command);

        $this->expectExceptionMessage('Inventory adjustment replay drift detected.');
        app(InventoryAdjustmentService::class)->adjust(array_merge($command, [
            'reason_code' => 'COUNT_FOUND',
        ]));
    }

    public function test_reason_replacement_creates_new_active_version_without_rewriting_history(): void
    {
        $reason = $this->reason('DAMAGED_CASE', InventoryAdjustmentReason::CATEGORY_DAMAGE, InventoryAdjustmentReason::DIRECTION_DECREASE);

        $this->actingAs($this->manager);
        $replacement = app(InventoryAdjustmentReasonService::class)->replace($reason, [
            'name' => 'Damaged Case',
            'requires_notes' => true,
        ]);

        $this->assertNotEquals($reason->id, $replacement->id);
        $this->assertSame($reason->reason_uuid, $replacement->reason_uuid);
        $this->assertSame(2, $replacement->reason_version);
        $this->assertFalse($reason->fresh()->is_active);
        $this->assertNull($reason->fresh()->active_slot);
        $this->assertTrue($replacement->fresh()->is_active);
        $this->assertSame('active', $replacement->fresh()->active_slot);
    }

    public function test_inactive_historical_reason_version_cannot_be_replaced(): void
    {
        $reason = $this->reason('EXPIRED_STOCK', InventoryAdjustmentReason::CATEGORY_EXPIRY, InventoryAdjustmentReason::DIRECTION_DECREASE);

        $this->actingAs($this->manager);
        app(InventoryAdjustmentReasonService::class)->replace($reason, [
            'name' => 'Expired Stock',
            'requires_notes' => true,
        ]);

        $this->expectExceptionMessage('Inactive historical adjustment reason versions cannot be edited.');
        app(InventoryAdjustmentReasonService::class)->replace($reason->fresh(), [
            'name' => 'Expired Stock Again',
            'requires_notes' => true,
        ]);
    }

    public function test_legacy_adjust_stock_creates_active_reason_when_only_inactive_history_exists(): void
    {
        $inventory = $this->inventory(10);
        $inactive = $this->reason('LEGACY_FOUND', InventoryAdjustmentReason::CATEGORY_FOUND_STOCK, InventoryAdjustmentReason::DIRECTION_INCREASE, requiresNotes: false);
        $inactive->update(['is_active' => false, 'active_slot' => null]);

        $movement = app(InventoryService::class)->adjustStock($inventory, 1, 'LEGACY_FOUND');

        $this->assertSame('manual_adjustment', $movement->movement_type);
        $this->assertEquals(11.0000, (float) $inventory->fresh()->current_stock);
        $this->assertTrue(InventoryAdjustmentReason::where('tenant_id', $this->tenant->id)
            ->where('code', 'LEGACY_FOUND')
            ->where('active_slot', 'active')
            ->exists());
    }

    private function inventory(float $currentStock): BranchInventory
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_inventory_tracked' => true,
        ]);

        return BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'current_stock' => $currentStock,
            'inventory_revision' => 1,
            'status' => 'active',
        ]);
    }

    private function reason(string $code, string $category, string $direction, bool $requiresNotes = true): InventoryAdjustmentReason
    {
        return InventoryAdjustmentReason::create([
            'reason_uuid' => (string) Str::orderedUuid(),
            'tenant_id' => $this->tenant->id,
            'code' => $code,
            'name' => str_replace('_', ' ', $code),
            'reason_category' => $category,
            'direction_policy' => $direction,
            'requires_notes' => $requiresNotes,
            'evidence_required' => false,
            'is_opening_balance' => $direction === InventoryAdjustmentReason::DIRECTION_OPENING_BALANCE,
            'is_active' => true,
            'active_slot' => 'active',
            'reason_version' => 1,
            'reason_schema_version' => 1,
        ]);
    }
}
