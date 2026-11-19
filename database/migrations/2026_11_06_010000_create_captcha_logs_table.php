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
        Schema::create('captcha_logs', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 100)->nullable()->index();
            $table->string('driver', 50)->default('custom')->index();
            $table->string('action', 50)->default('login')->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 500)->nullable();
            $table->string('status', 30)->default('passed')->index();
            $table->decimal('score', 3, 2)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->unsignedInteger('response_time_ms')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['driver', 'status']);
            $table->index(['action', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('captcha_logs');
    }
};
