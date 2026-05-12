<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_outbox', function (Blueprint $table) {
            $table->string('sync_error_category')->nullable()->after('sync_error');
            $table->timestamp('last_attempted_at')->nullable()->after('available_at');
            $table->timestamp('next_attempt_at')->nullable()->after('last_attempted_at');
            $table->string('external_provider')->nullable()->after('synced_at');
            $table->string('external_id')->nullable()->after('external_provider');
            $table->string('external_reference')->nullable()->after('external_id');

            $table->index(['sync_status', 'available_at']);
            $table->index(['external_provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('accounting_outbox', function (Blueprint $table) {
            $table->dropIndex(['sync_status', 'available_at']);
            $table->dropIndex(['external_provider', 'external_id']);
            $table->dropColumn([
                'sync_error_category',
                'last_attempted_at',
                'next_attempt_at',
                'external_provider',
                'external_id',
                'external_reference',
            ]);
        });
    }
};
