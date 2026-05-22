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
        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stocktake_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            
            $table->decimal('expected_quantity', 19, 4)->default(0);
            $table->decimal('counted_quantity', 19, 4)->default(0);
            $table->decimal('variance_quantity', 19, 4)->default(0);
            
            $table->string('reason_code')->nullable();
            $table->text('remarks')->nullable();
            
            $table->foreignUuid('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'stocktake_session_id', 'product_id'], 'idx_stocktake_lines_scoped');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocktake_lines');
    }
};
