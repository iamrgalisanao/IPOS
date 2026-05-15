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
        Schema::table('sales', function (Blueprint $table) {
            $table->string('tax_source_version', 50)->nullable()->after('compliance_version');
            $table->string('tax_computation_source', 50)->nullable()->after('tax_source_version');
            $table->json('tax_profile_snapshot')->nullable()->after('tax_computation_source');
            $table->boolean('is_reversal')->default(false)->after('tax_profile_snapshot');
            $table->foreignUuid('reversal_of_sale_id')
                ->nullable()
                ->after('is_reversal')
                ->constrained('sales')
                ->nullOnDelete();
            $table->string('reversal_reason', 100)->nullable()->after('reversal_of_sale_id');
            $table->json('reversal_tax_impact_snapshot')->nullable()->after('reversal_reason');

            $table->index(['tax_source_version']);
            $table->index(['tax_computation_source']);
            $table->index(['is_reversal']);
            $table->index(['reversal_of_sale_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['tax_source_version']);
            $table->dropIndex(['tax_computation_source']);
            $table->dropIndex(['is_reversal']);
            $table->dropIndex(['reversal_of_sale_id']);
            $table->dropForeign(['reversal_of_sale_id']);

            $table->dropColumn([
                'tax_source_version',
                'tax_computation_source',
                'tax_profile_snapshot',
                'is_reversal',
                'reversal_of_sale_id',
                'reversal_reason',
                'reversal_tax_impact_snapshot',
            ]);
        });
    }
};