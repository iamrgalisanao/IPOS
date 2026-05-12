<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingMapping;
use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\TaxCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\AccountingMappingService;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountingMappingManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);

        app(TenantContext::class)->setTenant($this->tenant);
        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active', 'name' => 'Branch B']);

        $this->accountant = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $this->accountant->assignRole(Role::where('name', 'Accountant')->firstOrFail());

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        parent::tearDown();
    }

    public function test_authorized_user_can_view_mapping_index_and_filters(): void
    {
        $match = $this->createMapping(['branch_id' => $this->branchA->id, 'mapping_type' => AccountingMappingService::TYPE_ACCOUNT, 'pos_key' => 'sales']);
        $this->createMapping(['branch_id' => $this->branchB->id, 'mapping_type' => AccountingMappingService::TYPE_ACCOUNT, 'pos_key' => 'returns', 'status' => AccountingMapping::STATUS_INACTIVE]);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.mappings.index', [
                'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
                'status' => AccountingMapping::STATUS_ACTIVE,
                'branch_id' => $this->branchA->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Mappings/Index')
                ->has('mappings.data', 1)
                ->where('mappings.data.0.id', $match->id)
                ->where('filters.branch_id', $this->branchA->id)
            );
    }

    public function test_provider_filter_works(): void
    {
        $match = $this->createMapping(['provider' => 'quickbooks', 'branch_id' => $this->branchA->id, 'pos_key' => 'sales']);
        $hidden = $this->createMapping([
            'provider' => 'xero',
            'branch_id' => $this->branchA->id,
            'pos_key' => 'returns',
            'external_id' => 'XERO-RETURNS',
        ], false);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.mappings.index', ['provider' => 'quickbooks']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Mappings/Index')
                ->where('mappings.data', fn ($records) => collect($records)->pluck('id')->contains($match->id)
                    && !collect($records)->pluck('id')->contains($hidden->id))
                ->where('filters.provider', 'quickbooks')
            );
    }

    public function test_unauthorized_user_receives_403(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        app(TenantContext::class)->clear();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.mappings.index'))
            ->assertForbidden();
    }

    public function test_tenant_cannot_view_another_tenants_mapping(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($otherTenant);

        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id, 'status' => 'active']);
        $foreign = $this->createMapping(['tenant_id' => $otherTenant->id, 'branch_id' => $otherBranch->id]);
        app(TenantContext::class)->clear();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.mappings.show', $foreign->id))
            ->assertNotFound();
    }

    public function test_branch_scoped_user_only_sees_tenant_level_and_assigned_branch_mappings(): void
    {
        $viewer = $this->createBranchScopedManager($this->tenant, $this->branchA);
        $tenantMapping = $this->createMapping(['branch_id' => null, 'pos_key' => 'sales']);
        $branchMapping = $this->createMapping(['branch_id' => $this->branchA->id, 'pos_key' => 'discounts']);
        $hiddenMapping = $this->createMapping(['branch_id' => $this->branchB->id, 'pos_key' => 'returns']);

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.mappings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Mappings/Index')
                ->where('mappings.data', fn ($records) => collect($records)->count() === 2
                    && collect($records)->pluck('id')->contains($tenantMapping->id)
                    && collect($records)->pluck('id')->contains($branchMapping->id)
                    && !collect($records)->pluck('id')->contains($hiddenMapping->id))
            );

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.mappings.show', $hiddenMapping->id))
            ->assertNotFound();
    }

    public function test_tenant_wide_user_can_view_mapping_detail(): void
    {
        $mapping = $this->createMapping([
            'branch_id' => $this->branchA->id,
            'metadata' => ['label' => 'visible', 'client_secret' => 'hidden'],
        ]);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.mappings.show', $mapping->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Mappings/Show')
                ->where('mapping.id', $mapping->id)
                ->where('mapping.metadata.label', 'visible')
                ->missing('mapping.metadata.client_secret')
            );
    }

    public function test_store_account_mapping_sanitizes_metadata_and_has_no_sync_side_effects(): void
    {
        Http::fake();
        $countsBefore = $this->businessCounts();
        $outboxBefore = $this->outboxCount();

        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.mappings.store'), [
                'provider' => 'quickbooks',
                'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
                'branch_id' => null,
                'pos_key' => 'sales',
                'external_id' => 'QB-SALES',
                'external_name' => 'Sales Income',
                'metadata' => json_encode([
                    'label' => 'safe',
                    'access_token' => 'secret',
                    'nested' => ['client_secret' => 'hidden', 'visible' => 'yes'],
                ], JSON_THROW_ON_ERROR),
                'status' => AccountingMapping::STATUS_ACTIVE,
            ]);

        $mapping = AccountingMapping::query()->where('pos_key', 'sales')->firstOrFail();

        $response->assertRedirect(route('accounting.mappings.show', $mapping));
        $this->assertSame(['label' => 'safe', 'nested' => ['visible' => 'yes']], $mapping->metadata);
        $this->assertSame($countsBefore, $this->businessCounts());
        $this->assertSame($outboxBefore, $this->outboxCount());
        Http::assertNothingSent();
    }

    public function test_store_account_mapping_strips_authorization_keys_and_bearer_values(): void
    {
        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.mappings.store'), [
                'provider' => 'quickbooks',
                'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
                'branch_id' => null,
                'pos_key' => 'discounts',
                'external_id' => 'QB-DISCOUNTS',
                'metadata' => json_encode([
                    'Authorization' => 'Bearer super-secret-token',
                    'headers' => 'Authorization: Bearer another-secret-token',
                    'label' => 'safe',
                ], JSON_THROW_ON_ERROR),
                'status' => AccountingMapping::STATUS_ACTIVE,
            ])
            ->assertRedirect();

        $mapping = AccountingMapping::query()->where('pos_key', 'discounts')->firstOrFail();

        $this->assertSame([
            'headers' => '[redacted authorization]',
            'label' => 'safe',
        ], $mapping->metadata);
    }

    public function test_store_payment_method_mapping_works(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $paymentMethod = PaymentMethod::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        app(TenantContext::class)->clear();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.mappings.store'), [
                'provider' => 'quickbooks',
                'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
                'branch_id' => $this->branchA->id,
                'pos_entity_type' => 'payment_method',
                'pos_entity_id' => $paymentMethod->id,
                'external_id' => 'QB-PM-CASH',
                'status' => AccountingMapping::STATUS_ACTIVE,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('accounting_mappings', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_id' => $paymentMethod->id,
            'external_id' => 'QB-PM-CASH',
        ]);
    }

    public function test_store_tax_code_mapping_works(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $taxCategory = TaxCategory::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'VAT12',
            'name' => 'VAT 12%',
            'tax_type' => 'vat',
            'rate' => 12,
            'status' => 'active',
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.mappings.store'), [
                'provider' => 'quickbooks',
                'mapping_type' => AccountingMappingService::TYPE_TAX_CODE,
                'branch_id' => $this->branchA->id,
                'pos_entity_type' => 'tax_category',
                'pos_entity_id' => $taxCategory->id,
                'external_id' => 'QB-TAX-STANDARD',
                'status' => AccountingMapping::STATUS_ACTIVE,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('accounting_mappings', [
            'tenant_id' => $this->tenant->id,
            'mapping_type' => AccountingMappingService::TYPE_TAX_CODE,
            'pos_entity_id' => $taxCategory->id,
            'external_id' => 'QB-TAX-STANDARD',
        ]);
    }

    public function test_store_product_mapping_works(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        app(TenantContext::class)->clear();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.mappings.store'), [
                'provider' => 'quickbooks',
                'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
                'branch_id' => $this->branchA->id,
                'pos_entity_type' => 'product',
                'pos_entity_id' => $product->id,
                'external_id' => 'QB-PRODUCT-1',
                'status' => AccountingMapping::STATUS_ACTIVE,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('accounting_mappings', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branchA->id,
            'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
            'pos_entity_id' => $product->id,
            'external_id' => 'QB-PRODUCT-1',
        ]);
    }

    public function test_update_product_mapping_changes_external_fields_only_without_side_effects(): void
    {
        Http::fake();
        app(TenantContext::class)->setTenant($this->tenant);
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        app(TenantContext::class)->clear();
        $mapping = $this->createMapping([
            'branch_id' => $this->branchA->id,
            'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
            'pos_entity_type' => 'product',
            'pos_entity_id' => $product->id,
            'pos_key' => null,
            'external_id' => 'QB-ITEM-OLD',
        ]);

        $countsBefore = $this->businessCounts();
        $outboxBefore = $this->outboxCount();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->put(route('accounting.mappings.update', $mapping), [
                'provider' => 'quickbooks',
                'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
                'branch_id' => $this->branchA->id,
                'pos_entity_type' => 'product',
                'pos_entity_id' => $product->id,
                'external_id' => 'QB-ITEM-NEW',
                'external_name' => 'Catalog Item',
                'metadata' => json_encode(['memo' => 'updated'], JSON_THROW_ON_ERROR),
                'status' => AccountingMapping::STATUS_ACTIVE,
            ])
            ->assertRedirect(route('accounting.mappings.show', $mapping));

        $mapping->refresh();

        $this->assertSame('QB-ITEM-NEW', $mapping->external_id);
        $this->assertSame('Catalog Item', $mapping->external_name);
        $this->assertSame(['memo' => 'updated'], $mapping->metadata);
        $this->assertSame($countsBefore, $this->businessCounts());
        $this->assertSame($outboxBefore, $this->outboxCount());
        Http::assertNothingSent();
    }

    public function test_branch_scoped_user_cannot_edit_other_branch_mapping(): void
    {
        $viewer = $this->createBranchScopedManager($this->tenant, $this->branchA);
        $mapping = $this->createMapping(['branch_id' => $this->branchB->id]);

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->put(route('accounting.mappings.update', $mapping), [
                'provider' => 'quickbooks',
                'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
                'branch_id' => $this->branchB->id,
                'pos_key' => 'sales',
                'external_id' => 'QB-NOPE',
                'status' => AccountingMapping::STATUS_ACTIVE,
            ])
            ->assertNotFound();
    }

    public function test_branch_scoped_user_cannot_write_tenant_level_mapping(): void
    {
        $viewer = $this->createBranchScopedManager($this->tenant, $this->branchA);

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.mappings.store'), [
                'provider' => 'quickbooks',
                'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
                'branch_id' => null,
                'pos_key' => 'sales',
                'external_id' => 'QB-SALES',
                'status' => AccountingMapping::STATUS_ACTIVE,
            ])
            ->assertForbidden();
    }

    public function test_status_toggle_works_without_side_effects(): void
    {
        Http::fake();
        $mapping = $this->createMapping(['branch_id' => $this->branchA->id]);
        $countsBefore = $this->businessCounts();
        $outboxBefore = $this->outboxCount();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->patch(route('accounting.mappings.status', $mapping), ['status' => AccountingMapping::STATUS_INACTIVE])
            ->assertRedirect(route('accounting.mappings.show', $mapping));

        $this->assertSame(AccountingMapping::STATUS_INACTIVE, $mapping->refresh()->status);
        $this->assertSame($countsBefore, $this->businessCounts());
        $this->assertSame($outboxBefore, $this->outboxCount());
        Http::assertNothingSent();
    }

    public function test_status_activate_works_without_side_effects(): void
    {
        Http::fake();
        $mapping = $this->createMapping([
            'branch_id' => $this->branchA->id,
            'pos_key' => 'surcharges',
            'status' => AccountingMapping::STATUS_INACTIVE,
        ]);
        $countsBefore = $this->businessCounts();
        $outboxBefore = $this->outboxCount();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->patch(route('accounting.mappings.status', $mapping), ['status' => AccountingMapping::STATUS_ACTIVE])
            ->assertRedirect(route('accounting.mappings.show', $mapping));

        $this->assertSame(AccountingMapping::STATUS_ACTIVE, $mapping->refresh()->status);
        $this->assertSame($countsBefore, $this->businessCounts());
        $this->assertSame($outboxBefore, $this->outboxCount());
        Http::assertNothingSent();
    }

    public function test_duplicate_active_mapping_activation_is_rejected(): void
    {
        $active = $this->createMapping(['branch_id' => $this->branchA->id, 'pos_key' => 'sales', 'status' => AccountingMapping::STATUS_ACTIVE]);
        $inactive = $this->createMapping([
            'branch_id' => $this->branchA->id,
            'pos_key' => 'sales',
            'status' => AccountingMapping::STATUS_INACTIVE,
            'external_id' => 'QB-SALES-ALT',
        ], false);

        $this->actingAs($this->accountant)
            ->from(route('accounting.mappings.show', $inactive))
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->patch(route('accounting.mappings.status', $inactive), ['status' => AccountingMapping::STATUS_ACTIVE])
            ->assertSessionHasErrors('mapping');

        $this->assertSame(AccountingMapping::STATUS_ACTIVE, $active->refresh()->status);
        $this->assertSame(AccountingMapping::STATUS_INACTIVE, $inactive->refresh()->status);
    }

    public function test_existing_inactive_mapping_can_be_reactivated_through_store_flow(): void
    {
        $mapping = $this->createMapping([
            'branch_id' => $this->branchA->id,
            'pos_key' => 'sales',
            'status' => AccountingMapping::STATUS_INACTIVE,
        ]);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.mappings.store'), [
                'provider' => 'quickbooks',
                'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
                'branch_id' => $this->branchA->id,
                'pos_key' => 'sales',
                'external_id' => 'QB-SALES-NEW',
                'external_name' => 'Sales New',
                'status' => AccountingMapping::STATUS_ACTIVE,
            ])
            ->assertRedirect(route('accounting.mappings.show', $mapping));

        $this->assertSame(1, AccountingMapping::query()->where('tenant_id', $this->tenant->id)->where('branch_id', $this->branchA->id)->where('pos_key', 'sales')->count());
        $this->assertSame(AccountingMapping::STATUS_ACTIVE, $mapping->refresh()->status);
        $this->assertSame('QB-SALES-NEW', $mapping->external_id);
    }

    public function test_cashier_pos_routes_remain_accounting_silent(): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $cashier->assignRole(Role::where('name', 'Cashier')->firstOrFail());
        $cashier->assignToBranch($this->branchA);
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
        app(TenantContext::class)->clear();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('POS/Index')
                ->missing('mappings')
                ->missing('accounting_mappings')
                ->missing('accounting')
            );
    }

    protected function createBranchScopedManager(Tenant $tenant, Branch $branch): User
    {
        app(TenantContext::class)->setTenant($tenant);

        $role = Role::create([
            'name' => 'Branch Mapping Manager',
            'description' => 'Branch-scoped mapping management',
        ]);
        $permission = Permission::where('name', 'manage_accounting_mappings')->firstOrFail();
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'actor_type' => 'tenant_user',
            'status' => 'active',
        ]);
        $user->assignRole($role);
        $user->assignToBranch($branch);

        app(TenantContext::class)->clear();

        return $user;
    }

    protected function createMapping(array $overrides = [], bool $useService = true): AccountingMapping
    {
        $tenantId = $overrides['tenant_id'] ?? $this->tenant->id;

        app(TenantContext::class)->setTenant(Tenant::findOrFail($tenantId));

        if (array_key_exists('branch_id', $overrides) && $overrides['branch_id']) {
            app(BranchContext::class)->setBranch(Branch::findOrFail($overrides['branch_id']));
        } else {
            app(BranchContext::class)->clear();
        }

        $attributes = array_merge([
            'tenant_id' => $tenantId,
            'branch_id' => null,
            'provider' => 'quickbooks',
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_entity_type' => null,
            'pos_entity_id' => null,
            'pos_key' => 'sales',
            'external_id' => 'QB-SALES',
            'external_name' => 'Sales',
            'metadata' => ['label' => 'visible'],
            'status' => AccountingMapping::STATUS_ACTIVE,
            'created_by' => $this->accountant->id,
            'updated_by' => $this->accountant->id,
        ], $overrides);

        $mapping = $useService
            ? app(AccountingMappingService::class)->createOrUpdate($attributes, $this->accountant)
            : AccountingMapping::create($attributes);

        app(BranchContext::class)->clear();
        app(TenantContext::class)->clear();

        return $mapping;
    }

    protected function businessCounts(): array
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $counts = [
            'sales' => Sale::count(),
            'sale_payments' => SalePayment::count(),
            'inventory_movements' => InventoryMovement::count(),
            'sale_refunds' => SaleRefund::count(),
            'sale_voids' => SaleVoid::count(),
        ];

        app(TenantContext::class)->clear();

        return $counts;
    }

    protected function outboxCount(): int
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $count = AccountingOutbox::count();

        app(TenantContext::class)->clear();

        return $count;
    }
}