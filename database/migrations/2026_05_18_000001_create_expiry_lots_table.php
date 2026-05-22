<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::connection(null)->getConnection()->getDriverName() === 'sqlite') {
            // SQLite in-memory representation with inline CHECK and FOREIGN KEY constraints for tests
            DB::statement('
                CREATE TABLE expiry_lots (
                    id TEXT PRIMARY KEY NOT NULL,
                    tenant_id TEXT NOT NULL,
                    branch_id TEXT NOT NULL,
                    product_id TEXT NOT NULL,
                    purchase_receiving_line_id TEXT,
                    batch_code TEXT NOT NULL,
                    quantity_received NUMERIC NOT NULL,
                    quantity_remaining NUMERIC NOT NULL,
                    expiry_date TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT "active",
                    created_at DATETIME,
                    updated_at DATETIME,
                    FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
                    FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE CASCADE,
                    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
                    FOREIGN KEY (purchase_receiving_line_id) REFERENCES purchase_receiving_lines (id) ON DELETE SET NULL,
                    UNIQUE (tenant_id, branch_id, product_id, batch_code),
                    CHECK (quantity_received >= 0.0000),
                    CHECK (quantity_remaining >= 0.0000)
                )
            ');

            // SQLite Indexes
            DB::statement('CREATE INDEX expiry_lots_tenant_branch_status_idx ON expiry_lots (tenant_id, branch_id, status)');
        } else {
            // PostgreSQL / MySQL standard blueprint layout with raw table level check constraints
            Schema::create('expiry_lots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignUuid('purchase_receiving_line_id')
                    ->nullable()
                    ->constrained('purchase_receiving_lines')
                    ->nullOnDelete();
                
                $table->string('batch_code');
                $table->decimal('quantity_received', 19, 4);
                $table->decimal('quantity_remaining', 19, 4);
                $table->date('expiry_date');
                $table->string('status')->default('active'); // active, depleted, wasted
                $table->timestamps();

                // Unique Constraints
                $table->unique(['tenant_id', 'branch_id', 'product_id', 'batch_code']);
                
                // Indexes
                $table->index(['tenant_id', 'branch_id', 'status']);
            });

            // Raw DB level constraints for production engines
            DB::statement('ALTER TABLE expiry_lots ADD CONSTRAINT quantity_received_non_negative CHECK (quantity_received >= 0.0000)');
            DB::statement('ALTER TABLE expiry_lots ADD CONSTRAINT quantity_remaining_non_negative CHECK (quantity_remaining >= 0.0000)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expiry_lots');
    }
};
