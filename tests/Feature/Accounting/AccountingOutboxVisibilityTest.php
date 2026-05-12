<?php

namespace Tests\Feature\Accounting;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\AccountingOutbox;
use App\Models\Sale;
use App\Models\BranchInventory;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleVoid;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingOutboxVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        app(BranchContext::class)->setBranch($this->branch);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        $this->role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Accountant'
        ]);
        
        $permission = Permission::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'view_sync_dashboard'
        ]);
        $multiBranchPermission = Permission::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'view_multi_branch_dashboard'
        ]);

        $this->role->permissions()->attach($permission);
        $this->role->permissions()->attach($multiBranchPermission);
        $this->user->assignRole($this->role);

        $this->actingAs($this->user);
    }

    /** AC 1, 9, 10: Authorized user can view outbox entries and detail payload */
    public function test_authorized_user_can_view_outbox(): void
    {
        $record = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => ['total' => '100.00'],
            'sync_status' => 'pending'
        ]);

        $response = $this->getJson('/api/accounting/outbox');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $record->id);

        $this->getJson('/api/accounting/outbox/' . $record->id)
            ->assertStatus(200)
            ->assertJsonPath('payload.total', '100.00');
    }

    /** AC 2: Unauthorized user receives 403 */
    public function test_unauthorized_user_receives_403(): void
    {
        $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($otherUser);

        $response = $this->getJson('/api/accounting/outbox');
        $response->assertStatus(403);
    }

    /** AC 3, 4: Tenant and Branch Isolation */
    public function test_tenant_and_branch_isolation_in_visibility(): void
    {
        // AC 3: Tenant B record (Switch context to create)
        $tenantB = Tenant::factory()->create(['status' => 'active']);
        
        // SWITCH to Tenant B to create its branch and outbox
        app(TenantContext::class)->setTenant($tenantB);
        $branchB_tenantB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        
        AccountingOutbox::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB_tenantB->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => ['total' => '999.00'],
        ]);

        // Switch back to original tenant for the test
        app(TenantContext::class)->setTenant($this->tenant);

        // AC 4: Branch B record (same tenant, different branch)
        $branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
        AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchB->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => ['total' => '50.00'],
        ]);

        // User A (Accountant) - should see only records for their tenant
        $response = $this->getJson('/api/accounting/outbox');
        $response->assertStatus(200);
        // Should NOT see Tenant B record, but should see Branch B record if no branch filter
        $response->assertJsonCount(1, 'data');
        
        $responseBranchB = $this->getJson("/api/accounting/outbox?branch_id={$branchB->id}");
        $responseBranchB->assertJsonCount(1, 'data');
        $responseBranchB->assertJsonPath('data.0.branch_id', $branchB->id);
    }

    /** AC 5: Filtering by event_type */
    public function test_filtering_by_event_type(): void
    {
        AccountingOutbox::query()->delete();

        $entry1 = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => [],
            'sync_status' => 'pending'
        ]);

        AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_voided',
            'source_type' => 'sale_void',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => [],
            'sync_status' => 'failed'
        ]);

        $this->getJson('/api/accounting/outbox?event_type=sale_paid')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $entry1->id);
    }

    /** AC 6: Filtering by sync_status */
    public function test_filtering_by_sync_status(): void
    {
        AccountingOutbox::query()->delete();

        AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => [],
            'sync_status' => 'pending'
        ]);

        $entry2 = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_voided',
            'source_type' => 'sale_void',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => [],
            'sync_status' => 'failed'
        ]);

        $this->getJson('/api/accounting/outbox?sync_status=failed')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $entry2->id);
    }

    /** AC 7: Filtering by source_type */
    public function test_filtering_by_source_type(): void
    {
        AccountingOutbox::query()->delete();

        AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => [],
            'sync_status' => 'pending'
        ]);

        $entry2 = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_voided',
            'source_type' => 'sale_void',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => [],
            'sync_status' => 'failed'
        ]);

        $this->getJson('/api/accounting/outbox?source_type=sale_void')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $entry2->id);
    }

    /** AC 8: Filtering by date range */
    public function test_filtering_by_date_range(): void
    {
        AccountingOutbox::query()->delete();

        // Entry 1: Old (should be excluded)
        // Using DB::table to bypass Eloquent timestamp management
        $oldDate = now()->subMonths(1)->toDateTimeString();
        \Illuminate\Support\Facades\DB::table('accounting_outbox')->insert([
            'id' => \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => json_encode([]),
            'sync_status' => 'pending',
            'created_at' => $oldDate,
            'updated_at' => $oldDate,
        ]);

        // Entry 2: Recent (should be included)
        $entry2 = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_voided',
            'source_type' => 'sale_void',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => [],
            'sync_status' => 'failed',
            'created_at' => now()
        ]);

        // Filter from yesterday - should ONLY find Entry 2
        $since = now()->subDays(1)->toDateString();
        $this->getJson("/api/accounting/outbox?date_from={$since}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $entry2->id);
    }

    /** AC 11, 12, 13, 14, 15: No-Mutation Boundary */
    public function test_query_is_strictly_non_mutating(): void
    {
        $outbox = AccountingOutbox::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'event_type' => 'sale_paid',
            'source_type' => 'sale',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'payload' => [],
            'sync_status' => 'pending',
            'attempt_count' => 0
        ]);

        $countBefore = [
            'outbox' => AccountingOutbox::count(),
            'sale' => Sale::count(),
            'payment' => SalePayment::count(),
            'inventory' => BranchInventory::count(),
            'refund' => SaleRefund::count(),
            'void' => SaleVoid::count(),
        ];

        $this->getJson('/api/accounting/outbox');
        $this->getJson("/api/accounting/outbox/{$outbox->id}");

        $fresh = $outbox->refresh();
        $this->assertEquals('pending', $fresh->sync_status); // AC 12
        $this->assertEquals(0, $fresh->attempt_count); // AC 13
        
        $this->assertEquals($countBefore['outbox'], AccountingOutbox::count()); // AC 14
        $this->assertEquals($countBefore['sale'], Sale::count()); // AC 15
        $this->assertEquals($countBefore['payment'], SalePayment::count()); // AC 15
        $this->assertEquals($countBefore['refund'], SaleRefund::count()); // AC 15
    }
}
