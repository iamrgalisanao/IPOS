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
        Schema::create('company_onboarding_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            // Event type classification
            $table->enum('event_type', [
                'onboarding_initialized',
                'branch_created',
                'owner_created',
                'owner_assigned',
                'machine_profile_registered',
                'bootstrap_token_generated',
                'bootstrap_token_used',
                'bootstrap_failed',
                'bootstrap_resent'
            ]);
            
            // Event data (JSON for flexibility)
            $table->json('event_data')->nullable();
            
            // Timestamp
            $table->timestamp('created_at')->useCurrent();
            
            // Indices for efficient querying
            $table->index(['tenant_id', 'created_at']);
            $table->index('event_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_onboarding_events');
    }
};
