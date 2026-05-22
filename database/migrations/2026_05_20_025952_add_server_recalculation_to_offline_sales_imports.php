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
        Schema::table('offline_sales_imports', function (Blueprint $table) {
            $table->jsonb('server_recalculation')->nullable()->after('raw_payload');
            $table->text('conflict_notes')->nullable()->after('server_recalculation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offline_sales_imports', function (Blueprint $table) {
            $table->dropColumn('server_recalculation');
            $table->dropColumn('conflict_notes');
        });
    }
};
