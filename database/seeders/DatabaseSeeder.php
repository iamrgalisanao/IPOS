<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\ProductCategory;
use App\Models\Product;
use App\Models\BranchInventory;
use App\Models\ExpiryLot;
use App\Models\TaxCategory;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Role;
use App\Services\RbacSeeder;
use App\Services\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Tenant
        $tenant = Tenant::create([
            'name' => 'BMad Coffee',
            'status' => 'active',
            'currency' => 'PHP',
            'timezone' => 'Asia/Manila',
            'tax_mode' => 'inclusive',
            'subscription_metadata' => [
                'plan' => 'enterprise',
                'features' => [
                    'sales.pos' => true,
                    'catalog.view' => true,
                    'catalog.edit' => true,
                    'reports.basic' => true,
                    'reports.advanced' => true,
                    'procurement.basic' => true,
                    'procurement.advanced' => true,
                    'quickbooks.sync' => true,
                    'layout.custom' => true,
                ],
                'limits' => [
                    'max_branches' => 99,
                    'max_users' => 99,
                ]
            ]
        ]);

        // Set active tenant context for database scope triggers
        $tenantContext = app(TenantContext::class);
        $tenantContext->setTenant($tenant);

        // 2. Seed Roles and Permissions
        $rbacSeeder = new RbacSeeder();
        $rbacSeeder->seedForTenant($tenant);

        // Re-establish tenant context because RbacSeeder clears it
        $tenantContext->setTenant($tenant);

        // 3. Create Branches
        $branchMain = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Corporate Branch',
            'branch_code' => 'MAIN',
            'status' => 'active',
        ]);

        $branchExpress = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Express Branch',
            'branch_code' => 'EXPR',
            'status' => 'active',
        ]);

        // 4. Create Users
        $ownerRole = Role::where('name', 'Owner/Admin')->first();
        $cashierRole = Role::where('name', 'Cashier')->first();

        // Admin User (BMad Coffee)
        $adminBmad = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'BMad Admin',
            'email' => 'admin@bmad.coffee',
            'password' => Hash::make('password'),
            'status' => 'active',
            'actor_type' => 'tenant_user',
        ]);
        $adminBmad->assignRole($ownerRole);
        $adminBmad->assignToBranch($branchMain);
        $adminBmad->assignToBranch($branchExpress);

        // Cashier User (BMad Coffee)
        $cashierBmad = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'BMad Cashier',
            'email' => 'cashier@bmad.coffee',
            'password' => Hash::make('password'),
            'status' => 'active',
            'actor_type' => 'tenant_user',
        ]);
        $cashierBmad->assignRole($cashierRole);
        $cashierBmad->assignToBranch($branchMain);

        // Legacy Admin User (example.com)
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Acme Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'status' => 'active',
            'actor_type' => 'tenant_user',
        ]);
        $admin->assignRole($ownerRole);
        $admin->assignToBranch($branchMain);
        $admin->assignToBranch($branchExpress);

        // Legacy Cashier User (example.com)
        $cashier = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Acme Cashier',
            'email' => 'cashier@example.com',
            'password' => Hash::make('password'),
            'status' => 'active',
            'actor_type' => 'tenant_user',
        ]);
        $cashier->assignRole($cashierRole);
        $cashier->assignToBranch($branchMain);

        // Default legacy test user as requested / fallback
        $testUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'status' => 'active',
            'actor_type' => 'tenant_user',
        ]);
        $testUser->assignRole($ownerRole);
        $testUser->assignToBranch($branchMain);

        // 5. Create Default Tax Categories
        $vatable = TaxCategory::create([
            'tenant_id' => $tenant->id,
            'code' => 'VAT',
            'name' => 'VATable standard rate',
            'description' => 'Standard VAT rate (12%)',
            'tax_type' => 'vatable',
            'rate' => 12.00,
            'is_default' => true,
            'status' => 'active',
        ]);

        $exempt = TaxCategory::create([
            'tenant_id' => $tenant->id,
            'code' => 'VAT-EXM',
            'name' => 'VAT Exempt',
            'description' => 'VAT Exempt Transactions',
            'tax_type' => 'exempt',
            'rate' => 0.00,
            'is_default' => false,
            'status' => 'active',
        ]);

        // 6. Create Default Payment Methods
        PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'reference_required' => false,
            'strict_reference_mode' => false,
            'settlement_tracking_enabled' => true,
            'is_default' => true,
            'status' => 'active',
        ]);

        PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'code' => 'CARD',
            'name' => 'Credit / Debit Card',
            'type' => 'card',
            'reference_required' => true,
            'strict_reference_mode' => false,
            'settlement_tracking_enabled' => true,
            'is_default' => false,
            'status' => 'active',
        ]);

        // 7. Create Product Categories
        $beverages = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Beverages',
            'code' => 'CAT-BEV',
            'description' => 'Cold soft drinks, juices, and water',
            'status' => 'active',
        ]);

        $perishables = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Perishable Goods',
            'code' => 'CAT-PER',
            'description' => 'Dairy, bread, fresh produce, and meats',
            'status' => 'active',
        ]);

        // 8. Create Products
        // A. Perishable Products (Expiry & FEFO Enabled)
        $milk = Product::create([
            'tenant_id' => $tenant->id,
            'product_category_id' => $perishables->id,
            'name' => 'Fresh Whole Milk 1L',
            'sku' => 'PRD-MILK-01',
            'barcode' => '4800000000011',
            'selling_price' => 95.00,
            'cost_price' => 70.00,
            'is_discountable' => true,
            'is_taxable' => true,
            'is_inventory_tracked' => true,
            'tax_category_id' => $vatable->id,
            'status' => 'active',
            'product_type' => 'physical',
            'is_sellable' => true,
            'expiry_tracking_enabled' => true,
        ]);

        $bread = Product::create([
            'tenant_id' => $tenant->id,
            'product_category_id' => $perishables->id,
            'name' => 'Sliced Artisan Sourdough',
            'sku' => 'PRD-SOURD-02',
            'barcode' => '4800000000022',
            'selling_price' => 120.00,
            'cost_price' => 80.00,
            'is_discountable' => true,
            'is_taxable' => false,
            'is_inventory_tracked' => true,
            'tax_category_id' => $exempt->id,
            'status' => 'active',
            'product_type' => 'physical',
            'is_sellable' => true,
            'expiry_tracking_enabled' => true,
        ]);

        // B. Non-Perishable Products (Normal Inventory)
        $coke = Product::create([
            'tenant_id' => $tenant->id,
            'product_category_id' => $beverages->id,
            'name' => 'Coca Cola Can 330ml',
            'sku' => 'PRD-COKE-03',
            'barcode' => '4800000000033',
            'selling_price' => 45.00,
            'cost_price' => 25.00,
            'is_discountable' => true,
            'is_taxable' => true,
            'is_inventory_tracked' => true,
            'tax_category_id' => $vatable->id,
            'status' => 'active',
            'product_type' => 'physical',
            'is_sellable' => true,
            'expiry_tracking_enabled' => false,
        ]);

        $water = Product::create([
            'tenant_id' => $tenant->id,
            'product_category_id' => $beverages->id,
            'name' => 'Mineral Spring Water 500ml',
            'sku' => 'PRD-WATER-04',
            'barcode' => '4800000000044',
            'selling_price' => 20.00,
            'cost_price' => 10.00,
            'is_discountable' => true,
            'is_taxable' => true,
            'is_inventory_tracked' => true,
            'tax_category_id' => $vatable->id,
            'status' => 'active',
            'product_type' => 'physical',
            'is_sellable' => true,
            'expiry_tracking_enabled' => false,
        ]);

        // 9. Create Branch Inventories & Expiry Lots
        // Main Branch Stock
        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchMain->id,
            'product_id' => $milk->id,
            'current_stock' => 25.0000,
            'average_cost' => 70.0000,
            'reorder_level' => 10.0000,
            'status' => 'active',
        ]);

        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchMain->id,
            'product_id' => $bread->id,
            'current_stock' => 15.0000,
            'average_cost' => 80.0000,
            'reorder_level' => 5.0000,
            'status' => 'active',
        ]);

        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchMain->id,
            'product_id' => $coke->id,
            'current_stock' => 100.0000,
            'average_cost' => 25.0000,
            'reorder_level' => 20.0000,
            'status' => 'active',
        ]);

        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchMain->id,
            'product_id' => $water->id,
            'current_stock' => 150.0000,
            'average_cost' => 10.0000,
            'reorder_level' => 30.0000,
            'status' => 'active',
        ]);

        // Express Branch Stock
        BranchInventory::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchExpress->id,
            'product_id' => $milk->id,
            'current_stock' => 5.0000,
            'average_cost' => 70.0000,
            'reorder_level' => 2.0000,
            'status' => 'active',
        ]);

        // Expiry Lots for Milk (Main Branch): FEFO Ordering Check
        // Lot 1: Expiring soonest (expires in 2 days) -> Should be depleted first
        ExpiryLot::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchMain->id,
            'product_id' => $milk->id,
            'batch_code' => 'LOT-MILK-A-SOON',
            'quantity_received' => 10.0000,
            'quantity_remaining' => 10.0000,
            'expiry_date' => now()->addDays(2),
            'status' => 'active',
        ]);

        // Lot 2: Expiring later (expires in 10 days) -> Should be depleted second
        ExpiryLot::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchMain->id,
            'product_id' => $milk->id,
            'batch_code' => 'LOT-MILK-B-LATE',
            'quantity_received' => 15.0000,
            'quantity_remaining' => 15.0000,
            'expiry_date' => now()->addDays(10),
            'status' => 'active',
        ]);

        // Lot 3: ALREADY EXPIRED 3 days ago -> Should be completely ignored by POS
        ExpiryLot::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchMain->id,
            'product_id' => $milk->id,
            'batch_code' => 'LOT-MILK-C-EXPIRED',
            'quantity_received' => 5.0000,
            'quantity_remaining' => 5.0000,
            'expiry_date' => now()->subDays(3),
            'status' => 'active', // Active status but expired date
        ]);

        // Expiry Lots for Sourdough Bread (Main Branch)
        // Lot 4: Expiring in 4 days
        ExpiryLot::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchMain->id,
            'product_id' => $bread->id,
            'batch_code' => 'LOT-BREAD-01',
            'quantity_received' => 15.0000,
            'quantity_remaining' => 15.0000,
            'expiry_date' => now()->addDays(4),
            'status' => 'active',
        ]);

        // Clean tenant context setup for final runtime integrity
        $tenantContext->clear();
    }
}
