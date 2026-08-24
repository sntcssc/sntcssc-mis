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
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->index(); // email, sms
            $table->string('type', 50)->default('custom_individual')->index(); // otp, notification, notice, broadcast, custom_individual, custom_bulk
            $table->string('recipient')->index(); // email address or phone number
            $table->string('recipient_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('template_code', 50)->nullable()->index();
            $table->string('subject')->nullable();
            $table->longText('content');
            $table->json('variables')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 30)->default('sent')->index(); // sent, delivered, failed, pending, draft
            $table->text('error_message')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('resend_count')->default(0);
            $table->timestamp('last_resent_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
    }
};
