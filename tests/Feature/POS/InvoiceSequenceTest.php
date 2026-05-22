<?php

namespace Tests\Feature\POS;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use App\Services\POS\SaleCreationService;
use App\Services\POS\InvoiceSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceSequenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Branch $branch;
    private User $cashier;
    private Product $product;
    private ProductCategory $category;
    private SalesMachineProfile $machineProfile;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(RbacSeeder::class)->seedForTenant($this->tenant);
        app(TenantContext::class)->setTenant($this->tenant);

        $this->branch = Branch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);
        app(BranchContext::class)->setBranch($this->branch);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cashier->assignRole(Role::where('name', 'Cashier')->first());
        $this->cashier->assignToBranch($this->branch);

        $this->category = ProductCategory::create(['name' => 'General', 'code' => 'GEN']);
        $this->product = Product::create([
            'tenant_id'            => $this->tenant->id,
            'product_category_id'  => $this->category->id,
            'name'                 => 'Generic Product',
            'sku'                  => 'GEN-001',
            'barcode'              => '1111111111',
            'unit_of_measure'      => 'pc',
            'selling_price'        => 50.00,
            'cost_price'           => 20.00,
            'status'               => 'active',
            'is_inventory_tracked' => false,
        ]);

        // Create an active SalesMachineProfile for the branch
        $this->machineProfile = SalesMachineProfile::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'profile_code' => 'TERM01',
            'machine_identification_number' => 'MIN-123456789',
            'machine_serial_number' => 'SN-ABC123XYZ',
            'software_license_number' => 'LIC-EOPT-2026',
            'permit_to_use_number' => 'PTU-2026-999',
            'permit_issued_at' => now(),
            'status' => 'active',
            'last_invoice_sequence' => 0,
            'grand_cumulative_total' => 0.00,
            'reset_counter' => 0,
            'z_read_counter' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        app(BranchContext::class)->clear();
        parent::tearDown();
    }

    private function postCreateSale(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->cashier)
            ->withHeader('X-Tenant-ID', $this->tenant->id)
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson(route('pos.checkout.create-sale'), $payload);
    }

    // =========================================================================
    // 1. Consequential Sequential Numbering (Gap-free)
    // =========================================================================
    public function test_sale_creation_increments_sequence_consecutively_and_formats_invoice_number(): void
    {
        $payload1 = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ];
        
        $payload2 = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ];

        // First sale
        $response1 = $this->postCreateSale($payload1);
        $response1->assertStatus(200);
        $sale1 = Sale::where('client_request_uuid', $payload1['client_request_uuid'])->firstOrFail();
        
        $this->assertEquals('INV-TERM01-0000000001', $sale1->principal_invoice_number);
        $this->assertEquals('INV-TERM01-0000000001', $sale1->sale_number);
        
        // Assert the SalesMachineProfile database sequence is now 1
        $this->machineProfile->refresh();
        $this->assertEquals(1, $this->machineProfile->last_invoice_sequence);

        // Second sale
        $response2 = $this->postCreateSale($payload2);
        $response2->assertStatus(200);
        $sale2 = Sale::where('client_request_uuid', $payload2['client_request_uuid'])->firstOrFail();

        $this->assertEquals('INV-TERM01-0000000002', $sale2->principal_invoice_number);
        $this->assertEquals('INV-TERM01-0000000002', $sale2->sale_number);

        // Assert the SalesMachineProfile database sequence is now 2
        $this->machineProfile->refresh();
        $this->assertEquals(2, $this->machineProfile->last_invoice_sequence);
    }

    // =========================================================================
    // 2. Transaction Atomicity and Rollback Isolation
    // =========================================================================
    public function test_sequence_update_rolls_back_if_sale_creation_fails(): void
    {
        $this->machineProfile->refresh();
        $initialSequence = $this->machineProfile->last_invoice_sequence; // should be 0

        // We trigger an atomic rollback inside the checkout transaction.
        // We can do this by passing invalid product IDs or causing a failure,
        // but let's test the InvoiceSequenceService directly in a transaction that throws an exception.
        try {
            DB::transaction(function () {
                $sequenceService = app(InvoiceSequenceService::class);
                $number = $sequenceService->generateNextInvoiceNumber($this->machineProfile);
                $this->assertEquals('INV-TERM01-0000000001', $number);

                // Check that inside the transaction, the sequence is temporarily incremented
                $this->machineProfile->refresh();
                $this->assertEquals(1, $this->machineProfile->last_invoice_sequence);

                // Throw exception to trigger rollback
                throw new \Exception('Simulated Sale Insertion Failure');
            });
        } catch (\Exception $e) {
            $this->assertEquals('Simulated Sale Insertion Failure', $e->getMessage());
        }

        // Assert that after rollback, the database sequence is back to the initial value (0)
        $this->machineProfile->refresh();
        $this->assertEquals($initialSequence, $this->machineProfile->last_invoice_sequence);

        // Subsequent valid transaction successfully gets sequence 1 (no gap!)
        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ];
        $response = $this->postCreateSale($payload);
        $response->assertStatus(200);

        $sale = Sale::where('client_request_uuid', $payload['client_request_uuid'])->firstOrFail();
        $this->assertEquals('INV-TERM01-0000000001', $sale->principal_invoice_number);

        $this->machineProfile->refresh();
        $this->assertEquals(1, $this->machineProfile->last_invoice_sequence);
    }

    // =========================================================================
    // 3. Strict Invoice Number Immutability
    // =========================================================================
    public function test_sale_number_and_principal_invoice_number_are_strictly_immutable(): void
    {
        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ];
        $response = $this->postCreateSale($payload);
        $response->assertStatus(200);

        $sale = Sale::first();

        // 1. Try updating principal_invoice_number
        try {
            $sale->update(['principal_invoice_number' => 'INV-TERM01-9999999999']);
            $this->fail('Expected RuntimeException when updating principal_invoice_number.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // 2. Try updating sale_number
        try {
            $sale->update(['sale_number' => 'INV-TERM01-9999999999']);
            $this->fail('Expected RuntimeException when updating sale_number.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // 3. Try updating invoice_issued_at
        try {
            $sale->update(['invoice_issued_at' => now()->addDay()]);
            $this->fail('Expected RuntimeException when updating invoice_issued_at.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Verify the original values remain intact in the database
        $sale->refresh();
        $this->assertEquals('INV-TERM01-0000000001', $sale->principal_invoice_number);
        $this->assertEquals('INV-TERM01-0000000001', $sale->sale_number);
    }

    // =========================================================================
    // 4. Deletions Prohibited
    // =========================================================================
    public function test_sales_are_strictly_protected_from_deletion(): void
    {
        $payload = [
            'client_request_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ];
        $response = $this->postCreateSale($payload);
        $response->assertStatus(200);

        $sale = Sale::first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sales cannot be deleted. Use void/refund protocols.');

        $sale->delete();
    }
}
