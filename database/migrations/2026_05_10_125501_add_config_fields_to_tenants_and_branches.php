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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('currency')->default('PHP')->after('status');
            $table->string('timezone')->default('Asia/Manila')->after('currency');
            $table->string('tax_mode')->default('exclusive')->after('timezone');
            $table->text('receipt_header')->nullable()->after('tax_mode');
            $table->text('receipt_footer')->nullable()->after('receipt_header');
            $table->string('business_registration_number')->nullable()->after('receipt_footer');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->text('address')->nullable()->after('status');
            $table->string('contact_number')->nullable()->after('address');
            $table->string('receipt_prefix')->nullable()->after('contact_number');
            $table->unsignedInteger('receipt_next_number')->default(1)->after('receipt_prefix');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'currency', 'timezone', 'tax_mode', 
                'receipt_header', 'receipt_footer', 
                'business_registration_number'
            ]);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'address', 'contact_number', 
                'receipt_prefix', 'receipt_next_number'
            ]);
        });
    }
};
