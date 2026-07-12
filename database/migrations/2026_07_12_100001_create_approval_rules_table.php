<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->string('scope_key');
            $table->string('action')->default('statutory_discount');
            $table->boolean('always_require_approval')->default(false);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'scope_key', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_rules');
    }
};
