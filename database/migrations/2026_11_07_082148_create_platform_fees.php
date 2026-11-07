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
        // 10MG's own fee per lender, set by a platform operator. NULL = platform default (config).
        Schema::table('institutions', function (Blueprint $table) {
            $table->unsignedInteger('platform_fee_bps')->nullable()->after('currency');
            $table->string('platform_fee_bearer', 16)->nullable()->after('platform_fee_bps');
        });

        // The fee terms in force when the borrower applied, so later changes never reprice a loan.
        Schema::table('loans', function (Blueprint $table) {
            $table->unsignedInteger('platform_fee_bps')->default(0)->after('capitalized_fees');
            $table->string('platform_fee_bearer', 16)->nullable()->after('platform_fee_bps');
            $table->unsignedBigInteger('platform_fee_amount')->default(0)->after('platform_fee_bearer');
        });

        // Append-only revenue ledger: one row per disbursed loan, owed by the lender to 10MG.
        Schema::create('platform_fees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('institution_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('loan_id')->unique()->constrained()->restrictOnDelete();
            $table->string('bearer', 16);
            $table->unsignedInteger('rate_bps');
            $table->unsignedBigInteger('base_amount');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->timestamp('accrued_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['institution_id', 'accrued_at']);
            $table->index('accrued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_fees');

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['platform_fee_bps', 'platform_fee_bearer', 'platform_fee_amount']);
        });

        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn(['platform_fee_bps', 'platform_fee_bearer']);
        });
    }
};
