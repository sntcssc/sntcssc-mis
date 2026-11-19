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
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('key_prefix', 24)->index();
            $table->string('key_hash', 64)->unique();
            $table->string('type', 50)->default('external_app'); // mobile_app, desktop_app, external_app, service, testing
            $table->string('environment', 20)->default('production'); // production, sandbox
            $table->string('status', 20)->default('active'); // active, suspended, revoked
            $table->json('scopes')->nullable(); // array of scopes
            $table->json('ip_allowlist')->nullable(); // array of IP addresses / CIDR
            $table->json('allowed_domains')->nullable(); // array of Origins / Domains
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->string('last_used_user_agent', 500)->nullable();
            $table->unsignedBigInteger('total_requests')->default(0);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'environment']);
            $table->index(['type', 'status']);
        });

        Schema::create('api_key_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('api_key_name')->nullable();
            $table->string('ip_address', 45)->index();
            $table->string('user_agent', 500)->nullable();
            $table->string('method', 10);
            $table->string('endpoint', 500);
            $table->unsignedSmallInteger('status_code')->index();
            $table->float('duration_ms')->nullable();
            $table->json('request_headers')->nullable();
            $table->json('request_payload')->nullable();
            $table->text('response_summary')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['api_key_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_key_logs');
        Schema::dropIfExists('api_keys');
    }
};
