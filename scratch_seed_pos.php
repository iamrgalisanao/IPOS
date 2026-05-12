<?php

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\BranchInventory;
use App\Services\TenantContext;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Seed data
$tenant = Tenant::updateOrCreate(['name' => 'BMad Coffee'], ['status' => 'active']);
app(TenantContext::class)->setTenant($tenant);

$branch = Branch::updateOrCreate(['name' => 'Main Branch', 'tenant_id' => $tenant->id], ['status' => 'active', 'branch_code' => 'MAIN']);

$cat1 = ProductCategory::updateOrCreate(['name' => 'Beverages', 'code' => 'BEV', 'tenant_id' => $tenant->id]);
$cat2 = ProductCategory::updateOrCreate(['name' => 'Pastries', 'code' => 'PAS', 'tenant_id' => $tenant->id]);

Product::updateOrCreate(['sku' => 'LATTE', 'tenant_id' => $tenant->id], [
    'product_category_id' => $cat1->id,
    'name' => 'Caffè Latte',
    'barcode' => '1001',
    'selling_price' => 4.50,
    'status' => 'active'
]);

Product::updateOrCreate(['sku' => 'CROIS', 'tenant_id' => $tenant->id], [
    'product_category_id' => $cat2->id,
    'name' => 'Butter Croissant',
    'barcode' => '2001',
    'selling_price' => 3.25,
    'status' => 'active'
]);

echo "Seeded BMad Coffee POS data.\n";
