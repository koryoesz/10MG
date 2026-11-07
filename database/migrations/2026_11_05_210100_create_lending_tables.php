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
        // A borrower is a healthcare business (pharmacy, clinic, hospital, ...). `user_id` is the
        // owner / primary contact who logs in on its behalf.
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('business_type', 32);
            $table->string('registration_number', 32);
            $table->string('licence_number', 64);
            $table->date('licence_expires_at');
            $table->unsignedSmallInteger('year_established');
            $table->string('phone', 32);
            $table->string('address')->nullable();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->unsignedSmallInteger('staff_count')->default(1);
            $table->unsignedBigInteger('monthly_revenue');
            $table->unsignedBigInteger('monthly_expenses');
            $table->timestamps();

            $table->unique(['institution_id', 'registration_number']);
            $table->unique(['institution_id', 'phone']);
            $table->index(['institution_id', 'business_type']);
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('institution_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('loan_product_id')->constrained()->restrictOnDelete();
            $table->string('reference', 32)->unique();
            $table->string('status', 16);
            $table->char('currency', 3);
            $table->unsignedBigInteger('principal_amount');
            $table->unsignedSmallInteger('tenor');
            $table->unsignedInteger('interest_rate_bps');
            $table->string('interest_method', 32);
            $table->string('repayment_frequency', 16);
            $table->json('fees_snapshot');
            $table->unsignedSmallInteger('grace_period_days')->default(0);
            $table->string('late_fee_type', 16)->nullable();
            $table->unsignedBigInteger('late_fee_value')->default(0);
            $table->unsignedBigInteger('total_interest');
            $table->unsignedBigInteger('upfront_fees');
            $table->unsignedBigInteger('capitalized_fees');
            $table->unsignedBigInteger('penalty_fees')->default(0);
            $table->unsignedBigInteger('net_disbursement_amount');
            $table->unsignedBigInteger('total_repayable');
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->unsignedBigInteger('outstanding_balance')->default(0);
            $table->string('purpose', 32);
            $table->string('purpose_note', 500)->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('decision_reason', 1000)->nullable();
            $table->foreignUlid('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->string('disbursement_reference')->nullable();
            $table->date('first_due_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'status', 'created_at']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('credit_assessments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('loan_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->unsignedSmallInteger('score');
            $table->char('risk_grade', 1);
            $table->string('recommendation', 16);
            $table->unsignedInteger('debt_service_coverage_bps');
            $table->bigInteger('net_monthly_cash_flow');
            $table->unsignedBigInteger('max_recommended_amount');
            $table->json('factors');
            $table->timestamp('assessed_at');
            $table->timestamps();
        });

        Schema::create('loan_installments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->unsignedBigInteger('principal_due');
            $table->unsignedBigInteger('interest_due');
            $table->unsignedBigInteger('fee_due');
            $table->unsignedBigInteger('penalty_due')->default(0);
            $table->unsignedBigInteger('total_due');
            $table->unsignedBigInteger('principal_paid')->default(0);
            $table->unsignedBigInteger('interest_paid')->default(0);
            $table->unsignedBigInteger('fee_paid')->default(0);
            $table->unsignedBigInteger('penalty_paid')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->string('status', 16)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'sequence']);
            $table->index(['status', 'due_date']);
        });

        Schema::create('loan_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('institution_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('loan_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->unsignedBigInteger('amount');
            $table->string('reference', 40)->unique();
            $table->string('external_reference')->nullable();
            $table->string('channel', 32)->nullable();
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->json('allocation')->nullable();
            $table->foreignUlid('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['institution_id', 'external_reference']);
            $table->index(['loan_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_transactions');
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('credit_assessments');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('customers');
    }
};
