<?php

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\BranchInventory;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require_once dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Seed data
$tenant = Tenant::updateOrCreate(['name' => 'BMad Coffee'], ['status' => 'active']);
app(TenantContext::class)->setTenant($tenant);

$branch = Branch::updateOrCreate(['name' => 'Main Branch', 'tenant_id' => $tenant->id], [
    'status' => 'active', 
    'branch_code' => 'MAIN'
]);

$user = User::updateOrCreate(['email' => 'admin@bmad.coffee'], [
    'name' => 'BMad Admin',
    'password' => Hash::make('password'),
    'tenant_id' => $tenant->id,
    'status' => 'active'
]);

// Assign user to branch
if (!$user->branches()->where('branches.id', $branch->id)->exists()) {
    $user->branches()->attach($branch->id);
}

$cat1 = ProductCategory::updateOrCreate(['name' => 'Beverages', 'code' => 'BEV', 'tenant_id' => $tenant->id]);
$cat2 = ProductCategory::updateOrCreate(['name' => 'Pastries', 'code' => 'PAS', 'tenant_id' => $tenant->id]);

$p1 = Product::updateOrCreate(['sku' => 'LATTE', 'tenant_id' => $tenant->id], [
    'product_category_id' => $cat1->id,
    'name' => 'Caffè Latte',
    'barcode' => '1001',
    'selling_price' => 150.00,
    'is_inventory_tracked' => true,
    'status' => 'active'
]);

BranchInventory::updateOrCreate([
    'branch_id' => $branch->id,
    'product_id' => $p1->id,
    'tenant_id' => $tenant->id
], [
    'current_stock' => 100,
    'reorder_level' => 10
]);

$p2 = Product::updateOrCreate(['sku' => 'CROIS', 'tenant_id' => $tenant->id], [
    'product_category_id' => $cat2->id,
    'name' => 'Butter Croissant',
    'barcode' => '2001',
    'selling_price' => 85.00,
    'is_inventory_tracked' => true,
    'status' => 'active'
]);

BranchInventory::updateOrCreate([
    'branch_id' => $branch->id,
    'product_id' => $p2->id,
    'tenant_id' => $tenant->id
], [
    'current_stock' => 50,
    'reorder_level' => 5
]);

echo "Seeded BMad Coffee POS data, admin user (admin@bmad.coffee / password), and stock levels.\n";
