<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_ticket_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('next_sequence')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id', 'business_date'], 'dining_ticket_sequences_branch_date_unique');
        });

        Schema::create('dining_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('ticket_number');
            $table->string('status')->default('open');
            $table->unsignedSmallInteger('guest_count')->default(1);
            $table->unsignedBigInteger('subtotal_centavos')->default(0);
            $table->unsignedBigInteger('discount_centavos')->default(0);
            $table->unsignedBigInteger('service_charge_centavos')->default(0);
            $table->unsignedBigInteger('tax_centavos')->default(0);
            $table->unsignedBigInteger('grand_total_centavos')->default(0);
            $table->foreignUuid('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('parent_ticket_id')->nullable()->constrained('dining_tickets')->nullOnDelete();
            $table->foreignUuid('source_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('sales_machine_profiles')->nullOnDelete();
            $table->unsignedInteger('ticket_revision')->default(1);
            $table->uuid('reservation_id')->nullable();
            $table->uuid('checkout_request_uuid')->nullable()->unique();
            $table->uuid('client_request_uuid')->nullable();
            $table->string('client_request_fingerprint', 64)->nullable();
            $table->string('pricing_engine_version')->nullable();
            $table->string('tax_engine_version')->nullable();
            $table->string('discount_engine_version')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->index(['branch_id', 'ticket_number']);
            $table->index('terminal_id');
            $table->index('opened_by');
            $table->index('parent_ticket_id');
            $table->index('source_sale_id');
            $table->unique(['tenant_id', 'branch_id', 'ticket_number'], 'dining_tickets_branch_number_unique');
            $table->unique(['tenant_id', 'branch_id', 'client_request_uuid'], 'dining_tickets_client_request_unique');
        });

        Schema::create('dining_ticket_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('dining_ticket_id')->constrained('dining_tickets')->cascadeOnDelete();
            $table->foreignUuid('dining_table_id')->constrained('dining_tables')->restrictOnDelete();
            $table->string('role');
            $table->timestamp('attached_at');
            $table->timestamp('detached_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id']);
            $table->index('dining_ticket_id');
            $table->index('dining_table_id');
            $table->index(['dining_table_id', 'role', 'detached_at'], 'dining_ticket_tables_active_lookup');
        });

        Schema::create('dining_ticket_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('dining_ticket_id')->constrained('dining_tickets')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedSmallInteger('seat_number')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->unsignedBigInteger('unit_price_centavos')->default(0);
            $table->unsignedBigInteger('line_total_centavos')->default(0);
            $table->string('status')->default('open');
            $table->foreignUuid('source_item_id')->nullable()->constrained('dining_ticket_items')->nullOnDelete();
            $table->unsignedSmallInteger('course_no')->nullable();
            $table->string('fire_group')->nullable();
            $table->timestamp('hold_until')->nullable();
            $table->uuid('preparation_station_id')->nullable();
            $table->json('promotion_allocation_snapshot')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id']);
            $table->index('dining_ticket_id');
            $table->index('product_id');
            $table->index('source_item_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_ticket_items');
        Schema::dropIfExists('dining_ticket_tables');
        Schema::dropIfExists('dining_tickets');
        Schema::dropIfExists('dining_ticket_sequences');
    }
};
