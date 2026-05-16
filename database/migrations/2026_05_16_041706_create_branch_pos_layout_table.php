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
        Schema::create('branch_pos_layout', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('pos_layout_id')->constrained('pos_layouts')->cascadeOnDelete();
            $table->timestamp('active_from')->useCurrent();
            $table->timestamp('active_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // PostgreSQL partial unique index
            // Ensures only one active layout per branch
            // Equivalent to: CREATE UNIQUE INDEX active_branch_layout ON branch_pos_layout (branch_id) WHERE is_active = true;
            // But since this might be SQLite for tests, we will add the unique constraint only if the DB driver supports it or use a standard composite.
            // Actually, we'll try to add it and document the limitation if the local test DB doesn't support it.
            // For Laravel, we can just use raw SQL for Postgres, but let's avoid DB specific raw in migration if we want broad compatibility.
            // We'll enforce this via Application logic (Service tests) as requested by the user.
        });
        
        // Let's add a partial index for PostgreSQL if applicable.
        if (config('database.default') === 'pgsql') {
            \DB::statement('CREATE UNIQUE INDEX active_branch_pos_layout ON branch_pos_layout (branch_id) WHERE is_active = true');
        } else if (config('database.default') === 'sqlite') {
            \DB::statement('CREATE UNIQUE INDEX active_branch_pos_layout ON branch_pos_layout (branch_id) WHERE is_active = 1');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_pos_layout');
    }
};
