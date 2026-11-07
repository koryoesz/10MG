<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Each lender chooses its own identity checks (1:1 with institutions, like branding).
        Schema::create('institution_kyc_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('institution_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('kyc_required')->default(true);
            $table->boolean('bvn_check_enabled')->default(true);
            $table->boolean('nin_check_enabled')->default(true);
            $table->boolean('phone_match_required')->default(false);
            $table->timestamps();
        });

        // Which checks a business has passed, so a lender enabling a new check later
        // can tell exactly what is still outstanding.
        Schema::table('customers', function (Blueprint $table) {
            $table->json('kyc_checks')->nullable()->after('kyc_status');
        });

        // A lender may run only one of the two checks, so either number can be absent.
        Schema::table('customer_directors', function (Blueprint $table) {
            $table->text('bvn')->nullable()->change();
            $table->char('bvn_hash', 64)->nullable()->change();
            $table->char('bvn_last4', 4)->nullable()->change();
            $table->text('nin')->nullable()->change();
            $table->char('nin_hash', 64)->nullable()->change();
            $table->char('nin_last4', 4)->nullable()->change();
        });

        DB::table('customers')->where('kyc_status', 'verified')->update(['kyc_checks' => json_encode(['bvn', 'nin'])]);

        $now = now();
        DB::table('institutions')->orderBy('id')->pluck('id')->each(fn (string $institutionId) => DB::table('institution_kyc_settings')->insert([
            'id' => strtolower((string) Str::ulid()),
            'institution_id' => $institutionId,
            'kyc_required' => (bool) config('lending.kyc.required', true),
            'bvn_check_enabled' => true,
            'nin_check_enabled' => true,
            'phone_match_required' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('institution_kyc_settings');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('kyc_checks');
        });
    }
};
