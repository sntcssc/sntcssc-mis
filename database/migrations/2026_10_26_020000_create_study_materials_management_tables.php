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
        // 1. Master Study Materials Table
        Schema::create('study_materials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('subject', 150)->nullable()->index();
            $table->string('topic', 150)->nullable()->index();
            $table->string('module_code', 50)->nullable();
            $table->string('material_type', 50)->default('lecture_notes')->index(); // lecture_notes, e_book_pdf, slide_deck, video_recording, audio_lecture, question_bank, syllabus, assignment_practice, handout_reference
            $table->text('description')->nullable();
            $table->longText('content')->nullable(); // Rich notes / lecture content
            $table->string('external_url', 500)->nullable(); // YouTube, Google Drive, Zoom, Vimeo link
            $table->string('access_level', 50)->default('enrolled_only')->index(); // enrolled_only, public_link, restricted_students
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('share_expires_at')->nullable();
            $table->string('share_password')->nullable();
            $table->boolean('is_pinned')->default(false)->index();
            $table->string('status', 30)->default('published')->index(); // published, draft, archived
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('download_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Study Material Batches Pivot Table
        Schema::create('study_material_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_material_id')->constrained('study_materials')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['study_material_id', 'batch_id'], 'sm_batch_unique');
        });

        // 3. Study Material Files Table (Supports multiple files per material)
        Schema::create('study_material_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_material_id')->constrained('study_materials')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path', 500);
            $table->unsignedBigInteger('file_size')->default(0); // in bytes
            $table->string('file_type', 50)->nullable(); // extension: pdf, docx, pptx, zip, etc.
            $table->string('mime_type', 100)->nullable();
            $table->string('disk', 50)->default('public');
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // 4. Study Material Selective Students Table
        Schema::create('study_material_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_material_id')->constrained('study_materials')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['study_material_id', 'user_id'], 'sm_student_unique');
        });

        // 5. Study Material Share & Delivery Audit Logs Table
        Schema::create('study_material_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_material_id')->constrained('study_materials')->cascadeOnDelete();
            $table->string('channel', 50)->index(); // whatsapp, email, sms, telegram, in_app, public_link
            $table->string('recipient_type', 50)->default('batch'); // batch, course, student, custom
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('recipient_contact')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('status', 30)->default('sent'); // sent, failed, pending
            $table->text('error_message')->nullable();
            $table->foreignId('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // 6. Study Material Access & Download Activity Logs Table
        Schema::create('study_material_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_material_id')->constrained('study_materials')->cascadeOnDelete();
            $table->foreignId('study_material_file_id')->nullable()->constrained('study_material_files')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30)->default('view'); // view, download, external_click
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('study_material_access_logs');
        Schema::dropIfExists('study_material_shares');
        Schema::dropIfExists('study_material_students');
        Schema::dropIfExists('study_material_files');
        Schema::dropIfExists('study_material_batches');
        Schema::dropIfExists('study_materials');
    }
};
