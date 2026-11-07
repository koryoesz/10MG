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
        Schema::create('institution_brandings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('institution_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('logo_url', 2048)->nullable();
            $table->char('primary_color', 7)->default('#0F172A');
            $table->char('secondary_color', 7)->default('#38BDF8');
            $table->string('support_email')->nullable();
            $table->string('support_phone', 32)->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->string('custom_domain')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('loan_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->text('description')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('min_amount');
            $table->unsignedBigInteger('max_amount');
            $table->unsignedSmallInteger('min_tenor');
            $table->unsignedSmallInteger('max_tenor');
            $table->unsignedInteger('interest_rate_bps');
            $table->string('interest_method', 32);
            $table->string('repayment_frequency', 16);
            $table->unsignedSmallInteger('grace_period_days')->default(0);
            $table->string('late_fee_type', 16)->nullable();
            $table->unsignedBigInteger('late_fee_value')->default(0);
            $table->unsignedSmallInteger('min_credit_score')->nullable();
            $table->json('eligible_business_types')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['institution_id', 'code']);
            $table->index(['institution_id', 'is_active']);
        });

        Schema::create('loan_product_fees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('loan_product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('calculation', 16);
            $table->unsignedBigInteger('value');
            $table->string('charge_type', 16);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_product_fees');
        Schema::dropIfExists('loan_products');
        Schema::dropIfExists('institution_brandings');
    }
};
