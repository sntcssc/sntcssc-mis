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
        Schema::create('alumni', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('student_profile_id')->nullable()->constrained('student_profiles')->nullOnDelete();

            // Personal & Contact Info
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('full_name', 200)->index();
            $table->string('email', 150)->nullable()->index();
            $table->string('phone', 30)->nullable()->index();
            $table->string('alternate_phone', 30)->nullable();
            $table->string('photo_url', 500)->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('dob')->nullable();
            $table->unsignedSmallInteger('passing_year')->nullable()->index();

            // Service & Posting Details
            $table->string('service_type', 100)->index(); // e.g. UPSC CSE, WBCS, WBPS, Banking, etc.
            $table->string('service_name', 100)->index(); // e.g. IAS, IPS, IFS, WBCS (Exe), etc.
            $table->string('designation', 150)->index(); // e.g. SDM, ACP, Assistant Collector
            $table->string('cadre', 100)->nullable(); // e.g. West Bengal, AGMUT
            $table->unsignedInteger('all_india_rank')->nullable();
            $table->unsignedInteger('state_rank')->nullable();
            $table->unsignedSmallInteger('exam_year')->nullable()->index();
            $table->string('roll_number', 50)->nullable();
            $table->string('posting_city', 100)->nullable();
            $table->string('posting_district', 100)->nullable();
            $table->string('posting_state', 100)->nullable()->index();
            $table->string('posting_country', 100)->default('India');
            $table->string('department_ministry', 200)->nullable();
            $table->date('posting_date')->nullable();
            $table->text('office_address')->nullable();
            $table->text('current_address')->nullable();
            $table->text('permanent_address')->nullable();

            // Social & Web Links
            $table->string('linkedin_url', 255)->nullable();
            $table->string('twitter_url', 255)->nullable();
            $table->string('website_url', 255)->nullable();

            // Bio, Success Story & Recognition
            $table->text('short_bio')->nullable();
            $table->longText('detailed_bio')->nullable();
            $table->longText('success_story')->nullable();
            $table->text('testimonial')->nullable();
            $table->json('achievements')->nullable();

            // Status & Visibility Flags
            $table->boolean('is_featured')->default(false)->index();
            $table->boolean('is_verified')->default(true)->index();
            $table->string('status', 30)->default('active')->index(); // active, inactive, retired, deceased
            $table->string('source', 30)->default('manual'); // manual, student_conversion, import
            $table->integer('display_order')->default(0);

            // Audit columns
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('alumni_course_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alumni_id')->constrained('alumni')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();

            // Snapshot or custom past entry titles
            $table->string('course_name', 150);
            $table->string('batch_name', 150);
            $table->string('enrollment_no', 100)->nullable()->index();
            $table->string('roll_no', 100)->nullable();
            $table->string('academic_year', 50)->nullable();
            $table->unsignedSmallInteger('start_year')->nullable();
            $table->unsignedSmallInteger('completion_year')->nullable();
            $table->string('status', 30)->default('completed'); // completed, attended, ongoing
            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['alumni_id', 'course_id']);
            $table->index(['alumni_id', 'batch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alumni_course_batches');
        Schema::dropIfExists('alumni');
    }
};
