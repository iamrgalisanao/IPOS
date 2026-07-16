<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_recipes', function (Blueprint $table) {
            $table->uuid('recipe_line_uuid')->nullable()->after('id');
            $table->unsignedSmallInteger('recipe_schema_version')->default(1)->after('recipe_line_uuid');
            $table->unsignedInteger('recipe_version')->default(1)->after('recipe_schema_version');
            $table->boolean('is_active')->default(true)->after('unit');
            $table->string('active_slot', 80)->default('active')->after('is_active');
            $table->foreignUuid('supersedes_recipe_id')->nullable()->after('active_slot')->constrained('product_recipes')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('supersedes_recipe_id');
            $table->json('metadata')->nullable()->after('locked_at');
        });

        DB::table('product_recipes')
            ->whereNull('recipe_line_uuid')
            ->orderBy('id')
            ->get()
            ->each(function ($recipe) {
                DB::table('product_recipes')
                    ->where('id', $recipe->id)
                    ->update([
                        'recipe_line_uuid' => (string) Str::orderedUuid(),
                        'recipe_schema_version' => 1,
                        'recipe_version' => 1,
                        'is_active' => true,
                        'active_slot' => 'active',
                    ]);
            });

        Schema::table('product_recipes', function (Blueprint $table) {
            $table->dropUnique('product_recipe_unique');
            $table->unique(['tenant_id', 'product_id', 'ingredient_id', 'active_slot'], 'product_recipes_active_ingredient_unique');
            $table->unique(['recipe_line_uuid', 'recipe_version'], 'product_recipes_line_version_unique');
            $table->index(['tenant_id', 'product_id', 'is_active'], 'product_recipes_active_lookup');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('source_effect_key', 255)->nullable()->change();
            $table->foreignUuid('sale_item_id')->nullable()->after('branch_inventory_id')->constrained('sale_items')->restrictOnDelete();
            $table->foreignUuid('parent_product_id')->nullable()->after('sale_item_id')->constrained('products')->restrictOnDelete();
            $table->uuid('recipe_line_uuid')->nullable()->after('parent_product_id');
            $table->uuid('recipe_batch_uuid')->nullable()->after('recipe_line_uuid');

            $table->index(['tenant_id', 'branch_id', 'sale_item_id'], 'idx_inventory_movements_sale_item');
            $table->index(['tenant_id', 'branch_id', 'parent_product_id'], 'idx_inventory_movements_parent_product');
            $table->index(['tenant_id', 'branch_id', 'recipe_line_uuid'], 'idx_inventory_movements_recipe_line');
            $table->index(['tenant_id', 'branch_id', 'recipe_batch_uuid'], 'idx_inventory_movements_recipe_batch');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('idx_inventory_movements_sale_item');
            $table->dropIndex('idx_inventory_movements_parent_product');
            $table->dropIndex('idx_inventory_movements_recipe_line');
            $table->dropIndex('idx_inventory_movements_recipe_batch');
            $table->dropConstrainedForeignId('sale_item_id');
            $table->dropConstrainedForeignId('parent_product_id');
            $table->dropColumn(['recipe_line_uuid', 'recipe_batch_uuid']);
            $table->string('source_effect_key', 160)->nullable()->change();
        });

        Schema::table('product_recipes', function (Blueprint $table) {
            $table->dropUnique('product_recipes_active_ingredient_unique');
            $table->dropUnique('product_recipes_line_version_unique');
            $table->dropIndex('product_recipes_active_lookup');
            $table->dropForeign(['supersedes_recipe_id']);
            $table->dropColumn([
                'recipe_line_uuid',
                'recipe_schema_version',
                'recipe_version',
                'is_active',
                'active_slot',
                'supersedes_recipe_id',
                'locked_at',
                'metadata',
            ]);
        });
    }
};
