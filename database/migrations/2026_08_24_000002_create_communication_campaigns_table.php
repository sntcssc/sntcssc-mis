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
        Schema::create('communication_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('channel', 20)->default('email')->index(); // email, sms, both
            $table->string('recipient_type', 30)->default('all_users')->index(); // individual, role, all_users, custom_list
            $table->string('recipient_role', 50)->nullable();
            $table->json('recipient_ids')->nullable(); // user ids or custom phone/email list
            $table->string('template_code', 50)->nullable()->index();
            $table->string('subject')->nullable();
            $table->longText('content');
            $table->string('status', 30)->default('draft')->index(); // draft, scheduled, processing, completed, failed, cancelled
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_campaigns');
    }
};
