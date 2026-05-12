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
        Schema::create('support_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type');
            $table->foreignUuid('support_session_id')->nullable()->constrained('support_access_sessions')->nullOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('route_name')->nullable();
            $table->string('path')->nullable();
            $table->string('method')->nullable();
            $table->string('status')->default('allowed');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'status']);
            $table->index('support_session_id');
            $table->index('actor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_audit_events');
    }
};