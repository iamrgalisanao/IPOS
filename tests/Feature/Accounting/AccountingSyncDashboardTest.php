<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingOutbox;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\AccountingMappingService;
use App\Services\Accounting\QuickBooksSyncService;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountingSyncDashboardTest extends TestCase
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

    public function test_authorized_admin_can_view_dashboard_list(): void
    {
        $record = $this->createOutbox($this->tenant, $this->branchA, ['sync_status' => 'failed']);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Outbox/Index')
                ->where('records.data.0.id', $record->id)
                ->where('records.data.0.sync_status', 'failed')
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
            ->get(route('accounting.outbox.index'))
            ->assertForbidden();

        $record = $this->createOutbox($this->tenant, $this->branchA, ['sync_status' => 'failed']);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.show', $record->id))
            ->assertForbidden();
    }

    public function test_tenant_cannot_view_another_tenant_outbox_record(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($otherTenant);

        app(TenantContext::class)->setTenant($otherTenant);
        $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id, 'status' => 'active']);
        $foreignRecord = $this->createOutbox($otherTenant, $otherBranch, ['sync_status' => 'failed']);
        app(TenantContext::class)->clear();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.show', $foreignRecord->id))
            ->assertNotFound();
    }

    public function test_branch_scoped_user_cannot_view_other_branch_without_tenant_wide_permission(): void
    {
        $viewer = $this->createBranchScopedViewer($this->tenant, $this->branchA);
        $recordA = $this->createOutbox($this->tenant, $this->branchA, ['sync_status' => 'failed']);
        $recordB = $this->createOutbox($this->tenant, $this->branchB, ['sync_status' => 'failed']);

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Outbox/Index')
                ->has('records.data', 1)
                ->where('records.data.0.id', $recordA->id)
            );

        $this->actingAs($viewer)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.show', $recordB->id))
            ->assertNotFound();
    }

    public function test_tenant_wide_accounting_user_can_view_multiple_branches(): void
    {
        $recordA = $this->createOutbox($this->tenant, $this->branchA, ['sync_status' => 'failed']);
        $recordB = $this->createOutbox($this->tenant, $this->branchB, ['sync_status' => 'pending']);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Outbox/Index')
                ->where('records.data', fn ($records) => collect($records)->count() === 2
                    && collect($records)->pluck('id')->contains($recordA->id)
                    && collect($records)->pluck('id')->contains($recordB->id))
            );
    }

    public function test_filter_by_event_type_works(): void
    {
        $match = $this->createOutbox($this->tenant, $this->branchA, ['event_type' => 'sale_voided']);
        $this->createOutbox($this->tenant, $this->branchA, ['event_type' => 'sale_paid']);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.index', ['event_type' => 'sale_voided']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.id', $match->id)
            );
    }

    public function test_filter_by_sync_status_works(): void
    {
        $match = $this->createOutbox($this->tenant, $this->branchA, ['sync_status' => 'failed']);
        $this->createOutbox($this->tenant, $this->branchA, ['sync_status' => 'synced']);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.index', ['sync_status' => 'failed']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.id', $match->id)
            );
    }

    public function test_filter_by_source_type_works(): void
    {
        $match = $this->createOutbox($this->tenant, $this->branchA, ['source_type' => 'sale_refund']);
        $this->createOutbox($this->tenant, $this->branchA, ['source_type' => 'sale']);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.index', ['source_type' => 'sale_refund']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.id', $match->id)
            );
    }

    public function test_filter_by_branch_id_works(): void
    {
        $match = $this->createOutbox($this->tenant, $this->branchA);
        $this->createOutbox($this->tenant, $this->branchB);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.index', ['branch_id' => $this->branchA->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.id', $match->id)
            );
    }

    public function test_filter_by_date_range_works(): void
    {
        $old = $this->createOutbox($this->tenant, $this->branchA);
        $this->setCreatedAt($old, now()->subDays(10));

        $recent = $this->createOutbox($this->tenant, $this->branchA);
        $this->setCreatedAt($recent, now()->subDay());

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.index', [
                'date_from' => now()->subDays(2)->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.id', $recent->id)
            );
    }

    public function test_detail_view_shows_payload_and_error_safely(): void
    {
        $record = $this->createOutbox($this->tenant, $this->branchA, [
            'sync_status' => 'failed',
            'sync_error' => 'Provider failed access_token=secret Authorization: Bearer hidden',
            'payload' => array_merge($this->basePayload(), [
                'access_token' => 'secret-token',
                'private_key' => 'private-value',
                'nested' => ['client_secret' => 'child-secret', 'safe' => 'visible'],
            ]),
        ]);

        $response = $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get(route('accounting.outbox.show', $record->id));

        $response->assertOk()
            ->assertDontSee('secret-token')
            ->assertDontSee('private-value')
            ->assertDontSee('child-secret')
            ->assertDontSee('Bearer hidden')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounting/Outbox/Show')
                ->where('record.id', $record->id)
                ->where('record.payload.nested.safe', 'visible')
                ->missing('record.payload.access_token')
                ->missing('record.payload.private_key')
                ->has('syncReadiness.checks', 2)
            );
    }

    public function test_manual_retry_allowed_for_failed_records(): void
    {
        $record = $this->createOutbox($this->tenant, $this->branchA, ['sync_status' => 'failed', 'attempt_count' => 1]);
        $this->seedMappings($this->branchA->id);

        $fakeSync = new class extends QuickBooksSyncService {
            public int $calls = 0;

            public function __construct() {}

            public function sync(AccountingOutbox $record): array
            {
                $this->calls++;

                return [
                    'external_provider' => 'quickbooks',
                    'external_id' => 'QB-RETRY',
                    'external_reference' => 'SalesReceipt:QB-RETRY',
                ];
            }
        };
        app()->instance(QuickBooksSyncService::class, $fakeSync);

        $countsBefore = $this->businessCounts();

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.outbox.retry', $record->id))
            ->assertRedirect(route('accounting.outbox.show', $record->id));

        $record->refresh();

        $this->assertSame(1, $fakeSync->calls);
        $this->assertSame('synced', $record->sync_status);
        $this->assertSame(2, $record->attempt_count);
        $this->assertSame('QB-RETRY', $record->external_id);
        $this->assertSame($countsBefore, $this->businessCounts());
        $this->assertSame(1, $this->outboxCount());
    }

    public function test_manual_retry_of_pending_is_rejected(): void
    {
        $record = $this->createOutbox($this->tenant, $this->branchA, ['sync_status' => 'pending']);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.outbox.retry', $record->id))
            ->assertStatus(422);

        $this->assertSame('pending', $record->refresh()->sync_status);
    }

    public function test_manual_retry_of_processing_is_rejected(): void
    {
        $record = $this->createOutbox($this->tenant, $this->branchA, ['sync_status' => 'processing']);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.outbox.retry', $record->id))
            ->assertStatus(422);

        $this->assertSame('processing', $record->refresh()->sync_status);
    }

    public function test_manual_retry_of_synced_is_rejected(): void
    {
        $record = $this->createOutbox($this->tenant, $this->branchA, ['sync_status' => 'synced']);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.outbox.retry', $record->id))
            ->assertStatus(422);

        $this->assertSame('synced', $record->refresh()->sync_status);
    }

    public function test_manual_retry_failure_keeps_record_failed(): void
    {
        $record = $this->createOutbox($this->tenant, $this->branchA, ['sync_status' => 'failed', 'attempt_count' => 1]);
        $this->seedMappings($this->branchA->id);

        $fakeSync = new class extends QuickBooksSyncService {
            public function __construct() {}

            public function sync(AccountingOutbox $record): array
            {
                throw new \RuntimeException('Provider failure access_token=retry-secret');
            }
        };
        app()->instance(QuickBooksSyncService::class, $fakeSync);

        $this->actingAs($this->accountant)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->post(route('accounting.outbox.retry', $record->id))
            ->assertRedirect(route('accounting.outbox.show', $record->id));

        $record->refresh();
        $this->assertSame('failed', $record->sync_status);
        $this->assertSame(2, $record->attempt_count);
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
        app(TenantContext::class)->clear();

        app(TenantContext::class)->setTenant($this->tenant);
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
                ->missing('sync_status')
                ->missing('accounting_outbox')
                ->missing('outbox')
            );
    }

    protected function createBranchScopedViewer(Tenant $tenant, Branch $branch): User
    {
        app(TenantContext::class)->setTenant($tenant);

        $role = Role::create([
            'name' => 'Branch Accounting Viewer',
            'description' => 'Branch-scoped accounting visibility',
        ]);
        $permission = Permission::where('name', 'view_sync_dashboard')->firstOrFail();
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

    protected function createOutbox(Tenant $tenant, Branch $branch, array $overrides = []): AccountingOutbox
    {
        app(TenantContext::class)->setTenant($tenant);

        $record = AccountingOutbox::create(array_merge([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => (string) Str::uuid(),
            'payload' => $this->basePayload(),
            'sync_status' => 'pending',
            'attempt_count' => 0,
        ], $overrides));

        app(TenantContext::class)->clear();

        return $record;
    }

    protected function basePayload(): array
    {
        return [
            'sale_number' => 'POS-100',
            'subtotal' => '100.0000',
            'tax_total' => '12.0000',
            'total' => '112.0000',
            'items' => [[
                'product_id' => 'prod-uuid',
                'product_name' => 'Burger',
                'quantity' => '1.0000',
                'unit_price' => '100.0000',
                'line_total' => '100.0000',
            ]],
            'taxes' => [[
                'tax_category_id' => 'tax-uuid',
                'tax_rate' => '12.0000',
                'tax_amount' => '12.0000',
            ]],
            'payments' => [[
                'method' => 'cash-method-uuid',
                'amount' => '112.0000',
            ]],
        ];
    }

    protected function seedMappings(string $branchId): void
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $service = app(AccountingMappingService::class);
        $service->createOrUpdate([
            'branch_id' => $branchId,
            'mapping_type' => AccountingMappingService::TYPE_ACCOUNT,
            'pos_key' => 'sales',
            'external_id' => 'QB-ACCOUNT-SALES',
        ]);
        $service->createOrUpdate([
            'branch_id' => $branchId,
            'mapping_type' => AccountingMappingService::TYPE_PRODUCT,
            'pos_entity_type' => 'product',
            'pos_entity_id' => 'prod-uuid',
            'external_id' => 'QB-ITEM-PROD',
        ]);
        $service->createOrUpdate([
            'branch_id' => $branchId,
            'mapping_type' => AccountingMappingService::TYPE_TAX_CODE,
            'pos_entity_type' => 'tax_category',
            'pos_entity_id' => 'tax-uuid',
            'external_id' => 'QB-TAX-STANDARD',
        ]);
        $service->createOrUpdate([
            'branch_id' => $branchId,
            'mapping_type' => AccountingMappingService::TYPE_PAYMENT_METHOD,
            'pos_entity_type' => 'payment_method',
            'pos_entity_id' => 'cash-method-uuid',
            'external_id' => 'QB-PM-CASH',
        ]);

        app(TenantContext::class)->clear();
    }

    protected function businessCounts(): array
    {
        app(TenantContext::class)->setTenant($this->tenant);

        $counts = [
            'sale' => Sale::count(),
            'payment' => SalePayment::count(),
            'inventory' => InventoryMovement::count(),
            'refund' => SaleRefund::count(),
            'void' => SaleVoid::count(),
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

    protected function setCreatedAt(AccountingOutbox $record, \DateTimeInterface $createdAt): void
    {
        app(TenantContext::class)->setTenant($this->tenant);
        $record->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
        app(TenantContext::class)->clear();
    }
}
