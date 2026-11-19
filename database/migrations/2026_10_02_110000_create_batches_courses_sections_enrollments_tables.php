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
        // 1. Batches table
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->string('academic_year', 20)->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 30)->default('upcoming')->index(); // upcoming, active, completed, archived
            $table->text('description')->nullable();
            $table->unsignedInteger('max_students')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Courses table
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->string('category', 50)->default('Classroom')->index(); // Classroom, Crash Course, Guidance, Interview, Test Series
            $table->text('description')->nullable();
            $table->string('duration', 100)->nullable();
            $table->decimal('fee', 10, 2)->default(0.00);
            $table->boolean('has_sections')->default(false);
            $table->string('identifier_code', 10)->nullable(); // Used for student ID generation rule (e.g. '1', '3', etc.)
            $table->boolean('admit_once_only')->default(false); // Restriction: student can enroll in this course only once
            $table->unsignedInteger('max_attempts')->nullable(); // Max lifetime attempts allowed
            $table->string('status', 30)->default('active')->index(); // active, inactive, archived
            $table->json('rules')->nullable(); // Configurable dynamic eligibility and admission rules
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 3. Batch-Course offering pivot table
        Schema::create('batch_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedInteger('capacity')->nullable();
            $table->decimal('fee', 10, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 30)->default('active');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['batch_id', 'course_id']);
        });

        // 4. Sections table (optional under batch and course)
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('batch_course_id')->nullable()->constrained('batch_courses')->nullOnDelete();
            $table->string('name', 100); // e.g. "Section A", "Section B"
            $table->string('code', 10); // Identifier digit/code e.g. "1" for Sec A, "2" for Sec B
            $table->unsignedInteger('capacity')->default(45);
            $table->string('room_number', 50)->nullable();
            $table->string('timing', 150)->nullable();
            $table->string('status', 30)->default('active'); // active, full, inactive
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['batch_id', 'course_id']);
        });

        // 5. Enrollments table
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->string('enrollment_no', 50)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('roll_no', 50)->nullable()->index();
            $table->date('enrolled_at')->index();
            $table->date('completed_at')->nullable();
            $table->string('status', 30)->default('active')->index(); // active, completed, dropped, suspended, alumni
            $table->decimal('fee_amount', 10, 2)->default(0.00);
            $table->string('fee_status', 30)->default('pending')->index(); // pending, partial, paid, exempted
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'course_id']);
            $table->index(['batch_id', 'course_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('batch_courses');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('batches');
    }
};
