<?php

namespace Tests\Feature\Customers;

use App\Models\AccountingOutbox;
use App\Models\Customer;
use App\Models\CustomerFinancialAccount;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CustomerFinancialAccountFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $owner;
    private User $branchManager;
    private User $accountant;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'currency' => 'PHP',
            'subscription_metadata' => ['plan' => 'enterprise'],
        ]);

        app(TenantContext::class)->setTenant($this->tenant);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->owner = $this->userWithRole('Owner/Admin');
        $this->branchManager = $this->userWithRole('Branch Manager');
        $this->accountant = $this->userWithRole('Accountant');
        $this->cashier = $this->userWithRole('Cashier');
    }

    protected function tearDown(): void
    {
        app(\App\Services\TenantContext::class)->clear();
        app(\App\Services\BranchContext::class)->clear();

        parent::tearDown();
    }

    public function test_owner_can_create_customer_and_active_financial_account(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('admin.customer-accounts.store'), [
                'display_name' => 'Maria Santos',
                'email' => 'shared@example.test',
                'phone' => '+639171234567',
            ]);

        $response->assertCreated()
            ->assertJsonPath('customer_financial_account.status', CustomerFinancialAccount::STATUS_ACTIVE)
            ->assertJsonPath('customer_financial_account.currency_code', 'PHP')
            ->assertJsonPath('customer_financial_account.customer.display_name', 'Maria Santos');

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenant->id,
            'display_name' => 'Maria Santos',
            'normalized_display_name' => 'maria santos',
            'email' => 'shared@example.test',
        ]);

        $this->assertDatabaseHas('customer_financial_accounts', [
            'tenant_id' => $this->tenant->id,
            'status' => CustomerFinancialAccount::STATUS_ACTIVE,
            'currency_code' => 'PHP',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'CUSTOMER_CREATED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CUSTOMER_FINANCIAL_ACCOUNT_CREATED']);
    }

    public function test_account_can_exist_without_balance_or_ledger_columns(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('admin.customer-accounts.store'), [
                'display_name' => 'No Ledger Yet',
            ])
            ->assertCreated();

        $this->assertFalse(Schema::hasColumn('customer_financial_accounts', 'balance'));
        $this->assertFalse(Schema::hasColumn('customer_financial_accounts', 'store_credit_balance'));
        $this->assertFalse(Schema::hasColumn('customer_financial_accounts', 'points_balance'));
    }

    public function test_duplicate_account_for_same_customer_is_rejected(): void
    {
        $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerFinancialAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'currency_code' => 'PHP',
        ]);

        $this->actingAs($this->owner)
            ->postJson(route('admin.customer-accounts.store'), [
                'customer_id' => $customer->id,
            ])
            ->assertStatus(409);
    }

    public function test_cross_tenant_account_access_is_hidden(): void
    {
        $otherTenant = Tenant::factory()->create(['currency' => 'PHP']);
        app(TenantContext::class)->setTenant($otherTenant);
        $otherCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherAccount = CustomerFinancialAccount::factory()->create([
            'tenant_id' => $otherTenant->id,
            'customer_id' => $otherCustomer->id,
            'currency_code' => 'PHP',
        ]);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->actingAs($this->owner)
            ->getJson(route('admin.customer-accounts.show', $otherAccount))
            ->assertNotFound();
    }

    public function test_view_only_user_can_read_but_cannot_mutate_accounts(): void
    {
        $account = $this->createAccount();

        $this->actingAs($this->branchManager)
            ->getJson(route('admin.customer-accounts.show', $account))
            ->assertOk();

        $this->actingAs($this->branchManager)
            ->postJson(route('admin.customer-accounts.store'), [
                'display_name' => 'Branch Created',
            ])
            ->assertForbidden();
    }

    public function test_cashier_without_customer_account_permissions_is_forbidden(): void
    {
        $this->actingAs($this->cashier)
            ->getJson(route('admin.customer-accounts.index'))
            ->assertForbidden();
    }

    public function test_lifecycle_transitions_and_closed_reactivation_conflict(): void
    {
        $account = $this->createAccount();

        $this->actingAs($this->owner)
            ->patchJson(route('admin.customer-accounts.status', $account), [
                'status' => CustomerFinancialAccount::STATUS_SUSPENDED,
                'reason' => 'Temporary customer hold',
            ])
            ->assertOk()
            ->assertJsonPath('customer_financial_account.status', CustomerFinancialAccount::STATUS_SUSPENDED);

        $this->actingAs($this->owner)
            ->patchJson(route('admin.customer-accounts.status', $account->fresh()), [
                'status' => CustomerFinancialAccount::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('customer_financial_account.status', CustomerFinancialAccount::STATUS_ACTIVE);

        $this->actingAs($this->owner)
            ->patchJson(route('admin.customer-accounts.status', $account->fresh()), [
                'status' => CustomerFinancialAccount::STATUS_CLOSED,
                'reason' => 'Customer requested closure',
            ])
            ->assertOk()
            ->assertJsonPath('customer_financial_account.status', CustomerFinancialAccount::STATUS_CLOSED);

        $this->actingAs($this->owner)
            ->patchJson(route('admin.customer-accounts.status', $account->fresh()), [
                'status' => CustomerFinancialAccount::STATUS_ACTIVE,
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('audit_logs', ['action' => 'CUSTOMER_FINANCIAL_ACCOUNT_SUSPENDED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CUSTOMER_FINANCIAL_ACCOUNT_REACTIVATED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CUSTOMER_FINANCIAL_ACCOUNT_CLOSED']);
    }

    public function test_customer_id_and_currency_code_are_immutable(): void
    {
        $account = $this->createAccount();
        $otherCustomer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->expectException(RuntimeException::class);
        $account->customer_id = $otherCustomer->id;
        $account->save();
    }

    public function test_currency_code_is_immutable(): void
    {
        $account = $this->createAccount();

        $this->expectException(RuntimeException::class);
        $account->currency_code = 'USD';
        $account->save();
    }

    public function test_customer_anonymization_preserves_account_linkage_and_removes_personal_fields(): void
    {
        $account = $this->createAccount([
            'display_name' => 'Privacy Request',
            'email' => 'privacy@example.test',
            'phone' => '+639171111111',
            'external_reference' => 'EXT-PRIVACY',
            'metadata' => ['note' => 'remove me'],
        ]);

        $this->actingAs($this->owner)
            ->postJson(route('admin.customers.anonymize', $account->customer), [
                'reason' => 'Privacy request',
            ])
            ->assertOk()
            ->assertJsonPath('customer.email', null)
            ->assertJsonPath('customer.phone', null)
            ->assertJsonPath('customer.external_reference', null);

        $customer = $account->customer->fresh();
        $this->assertSame($account->customer_id, $customer->id);
        $this->assertTrue($customer->financialAccount()->whereKey($account->id)->exists());
        $this->assertNotNull($customer->anonymized_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CUSTOMER_ANONYMIZED']);
    }

    public function test_create_request_rejects_client_supplied_uuid_and_allows_email_reuse(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('admin.customer-accounts.store'), [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'display_name' => 'Bad UUID',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id');

        $payload = [
            'display_name' => 'Shared Email One',
            'email' => 'family@example.test',
        ];

        $this->actingAs($this->owner)
            ->postJson(route('admin.customer-accounts.store'), $payload)
            ->assertCreated();

        $this->actingAs($this->owner)
            ->postJson(route('admin.customer-accounts.store'), [
                'display_name' => 'Shared Email Two',
                'email' => 'family@example.test',
            ])
            ->assertCreated();
    }

    public function test_customer_with_financial_account_cannot_be_physically_deleted(): void
    {
        $account = $this->createAccount();

        $this->expectException(RuntimeException::class);
        $account->customer->forceDelete();
    }

    public function test_story_endpoints_do_not_create_sales_payments_refunds_or_accounting_outbox_rows(): void
    {
        $before = [
            'sales' => Sale::count(),
            'sale_payments' => SalePayment::count(),
            'sale_refunds' => SaleRefund::count(),
            'accounting_outbox' => AccountingOutbox::count(),
        ];

        $this->actingAs($this->owner)
            ->postJson(route('admin.customer-accounts.store'), [
                'display_name' => 'No Side Effects',
            ])
            ->assertCreated();

        $this->assertSame($before['sales'], Sale::count());
        $this->assertSame($before['sale_payments'], SalePayment::count());
        $this->assertSame($before['sale_refunds'], SaleRefund::count());
        $this->assertSame($before['accounting_outbox'], AccountingOutbox::count());
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $role = Role::where('tenant_id', $this->tenant->id)
            ->where('name', $roleName)
            ->firstOrFail();

        $user->assignRole($role);

        return $user;
    }

    private function createAccount(array $customerAttributes = []): CustomerFinancialAccount
    {
        $customer = Customer::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
        ], $customerAttributes));

        return CustomerFinancialAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'currency_code' => 'PHP',
        ]);
    }
}
