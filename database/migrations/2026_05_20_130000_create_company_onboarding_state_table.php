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
        Schema::create('company_onboarding_state', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->unique();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            // Status tracking
            $table->enum('status', ['provisioned', 'branch_created', 'owner_assigned', 'ready'])->default('provisioned');
            
            // Initial branch reference
            $table->uuid('initial_branch_id')->nullable();
            $table->foreign('initial_branch_id')->references('id')->on('branches')->onDelete('set null');
            
            // Owner user reference
            $table->uuid('owner_user_id')->nullable();
            $table->foreign('owner_user_id')->references('id')->on('users')->onDelete('set null');
            
            // Owner contact info
            $table->string('owner_email')->nullable();
            
            // Bootstrap token management
            $table->string('bootstrap_token')->nullable()->unique();
            $table->timestamp('bootstrap_token_expires_at')->nullable();
            $table->integer('bootstrap_attempts')->default(0);
            $table->timestamp('bootstrap_locked_until')->nullable();
            
            // Completion tracking
            $table->timestamp('completed_at')->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Indices
            $table->index(['tenant_id', 'status']);
            $table->index('bootstrap_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_onboarding_state');
    }
};
