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
        // 1. Student Profiles (Basic Details)
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('father_name', 150)->nullable();
            $table->string('mother_name', 150)->nullable();
            $table->date('dob')->nullable();
            $table->string('gender', 20)->nullable(); // male, female, others
            $table->string('category', 30)->nullable(); // UR, SC, ST, OBCA, OBCB, EWS
            $table->string('verified_category', 30)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('category_remark')->nullable();
            $table->string('subcategory', 150)->nullable();
            $table->string('subcaste', 150)->nullable();
            $table->string('cert_no', 100)->nullable();
            $table->date('issued_date')->nullable();
            $table->string('issued_by', 150)->nullable();
            $table->boolean('is_pwbd')->default(false);
            $table->string('pwbd_type', 150)->nullable();
            $table->unsignedTinyInteger('pwd_percentage')->default(0);
            $table->string('occupation', 150)->nullable();
            $table->string('father_occupation', 150)->nullable();
            $table->string('mother_occupation', 150)->nullable();
            $table->decimal('family_income', 12, 2)->nullable();
            $table->string('highest_edu', 150)->nullable();
            $table->string('school_language', 100)->nullable();
            $table->string('upsc_community', 150)->nullable();
            $table->text('activities')->nullable();
            $table->text('hobbies')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Student Addresses (Permanent & Correspondence)
        Schema::create('student_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('address_type', 30)->default('Permanent'); // Permanent, Correspondence
            $table->string('state', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('subdistrict', 100)->nullable();
            $table->text('address_line1')->nullable();
            $table->text('address_line2')->nullable();
            $table->string('post_office', 100)->nullable();
            $table->string('police_station', 100)->nullable();
            $table->string('pin_code', 15)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'address_type']);
        });

        // 3. Student Education Qualifications
        Schema::create('student_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('level', 100); // 10th / Secondary, 12th / Higher Secondary, Graduation, Post-Graduation, etc.
            $table->string('board_university', 200)->nullable();
            $table->string('institute', 200)->nullable();
            $table->string('stream_subject', 200)->nullable();
            $table->year('passing_year')->nullable();
            $table->unsignedInteger('total_marks')->nullable();
            $table->unsignedInteger('marks_obtained')->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->decimal('cgpa', 4, 2)->nullable();
            $table->string('division', 50)->nullable();
            $table->string('completion_status', 50)->nullable(); // Completed, Pursuing
            $table->boolean('is_highest')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });

        // 4. Student Employment History
        Schema::create('student_employments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('employer_name', 200)->nullable();
            $table->string('designation', 150)->nullable();
            $table->string('nature_of_employment', 100)->nullable(); // Full-time, Part-time, Contract, Govt
            $table->string('location', 150)->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });

        // 5. Student Other UPSC Details
        Schema::create('student_other_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('attempts_already_given')->default(0);
            $table->unsignedInteger('attempts_left')->nullable();
            $table->string('optional_subject', 150)->nullable();
            $table->year('year_of_appearing')->nullable();
            $table->text('activities')->nullable();
            $table->text('hobbies')->nullable();
            $table->json('dynamic_fields')->nullable();
            $table->timestamps();
        });

        // 6. Student Uploaded Documents
        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 100); // photo, signature, id_proof, 10th_certificate, degree, caste_certificate, pwd_certificate
            $table->string('file_path', 500);
            $table->string('file_name', 255)->nullable();
            $table->string('file_hash', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_documents');
        Schema::dropIfExists('student_other_details');
        Schema::dropIfExists('student_employments');
        Schema::dropIfExists('student_educations');
        Schema::dropIfExists('student_addresses');
        Schema::dropIfExists('student_profiles');
    }
};
