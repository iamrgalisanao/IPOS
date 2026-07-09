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
        Schema::create('discount_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('statutory_category', ['senior', 'pwd', 'solo_parent', 'other', 'none'])->default('none');
            $table->decimal('default_rate', 5, 4)->default(0);
            $table->enum('vat_treatment', ['exempt', 'partial', 'none'])->default('none');
            $table->boolean('requires_identity')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('applies_to_fnb')->default(true);
            $table->boolean('applies_to_retail')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_discount_eligibility', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('product_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('discount_type_id')->constrained('discount_types')->onDelete('cascade');
            $table->boolean('is_eligible')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'discount_type_id']);
        });

        Schema::create('sale_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('discount_type_id')->constrained('discount_types');
            $table->enum('application_mode', ['standard', 'line_item', 'portion', 'memc'])->default('standard');
            $table->decimal('base_amount', 16, 4)->default(0);
            $table->decimal('discount_amount', 16, 4)->default(0);
            $table->decimal('vat_exempt_amount', 16, 4)->default(0);
            $table->integer('eligible_person_count')->default(1);
            $table->integer('total_pax_count')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->json('calculation_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_discount_beneficiaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_discount_id')->constrained('sale_discounts')->onDelete('cascade');
            $table->string('beneficiary_name');
            $table->string('id_number')->nullable();
            $table->string('tin')->nullable();
            $table->string('spic_number')->nullable();
            $table->string('child_name')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_discount_beneficiaries');
        Schema::dropIfExists('sale_discounts');
        Schema::dropIfExists('product_discount_eligibility');
        Schema::dropIfExists('discount_types');
    }
};
