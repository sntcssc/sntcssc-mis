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
        // 1. Student Tests / Examinations (Mock Tests, Class Tests, Sectional Tests, etc.)
        Schema::create('student_tests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 50)->unique(); // e.g. TEST-2026-001
            $table->string('title', 200); // e.g. UPSC Prelims Mock Test #1
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete(); // null = all sections
            $table->string('test_stage', 30)->default('prelims'); // prelims, mains, interview_prep
            $table->string('test_type', 30)->default('mcq'); // mcq, descriptive, essay, answer_writing
            $table->string('test_for', 50)->default('mock_test'); // class_test, mock_test, live_test, full_length_test, sectional_test
            $table->unsignedInteger('test_number')->default(1);
            $table->decimal('total_marks', 8, 2)->default(100.00);
            $table->decimal('pass_marks', 8, 2)->nullable();
            $table->unsignedInteger('total_questions')->default(0);
            $table->string('mode', 20)->default('offline'); // offline, online, hybrid
            $table->date('test_date');
            $table->string('test_day', 20)->nullable(); // e.g. Sunday
            $table->string('start_time', 20)->nullable(); // e.g. 10:00 AM
            $table->string('end_time', 20)->nullable(); // e.g. 12:00 PM
            $table->unsignedInteger('duration_minutes')->default(120);
            $table->string('venue_room', 100)->nullable();
            $table->string('question_paper_path', 255)->nullable();
            $table->string('question_paper_name', 255)->nullable();
            $table->string('model_answer_path', 255)->nullable();
            $table->string('model_answer_name', 255)->nullable();
            $table->dateTime('solutions_published_at')->nullable();
            $table->string('status', 30)->default('scheduled'); // scheduled, ongoing, completed, cancelled
            $table->text('instructions')->nullable();
            $table->decimal('negative_marking_ratio', 4, 2)->default(0.33); // e.g. 0.33 (1/3 deduction)
            $table->decimal('marks_per_question', 5, 2)->default(2.00);
            $table->boolean('notify_students')->default(false);
            $table->json('notification_channels')->nullable(); // ['whatsapp', 'sms', 'email', 'in_app']
            $table->dateTime('notification_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['batch_id', 'course_id', 'section_id'], 'st_batch_course_sec_idx');
            $table->index(['test_date', 'status'], 'st_date_status_idx');
            $table->index(['test_stage', 'test_type', 'test_for'], 'st_stage_type_for_idx');
        });

        // 2. Student Test Subjects (Composite paper structure e.g. GS: 50 Qs, CSAT: 50 Qs)
        Schema::create('student_test_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_test_id')->constrained('student_tests')->cascadeOnDelete();
            $table->string('subject_name', 150); // e.g. General Studies Paper 1, CSAT
            $table->string('subject_code', 50)->nullable();
            $table->unsignedInteger('questions_count')->default(0);
            $table->decimal('max_marks', 8, 2)->default(0.00);
            $table->decimal('marks_per_question', 5, 2)->default(2.00);
            $table->decimal('negative_marking', 4, 2)->default(0.66);
            $table->unsignedInteger('display_order')->default(1);
            $table->timestamps();

            $table->index(['student_test_id', 'display_order'], 'sts_test_order_idx');
        });

        // 3. Student Test Results / Marksheets
        Schema::create('student_test_results', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_test_id')->constrained('student_tests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Student User
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('roll_number', 50)->nullable()->index();
            $table->string('attendance_status', 30)->default('present'); // present, absent, exempted, disqualified

            // MCQ Details
            $table->unsignedInteger('total_questions')->default(0);
            $table->unsignedInteger('attempted')->default(0);
            $table->unsignedInteger('right_answers')->default(0);
            $table->unsignedInteger('wrong_answers')->default(0);
            $table->unsignedInteger('blank_answers')->default(0);
            $table->decimal('positive_marks', 8, 2)->default(0.00);
            $table->decimal('negative_marks', 8, 2)->default(0.00);

            // Scores & Standings
            $table->decimal('marks_obtained', 8, 2)->default(0.00)->index();
            $table->decimal('total_marks', 8, 2)->default(0.00);
            $table->decimal('percentage', 5, 2)->default(0.00);
            $table->decimal('percentile', 5, 2)->nullable();
            $table->unsignedInteger('rank')->nullable()->index();
            $table->boolean('is_top_ten')->default(false)->index();
            $table->boolean('is_passed')->default(true);

            // Subject-wise detailed score breakdown (JSON)
            $table->json('subject_scores')->nullable();

            $table->text('remarks')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('evaluated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['student_test_id', 'user_id']);
            $table->index(['student_test_id', 'attendance_status', 'rank'], 'str_test_att_rank_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_test_results');
        Schema::dropIfExists('student_test_subjects');
        Schema::dropIfExists('student_tests');
    }
};
