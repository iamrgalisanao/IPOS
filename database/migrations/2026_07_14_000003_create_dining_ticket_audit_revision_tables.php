<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_ticket_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('dining_ticket_id')->constrained('dining_tickets')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('operation');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('sales_machine_profiles')->nullOnDelete();
            $table->string('source')->nullable();
            $table->string('reason')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'branch_id']);
            $table->index('dining_ticket_id');
            $table->index(['dining_ticket_id', 'version']);
            $table->index('operation');
            $table->index('actor_user_id');
            $table->index('terminal_id');
            $table->unique(['dining_ticket_id', 'version'], 'dining_ticket_versions_ticket_version_unique');
        });

        Schema::create('dining_ticket_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('dining_ticket_id')->constrained('dining_tickets')->restrictOnDelete();
            $table->uuid('event_uuid')->unique();
            $table->unsignedInteger('event_sequence');
            $table->string('event_type');
            $table->string('summary');
            $table->json('payload')->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('sales_machine_profiles')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'branch_id']);
            $table->index('dining_ticket_id');
            $table->index(['dining_ticket_id', 'event_sequence'], 'dining_ticket_events_ticket_sequence_index');
            $table->index('event_type');
            $table->index('actor_user_id');
            $table->index('terminal_id');
            $table->unique(['dining_ticket_id', 'event_sequence'], 'dining_ticket_events_ticket_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_ticket_events');
        Schema::dropIfExists('dining_ticket_versions');
    }
};
