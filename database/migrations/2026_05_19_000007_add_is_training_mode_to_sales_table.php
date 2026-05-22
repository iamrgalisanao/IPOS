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
         Schema::table('sales', function (Blueprint $table) {
             $table->boolean('is_training_mode')->default(false)->after('status');
             $table->index(['tenant_id', 'branch_id', 'is_training_mode']);
         });

         Schema::table('checkout_requests', function (Blueprint $table) {
             $table->boolean('is_training_mode')->default(false)->after('status');
             $table->index(['tenant_id', 'branch_id', 'is_training_mode']);
         });
     }

    /**
     * Reverse the migrations.
     */
    public function down(): void
     {
         Schema::table('sales', function (Blueprint $table) {
             $table->dropIndex(['tenant_id', 'branch_id', 'is_training_mode']);
             $table->dropColumn('is_training_mode');
         });

         Schema::table('checkout_requests', function (Blueprint $table) {
             $table->dropIndex(['tenant_id', 'branch_id', 'is_training_mode']);
             $table->dropColumn('is_training_mode');
         });
     }
};
