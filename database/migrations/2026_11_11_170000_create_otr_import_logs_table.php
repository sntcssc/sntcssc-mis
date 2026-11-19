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
        Schema::create('otr_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();

            // OTR Remote Identifiers
            $table->unsignedBigInteger('admission_selection_id')->nullable()->index();
            $table->unsignedBigInteger('admission_form_id')->nullable()->index();
            $table->string('admission_form_number', 100)->nullable()->index();
            $table->unsignedBigInteger('application_id')->nullable()->index();
            $table->string('application_number', 100)->nullable()->index();
            $table->unsignedBigInteger('exam_id')->nullable()->index();
            $table->string('exam_code', 100)->nullable()->index();
            $table->string('urn', 100)->nullable()->index();

            // Candidate Snapshot Info
            $table->string('candidate_name', 255);
            $table->string('candidate_email', 255)->index();
            $table->string('candidate_phone', 50)->nullable()->index();

            // Status and Outcomes
            $table->string('status', 50)->default('success')->index(); // success, failed, already_enrolled, skipped
            $table->string('action_taken', 100); // created_student_and_enrolled, enrolled_existing_student, already_enrolled, failed
            $table->unsignedInteger('downloaded_documents_count')->default(0);
            $table->json('payload_snapshot')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['candidate_email', 'course_id', 'batch_id'], 'oil_email_course_batch_idx');
            $table->index(['urn', 'course_id', 'batch_id'], 'oil_urn_course_batch_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otr_import_logs');
    }
};

