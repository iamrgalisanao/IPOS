<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('source', 50)->nullable()->after('tax_profile_snapshot');
            $table->foreignUuid('offline_sales_import_id')
                ->nullable()
                ->after('source')
                ->constrained('offline_sales_imports')
                ->nullOnDelete();
            $table->string('offline_sequence_number')->nullable()->after('offline_sales_import_id');
            $table->timestamp('offline_submitted_at')->nullable()->after('offline_sequence_number');
            $table->timestamp('offline_local_created_at')->nullable()->after('offline_submitted_at');
            $table->timestamp('offline_posted_at')->nullable()->after('offline_local_created_at');

            $table->index(['source']);
            $table->index(['offline_sales_import_id']);
            $table->index(['offline_sequence_number']);
            $table->index(['offline_submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropIndex(['offline_sales_import_id']);
            $table->dropIndex(['offline_sequence_number']);
            $table->dropIndex(['offline_submitted_at']);
            $table->dropForeign(['offline_sales_import_id']);

            $table->dropColumn([
                'source',
                'offline_sales_import_id',
                'offline_sequence_number',
                'offline_submitted_at',
                'offline_local_created_at',
                'offline_posted_at',
            ]);
        });
    }
};
