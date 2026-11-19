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
        // 1. Faculties Master Table
        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('faculty_code', 50)->unique();
            $table->string('name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('designation', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('specialization', 191)->nullable();
            $table->string('qualification', 191)->nullable();
            $table->text('bio')->nullable();
            $table->string('avatar_path', 500)->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->json('subjects')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Course Plans / Syllabi Plans Table (Annual, Monthly, Weekly, Custom)
        Schema::create('course_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_code', 50)->unique();
            $table->string('title', 255);
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('plan_type', 30)->default('annual'); // annual, monthly, weekly, custom
            $table->string('academic_year', 30)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('total_lectures_planned')->default(0);
            $table->text('topics_covered')->nullable();
            $table->longText('description')->nullable();
            $table->string('document_path', 500)->nullable();
            $table->string('version', 20)->default('1.0');
            $table->string('status', 30)->default('published')->index(); // draft, published, under_revision, archived
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['batch_id', 'course_id', 'status']);
        });

        // 3. Class Schedules / Routine Table
        Schema::create('class_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('schedule_code', 50)->unique();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('course_plan_id')->nullable()->constrained('course_plans')->nullOnDelete();
            $table->string('class_type', 30)->default('regular'); // regular, optional, extra, revision, guest_lecture
            $table->date('class_date');
            $table->string('day_of_week', 20); // Monday, Tuesday, etc.
            $table->time('start_time');
            $table->time('end_time');
            $table->string('lecture_number', 50)->nullable();
            $table->string('subject', 191)->nullable();
            $table->text('topics')->nullable();
            $table->text('description')->nullable();
            $table->string('delivery_mode', 20)->default('offline'); // offline, online, hybrid
            $table->string('classroom', 100)->nullable();
            $table->string('platform', 50)->nullable(); // Zoom, Google Meet, Microsoft Teams, Cisco Webex Meetings, Others
            $table->text('meeting_link')->nullable();
            $table->string('meeting_id', 100)->nullable();
            $table->string('meeting_password', 100)->nullable();
            $table->string('platform_other', 100)->nullable();
            $table->text('recording_url')->nullable();
            $table->string('recording_file_path', 500)->nullable();
            $table->string('recording_password', 100)->nullable();
            $table->text('recording_notes')->nullable();
            $table->string('recording_status', 30)->default('not_available'); // not_available, available, processing
            $table->string('recurrence_group_id', 64)->nullable()->index();
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_rule', 255)->nullable();
            $table->string('status', 30)->default('scheduled')->index(); // scheduled, in_progress, completed, rescheduled, cancelled
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('class_schedules')->nullOnDelete();
            $table->text('rescheduled_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['batch_id', 'course_id', 'class_date']);
            $table->index(['class_date', 'status']);
            $table->index(['delivery_mode']);
            $table->index(['class_type']);
        });

        // 4. Class Schedule Faculty Pivot Table
        Schema::create('class_schedule_faculty', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_schedule_id')->constrained('class_schedules')->cascadeOnDelete();
            $table->foreignId('faculty_id')->constrained('faculties')->cascadeOnDelete();
            $table->string('role', 50)->default('lead_faculty'); // lead_faculty, co_faculty, guest_speaker, assistant
            $table->timestamps();

            $table->unique(['class_schedule_id', 'faculty_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_schedule_faculty');
        Schema::dropIfExists('class_schedules');
        Schema::dropIfExists('course_plans');
        Schema::dropIfExists('faculties');
    }
};
