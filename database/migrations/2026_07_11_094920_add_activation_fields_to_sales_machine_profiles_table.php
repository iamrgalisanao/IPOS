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
        Schema::table('sales_machine_profiles', function (Blueprint $table) {
            $table->string('activation_token_hash')->nullable()->index();
            $table->timestamp('activation_token_expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->uuid('activated_by')->nullable();
            $table->string('activated_device_id')->nullable();
            $table->string('activation_status')->default('active')->index();
            $table->string('last_activated_ip')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_machine_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'activation_token_hash',
                'activation_token_expires_at',
                'activated_at',
                'activated_by',
                'activated_device_id',
                'activation_status',
                'last_activated_ip',
            ]);
        });
    }
};
