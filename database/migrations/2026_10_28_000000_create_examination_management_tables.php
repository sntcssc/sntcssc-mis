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
        // 1. Examination Centers (Venues)
        Schema::create('examination_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 200);
            $table->string('address', 255);
            $table->string('city', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('state', 100)->default('West Bengal');
            $table->string('pincode', 10)->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->unsignedInteger('total_capacity')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Examination Center Rooms / Halls
        Schema::create('examination_center_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_center_id')->constrained('examination_centers')->cascadeOnDelete();
            $table->string('room_number', 50);
            $table->string('floor', 50)->nullable();
            $table->string('building_name', 100)->nullable();
            $table->unsignedInteger('capacity')->default(30);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        // 3. Exams
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('exam_code', 50)->unique();
            $table->string('exam_name', 200);
            $table->string('academic_year', 20)->nullable()->index();
            $table->string('conducting_body', 150)->nullable();
            $table->date('notification_date')->nullable();
            $table->date('apply_start')->nullable();
            $table->date('apply_end')->nullable();
            $table->date('exam_date')->nullable()->index();
            $table->string('exam_time', 50)->nullable();
            $table->string('reporting_time', 50)->nullable();
            $table->unsignedInteger('duration_minutes')->default(120);
            $table->decimal('fee_general', 10, 2)->default(0.00);
            $table->decimal('fee_reserved', 10, 2)->default(0.00);
            $table->unsignedInteger('total_vacancies')->nullable();
            $table->string('status', 30)->default('draft')->index(); // draft, published, registration_closed, completed, cancelled
            $table->text('instructions')->nullable();
            $table->text('admit_card_instructions')->nullable();
            $table->date('admit_card_release_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 4. Exam Center Mappings
        Schema::create('exam_center_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('examination_center_id')->constrained('examination_centers')->cascadeOnDelete();
            $table->foreignId('examination_center_room_id')->nullable()->constrained('examination_center_rooms')->nullOnDelete();
            $table->unsignedInteger('allocated_seats')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // 5. Exam Candidates / Student Hall Allocations
        Schema::create('exam_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('candidate_name', 150);
            $table->string('candidate_email', 150)->nullable();
            $table->string('candidate_phone', 25)->nullable();
            $table->string('roll_number', 50)->index();
            $table->foreignId('examination_center_id')->nullable()->constrained('examination_centers')->nullOnDelete();
            $table->foreignId('examination_center_room_id')->nullable()->constrained('examination_center_rooms')->nullOnDelete();
            $table->string('seat_number', 50)->nullable();
            $table->string('admit_card_token', 64)->nullable()->unique();
            $table->timestamp('admit_card_downloaded_at')->nullable();
            $table->string('attendance_status', 30)->default('pending')->index(); // pending, present, absent, disqualified
            $table->timestamp('attendance_marked_at')->nullable();
            $table->decimal('marks_obtained', 8, 2)->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->boolean('is_qualified')->default(false)->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['exam_id', 'roll_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_candidates');
        Schema::dropIfExists('exam_center_mappings');
        Schema::dropIfExists('exams');
        Schema::dropIfExists('examination_center_rooms');
        Schema::dropIfExists('examination_centers');
    }
};
