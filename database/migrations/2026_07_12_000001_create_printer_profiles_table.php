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
        Schema::create('printer_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name');
            $table->string('connection_type'); // usb, network, bluetooth, browser_print, system_default
            $table->string('identifier')->nullable(); // IP Address/MAC/Port
            $table->string('paper_width')->default('80mm'); // 58mm, 80mm
            $table->string('role')->default('receipt'); // receipt, kitchen, label, report, cash_drawer
            $table->string('template_type')->default('standard'); // standard, kitchen, custom
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'role', 'is_active', 'is_default'], 'printer_profiles_resolution_index');
        });

        Schema::table('sales_machine_profiles', function (Blueprint $table) {
            $table->foreignUuid('printer_profile_id')
                ->nullable()
                ->constrained('printer_profiles')
                ->nullOnDelete();
            $table->index(['tenant_id', 'branch_id', 'printer_profile_id'], 'sales_machine_printer_scope_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_machine_profiles', function (Blueprint $table) {
            $table->dropIndex('sales_machine_printer_scope_index');
            $table->dropForeign(['printer_profile_id']);
            $table->dropColumn('printer_profile_id');
        });

        Schema::dropIfExists('printer_profiles');
    }
};
