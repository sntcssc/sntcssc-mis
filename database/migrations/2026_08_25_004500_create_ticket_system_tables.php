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
        // 1. Ticket Categories & SLA Rules
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->default('tag');
            $table->string('color_badge')->default('blue');
            $table->string('default_priority')->default('medium');
            $table->unsignedInteger('sla_response_hours')->default(24);
            $table->unsignedInteger('sla_resolution_hours')->default(72);
            $table->foreignId('default_assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Master Support Tickets
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 32)->unique();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 20)->default('medium'); // low, medium, high, urgent
            $table->string('status', 30)->default('open'); // open, in_progress, pending_user, on_hold, resolved, closed
            $table->string('subject');
            $table->longText('description');
            $table->string('source', 20)->default('portal'); // portal, email, admin, api
            $table->timestamp('last_reply_at')->nullable();
            $table->foreignId('last_reply_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->boolean('is_sla_response_breached')->default(false);
            $table->boolean('is_sla_resolution_breached')->default(false);
            $table->unsignedTinyInteger('satisfaction_rating')->nullable(); // 1 to 5
            $table->text('satisfaction_feedback')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority']);
            $table->index(['category_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['assigned_to_user_id', 'status']);
            $table->index('last_reply_at');
        });

        // 3. Ticket Thread Messages (Public Replies & Internal Staff Notes)
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_name')->nullable();
            $table->string('sender_email')->nullable();
            $table->string('type', 30)->default('public_reply'); // public_reply, internal_note, system_event
            $table->longText('message');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ticket_id', 'type']);
            $table->index(['ticket_id', 'created_at']);
        });

        // 4. Ticket Attachments
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('ticket_message_id')->nullable()->constrained('ticket_messages')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk')->default('local');
            $table->string('path');
            $table->timestamps();
            $table->softDeletes();

            $table->index('ticket_id');
        });

        // 5. Canned Responses & Macros
        Schema::create('ticket_canned_responses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('shortcut', 50)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->nullOnDelete();
            $table->longText('content');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('shortcut');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_canned_responses');
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_categories');
    }
};
