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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id', 26)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['institution_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('action');
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 255);
            $table->string('request_method', 10);
            $table->string('request_path', 2048);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');
    }
};
