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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('kyc_status', 16)->default('pending')->after('monthly_expenses');
            $table->timestamp('kyc_verified_at')->nullable()->after('kyc_status');
        });

        // The people behind a borrowing business. BVN and NIN belong to people, not businesses,
        // so they live here. Numbers are encrypted at rest; the HMAC columns allow uniqueness
        // checks and lookups without ever storing or indexing the plain number.
        Schema::create('customer_directors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->date('date_of_birth');
            $table->string('phone', 32);
            $table->text('bvn');
            $table->char('bvn_hash', 64);
            $table->char('bvn_last4', 4);
            $table->text('nin');
            $table->char('nin_hash', 64);
            $table->char('nin_last4', 4);
            $table->string('bvn_reference', 64)->nullable();
            $table->timestamp('bvn_verified_at')->nullable();
            $table->string('nin_reference', 64)->nullable();
            $table->timestamp('nin_verified_at')->nullable();
            $table->json('verification_checks')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'role']);
            $table->unique(['institution_id', 'bvn_hash']);
            $table->unique(['institution_id', 'nin_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_directors');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['kyc_status', 'kyc_verified_at']);
        });
    }
};
