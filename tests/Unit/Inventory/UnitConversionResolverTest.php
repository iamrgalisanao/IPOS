<?php

namespace Tests\Unit\Inventory;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\UnitConversion;
use App\Services\Inventory\UnitConversionResolver;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitConversionResolverTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Product $product;
    protected UnitConversionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        app(TenantContext::class)->setTenant($this->tenant);

        $category = ProductCategory::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'product_category_id' => $category->id,
            'unit_of_measure' => 'gram',
            'status' => 'active',
        ]);

        $this->resolver = app(UnitConversionResolver::class);
    }

    public function test_product_specific_rule_takes_precedence_over_global_rule(): void
    {
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => null,
            'from_unit' => 'scoop',
            'to_unit' => 'gram',
            'conversion_factor' => 20,
            'is_active' => true,
        ]);

        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'from_unit' => 'scoop',
            'to_unit' => 'gram',
            'conversion_factor' => 25,
            'is_active' => true,
        ]);

        $result = $this->resolver->convert(2, 'scoop', 'gram', $this->product->id);

        $this->assertSame(50.0, $result['value']);
        $this->assertSame('product_rule', $result['resolved_by']);
        $this->assertFalse($result['missing']);
    }

    public function test_global_rule_is_used_when_product_rule_is_missing(): void
    {
        UnitConversion::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => null,
            'from_unit' => 'bag',
            'to_unit' => 'kg',
            'conversion_factor' => 5,
            'is_active' => true,
        ]);

        $result = $this->resolver->convert(3, 'bag', 'kg', $this->product->id);

        $this->assertSame(15.0, $result['value']);
        $this->assertSame('global_rule', $result['resolved_by']);
        $this->assertFalse($result['missing']);
    }

    public function test_metric_fallback_converts_known_units(): void
    {
        $result = $this->resolver->convert(1.5, 'kg', 'gram', $this->product->id);

        $this->assertSame(1500.0, $result['value']);
        $this->assertSame('metric_fallback', $result['resolved_by']);
        $this->assertFalse($result['missing']);
    }

    public function test_missing_rule_returns_missing_result_or_throws_in_strict_mode(): void
    {
        $result = $this->resolver->convert(2, 'tray', 'gram', $this->product->id);

        $this->assertSame(2.0, $result['value']);
        $this->assertSame('missing', $result['resolved_by']);
        $this->assertTrue($result['missing']);

        $this->expectException(\RuntimeException::class);
        $this->resolver->convert(2, 'tray', 'gram', $this->product->id, strict: true);
    }
}
