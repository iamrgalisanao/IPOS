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
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->renameColumn('requires_reference', 'reference_required');
            $table->boolean('strict_reference_mode')->default(false)->after('requires_reference');
            $table->boolean('settlement_tracking_enabled')->default(false)->after('strict_reference_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->renameColumn('reference_required', 'requires_reference');
            $table->dropColumn(['strict_reference_mode', 'settlement_tracking_enabled']);
        });
    }
};
