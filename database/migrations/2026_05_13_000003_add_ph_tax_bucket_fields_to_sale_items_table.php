<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('tax_bucket', 32)->nullable()->after('tax_type');
            $table->decimal('net_amount', 19, 4)->nullable()->after('tax_amount');
            $table->decimal('vatable_amount', 19, 4)->nullable()->after('net_amount');
            $table->decimal('vat_exempt_amount', 19, 4)->nullable()->after('vatable_amount');
            $table->decimal('zero_rated_amount', 19, 4)->nullable()->after('vat_exempt_amount');
            $table->decimal('non_vat_amount', 19, 4)->nullable()->after('zero_rated_amount');
            $table->string('tax_source', 64)->nullable()->after('non_vat_amount');
            $table->json('tax_snapshot')->nullable()->after('tax_source');

            $table->index(['sale_id', 'tax_bucket']);
            $table->index(['tenant_id', 'branch_id', 'tax_bucket']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['sale_id', 'tax_bucket']);
            $table->dropIndex(['tenant_id', 'branch_id', 'tax_bucket']);

            $table->dropColumn([
                'tax_bucket',
                'net_amount',
                'vatable_amount',
                'vat_exempt_amount',
                'zero_rated_amount',
                'non_vat_amount',
                'tax_source',
                'tax_snapshot',
            ]);
        });
    }
};