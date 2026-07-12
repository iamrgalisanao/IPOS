<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\DiscountType;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesMachineProfile;
use App\Models\Sale;
use App\Models\SaleStatutoryDiscount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\POS\ManagerAuthorizationService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManagerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private User $manager;
    private SalesMachineProfile $terminal;
    private DiscountType $type;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->setTenant($this->tenant);
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        app(BranchContext::class)->setBranch($this->branch);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $this->cashier->assignToBranch($this->branch);
        $this->manager = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'status' => 'active',
            'email' => 'manager@example.test', 'password' => Hash::make('Secret-1234'),
        ]);
        $this->manager->assignToBranch($this->branch);
        $permission = Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'pos.approve_discount']);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Discount Manager']);
        $role->permissions()->attach($permission);
        $this->manager->assignRole($role);
        $this->terminal = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'profile_code' => 'APPROVAL-TERM', 'status' => 'active',
        ]);
        $this->type = DiscountType::create([
            'code' => 'TEST-PWD', 'name' => 'PWD', 'statutory_category' => 'pwd',
            'default_rate' => .2, 'vat_treatment' => 'exempt', 'requires_identity' => true,
            'requires_approval' => true, 'applies_to_fnb' => true, 'applies_to_retail' => true,
            'is_active' => true,
        ]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'selling_price' => 112]);
        $this->actingAs($this->cashier);
    }

    public function test_independent_same_branch_manager_receives_short_lived_context_bound_approval(): void
    {
        $approval = app(ManagerAuthorizationService::class)->issue(
            $this->cashier, $this->tenant->id, $this->branch->id, $this->terminal, $this->type,
            [['product_id' => $this->product->id, 'quantity' => 1]], $this->approvalOptions(),
            $this->manager->email, 'Secret-1234',
        );

        $this->assertSame('issued', $approval->status);
        $this->assertSame($this->manager->id, $approval->user_id);
        $this->assertSame($this->cashier->id, $approval->requesting_user_id);
        $this->assertSame($this->terminal->id, $approval->sales_machine_profile_id);
        $this->assertNotEmpty($approval->context_hmac);
        $this->assertTrue($approval->expires_at->between(now()->addSeconds(110), now()->addSeconds(125)));
        $this->assertArrayNotHasKey('beneficiaries', $approval->metadata);
    }

    public function test_wrong_password_self_approval_and_cross_branch_manager_fail_generically(): void
    {
        foreach ([[$this->manager->email, 'wrong'], [$this->cashier->email, 'password']] as [$email, $password]) {
            try {
                app(ManagerAuthorizationService::class)->issue(
                    $this->cashier, $this->tenant->id, $this->branch->id, $this->terminal, $this->type,
                    [['product_id' => $this->product->id, 'quantity' => 1]], $this->approvalOptions(), $email, $password,
                );
                $this->fail('Authorization should have failed.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Manager authorization could not be verified.', $e->getMessage());
            }
        }
    }

    public function test_consumption_is_single_use_and_rolls_back_with_sale_transaction(): void
    {
        $items = [['product_id' => $this->product->id, 'quantity' => 1]];
        $approval = app(ManagerAuthorizationService::class)->issue(
            $this->cashier, $this->tenant->id, $this->branch->id, $this->terminal, $this->type,
            $items, $this->approvalOptions(), $this->manager->email, 'Secret-1234',
        );
        $sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id, 'sales_machine_profile_id' => $this->terminal->id,
        ]);
        SaleStatutoryDiscount::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'sale_id' => $sale->id, 'discount_type' => 'pwd', 'discount_amount' => 20,
        ]);

        DB::beginTransaction();
        app(ManagerAuthorizationService::class)->consume(
            $approval->id, $this->tenant->id, $this->branch->id, $this->cashier->id,
            $this->terminal, $this->type, $items, $this->approvalOptions(), $sale,
        );
        DB::rollBack();
        $this->assertSame('issued', $approval->fresh()->status);

        app(ManagerAuthorizationService::class)->consume(
            $approval->id, $this->tenant->id, $this->branch->id, $this->cashier->id,
            $this->terminal, $this->type, $items, $this->approvalOptions(), $sale,
        );
        $this->assertSame('consumed', $approval->fresh()->status);

        $this->expectException(\RuntimeException::class);
        app(ManagerAuthorizationService::class)->consume(
            $approval->id, $this->tenant->id, $this->branch->id, $this->cashier->id,
            $this->terminal, $this->type, $items, $this->approvalOptions(), $sale,
        );
    }

    private function approvalOptions(): array
    {
        return ['beneficiaries' => [['beneficiary_name' => 'Protected Person', 'id_number' => 'PWD-1']]];
    }
}
