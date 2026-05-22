<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\OfflineSalesImport;
use App\Models\OfflineSyncBatch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesMachineProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfflineImportPostingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $admin;
    protected SalesMachineProfile $profile;
    protected OfflineSyncBatch $batch;
    protected Product $product;
    protected PaymentMethod $paymentMethod;

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

        $this->admin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'actor_type' => 'tenant_user',
            'status'     => 'active',
        ]);
        $this->admin->assignRole(Role::where('name', 'Owner/Admin')->firstOrFail());

        $this->profile = SalesMachineProfile::create([
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
            'offline_sequence_prefix'              => 'INV-',
            'offline_sequence_next_value'          => 1,
            'offline_sequence_status'              => 'active',
        ]);

        $this->batch = OfflineSyncBatch::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_reference'          => 'BATCH-001',
            'status'                   => OfflineSyncBatch::STATUS_COMPLETED,
            'submitted_import_count'   => 1,
        ]);

        $taxCategory = \App\Models\TaxCategory::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'VAT',
            'code' => 'VAT',
            'tax_type' => 'vatable',
            'rate' => 12.00,
            'status' => 'active',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'tax_category_id' => $taxCategory->id,
            'selling_price' => 100.00,
            'is_inventory_tracked' => true,
        ]);

        // Seed some initial inventory
        \App\Models\BranchInventory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'current_stock' => 50,
        ]);

        $this->paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'cash',
            'status' => 'active',
        ]);
    }

    protected function createImport(string $status, bool $withPayment = false): OfflineSalesImport
    {
        $serverRecalc = [
            'server_subtotal' => '100.0000',
            'server_tax_total' => '10.7143', // Example inclusive tax
            'server_total' => '100.0000',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'product_name' => 'Test Product',
                    'sku' => 'SKU-01',
                    'barcode' => 'BAR-01',
                    'unit_of_measure' => 'piece',
                    'quantity' => '1.0000',
                    'selling_price' => '100.0000',
                    'subtotal' => '100.0000',
                    'tax_category_id' => $this->product->tax_category_id,
                    'tax_type' => 'vatable',
                    'tax_bucket' => SaleItem::TAX_BUCKET_VATABLE,
                    'tax_rate' => '12.0000',
                    'tax_amount' => '10.7143',
                    'tax_snapshot' => [],
                    'is_inventory_tracked' => true,
                ]
            ],
            'client_submitted' => [
                'client_subtotal' => '100.0000',
                'client_tax_total' => '10.7143',
                'client_total' => '100.0000',
            ]
        ];

        $rawPayload = [
            'user_id' => $this->admin->id,
            'client_request_uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'submitted_at' => now()->toIso8601String(),
        ];

        if ($withPayment) {
            $rawPayload['payments'] = [
                [
                    'payment_method_id' => $this->paymentMethod->id,
                    'amount' => '100.00',
                ]
            ];
        }

        return OfflineSalesImport::create([
            'tenant_id'                => $this->tenant->id,
            'branch_id'                => $this->branch->id,
            'sales_machine_profile_id' => $this->profile->id,
            'batch_id'                 => $this->batch->id,
            'offline_sequence_number'  => 'INV-001',
            'payload_hash'             => hash('sha256', uniqid()),
            'raw_payload'              => $rawPayload,
            'server_recalculation'     => $serverRecalc,
            'status'                   => $status,
            'submitted_at'             => now(),
        ]);
    }

    public function test_tc_28_10_01_server_verified_import_can_be_posted()
    {
        $import = $this->createImport('server_verified', true);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/offline-sync/imports/{$import->id}/post");

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'sale_id']);

        $import->refresh();
        $this->assertEquals('posted', $import->status);
        $this->assertNotNull($import->reconciled_sale_id);
        $this->assertNotNull($import->reconciled_at);

        $sale = Sale::find($import->reconciled_sale_id);
        $this->assertEquals('100.0000', $sale->total);
        $this->assertEquals('paid', $sale->status); // Because payments were provided
        $this->assertEquals(1, $sale->items()->count());
        $this->assertEquals(1, $sale->payments()->count());
    }

    public function test_tc_28_10_02_override_approved_import_can_be_posted()
    {
        $import = $this->createImport('override_approved', true);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/offline-sync/imports/{$import->id}/post");

        $response->assertStatus(200);

        $import->refresh();
        $this->assertEquals('posted', $import->status);
        $this->assertNotNull($import->reconciled_sale_id);
    }

    public function test_tc_28_10_03_ineligible_imports_cannot_be_posted()
    {
        $statuses = ['conflict', 'hold', 'rejected', 'duplicate', 'pending'];

        foreach ($statuses as $status) {
            $import = $this->createImport($status);

            $response = $this->actingAs($this->admin, 'sanctum')
                ->postJson("/api/admin/offline-sync/imports/{$import->id}/post");

            $response->assertStatus(422);
            $this->assertNull($import->fresh()->reconciled_sale_id);
        }
    }

    public function test_tc_28_10_04_posted_import_cannot_be_posted_again()
    {
        $import = $this->createImport('server_verified', true);

        // First post
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/offline-sync/imports/{$import->id}/post");
        $response->assertStatus(200);
        
        $saleId = $response->json('sale_id');

        // Second post (Idempotency check)
        $response2 = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/offline-sync/imports/{$import->id}/post");
        
        $response2->assertStatus(200); // Should just return success and the same sale_id
        $this->assertEquals($saleId, $response2->json('sale_id'));
        
        $this->assertEquals(1, Sale::where('id', $saleId)->count());
    }

    public function test_tc_28_10_05_inventory_deduction_happens()
    {
        $import = $this->createImport('server_verified', true);

        $initialQuantity = \App\Models\BranchInventory::where('product_id', $this->product->id)->sum('current_stock');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/offline-sync/imports/{$import->id}/post");

        $response->assertStatus(200);

        $newQuantity = \App\Models\BranchInventory::where('product_id', $this->product->id)->sum('current_stock');
        
        // Product quantity was 1 in the recalc
        $this->assertEquals((float) $initialQuantity - 1, (float) $newQuantity);
    }

    public function test_tc_28_10_06_failed_posting_rolls_back_all_changes()
    {
        $import = $this->createImport('server_verified');
        
        // Break the payload to cause a failure (e.g. invalid payment method)
        $payload = $import->raw_payload;
        $payload['payments'] = [
            ['payment_method_id' => '999999', 'amount' => '100.00']
        ];
        $import->update(['raw_payload' => $payload]);

        $initialSaleCount = Sale::count();
        $initialItemCount = SaleItem::count();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/offline-sync/imports/{$import->id}/post");

        $response->assertStatus(422);

        $this->assertEquals($initialSaleCount, Sale::count());
        $this->assertEquals($initialItemCount, SaleItem::count());
        $this->assertEquals('server_verified', $import->fresh()->status);
        $this->assertNull($import->fresh()->reconciled_sale_id);
    }

    public function test_tc_28_10_07_posting_requires_fully_paid_payload()
    {
        $import = $this->createImport('server_verified', true);

        $payload = $import->raw_payload;
        $payload['payments'][0]['amount'] = '50.00';
        $import->update(['raw_payload' => $payload]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/offline-sync/imports/{$import->id}/post");

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Offline posting requires fully paid imports only.',
        ]);

        $this->assertNull($import->fresh()->reconciled_sale_id);
        $this->assertEquals('server_verified', $import->fresh()->status);
    }

    public function test_tc_28_10_08_posting_rejects_missing_payments()
    {
        $import = $this->createImport('server_verified', false);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/offline-sync/imports/{$import->id}/post");

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Offline posting requires at least one payment entry.',
        ]);

        $this->assertNull($import->fresh()->reconciled_sale_id);
    }

    public function test_tc_28_10_09_posted_sale_persists_offline_metadata()
    {
        $import = $this->createImport('server_verified', true);

        $payload = $import->raw_payload;
        $payload['local_created_at'] = now()->subMinutes(5)->toIso8601String();
        $import->update(['raw_payload' => $payload]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/offline-sync/imports/{$import->id}/post");

        $response->assertStatus(200);

        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertEquals('offline_reconciliation', $sale->source);
        $this->assertEquals($import->id, $sale->offline_sales_import_id);
        $this->assertEquals($import->offline_sequence_number, $sale->offline_sequence_number);
        $this->assertNotNull($sale->offline_submitted_at);
        $this->assertNotNull($sale->offline_local_created_at);
        $this->assertNotNull($sale->offline_posted_at);
    }
}
