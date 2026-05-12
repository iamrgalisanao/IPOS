<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_requests', function (Blueprint $table) {
            // Links an existing CheckoutRequest to the Sale it eventually created.
            // Null = validated but sale not yet created.
            // Non-null = sale already created; use for idempotent duplicate detection.
            $table->uuid('sale_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_requests', function (Blueprint $table) {
            $table->dropColumn('sale_id');
        });
    }
};
