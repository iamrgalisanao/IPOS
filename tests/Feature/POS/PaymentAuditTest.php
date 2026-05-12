<?php

namespace Tests\Feature\POS;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $user;
    protected Sale $sale;
    protected PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'actor_type' => 'tenant_user'
        ]);
        
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Cashier']);
        $permission = Permission::withoutGlobalScopes()->where([
            'tenant_id' => $this->tenant->id,
            'name' => 'create_sale'
        ])->first() 
            ?? Permission::create(['tenant_id' => $this->tenant->id, 'name' => 'create_sale']);
        $role->permissions()->attach($permission);
        $this->user->assignRole($role);
        $this->user->assignToBranch($this->branch);

        $this->sale = Sale::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'total' => 100.00,
            'status' => 'created',
        ]);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'status' => 'active',
        ]);
    }

    private function postPayment(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.sales.payments', ['sale_id' => $this->sale->id]), $payload);
    }

    /** AC: Successful single payment creates audit log */
    public function test_successful_payment_creates_audit_log(): void
    {
        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 100];
        $response = $this->postPayment($payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'action' => 'payment_recorded',
            'auditable_type' => Sale::class,
            'auditable_id' => $this->sale->id,
            'actor_user_id' => $this->user->id
        ]);

        $log = AuditLog::where('action', 'payment_recorded')->first();
        $this->assertEquals(100, $log->metadata['total_amount']);
    }

    /** AC: Failed payment attempt logs safe failure context */
    public function test_failed_payment_creates_failure_log(): void
    {
        $payload = [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 50] // Underpayment
            ]
        ];
        
        $response = $this->actingAs($this->user)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.sales.payments.split', ['sale_id' => $this->sale->id]), $payload);

        $response->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_recording_failed',
            'reason' => 'Amount mismatch',
            'actor_user_id' => $this->user->id
        ]);
    }

    /** AC: Cross-tenant audit isolation */
    public function test_audit_isolation(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        
        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 100];
        $this->postPayment($payload);

        // Switch context to other tenant
        app(TenantContext::class)->setTenant($otherTenant);
        $this->assertEquals(0, AuditLog::count(), 'Other tenant should not see audits');
    }

    /** AC: Mutation silence check */
    public function test_audit_is_mutation_silent(): void
    {
        $payload = ['payment_method_id' => $this->cashMethod->id, 'amount' => 100];
        $this->postPayment($payload);

        // Inventory movements SHOULD NOT be created (this test doesn't set up inventory, so it would fail anyway)
        if (\Schema::hasTable('inventory_movements')) $this->assertEquals(0, \DB::table('inventory_movements')->count());
        
        // Accounting outbox SHOULD be created for a successful payment
        if (\Schema::hasTable('accounting_outbox')) $this->assertEquals(1, \DB::table('accounting_outbox')->count());
    }
}
