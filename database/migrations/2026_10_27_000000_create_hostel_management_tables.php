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
        // 1. Hostel Buildings
        Schema::create('hostel_buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g. Ganga Hostel, Mayurakhshi Hostel, Teesta Hostel
            $table->string('code', 50)->unique(); // e.g. GH, MH, TH
            $table->string('gender_type', 30)->default('coed')->index(); // boys_only, girls_only, coed
            $table->unsignedInteger('total_floors')->default(1);
            $table->string('warden_name')->nullable();
            $table->string('warden_contact')->nullable();
            $table->string('warden_email')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active')->index(); // active, inactive, maintenance
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Hostel Floors
        Schema::create('hostel_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_building_id')->constrained('hostel_buildings')->cascadeOnDelete();
            $table->integer('floor_number')->default(0); // 0 = Ground, 1 = 1st, 2 = 2nd, etc.
            $table->string('name'); // e.g. Ground Floor, 1st Floor
            $table->string('gender_type', 30)->default('coed')->index(); // boys_only, girls_only, coed
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active')->index(); // active, inactive, maintenance
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hostel_building_id', 'floor_number'], 'hb_floor_unique');
        });

        // 3. Hostel Rooms
        Schema::create('hostel_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_building_id')->constrained('hostel_buildings')->cascadeOnDelete();
            $table->foreignId('hostel_floor_id')->constrained('hostel_floors')->cascadeOnDelete();
            $table->string('room_number', 50)->index(); // e.g. G-101, M-204, T-305
            $table->string('room_type', 30)->default('sharing')->index(); // sharing, non_sharing
            $table->unsignedInteger('capacity')->default(2); // Bed count: 1 for non_sharing, >=2 for sharing
            $table->string('gender_allowed', 30)->default('male_only')->index(); // male_only, female_only, any
            $table->string('status', 30)->default('available')->index(); // available, occupied, reserved, maintenance, inactive
            $table->json('amenities')->nullable(); // ['attached_washroom', 'ac', 'study_table', 'balcony', 'wifi']
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hostel_building_id', 'room_number'], 'hb_room_unique');
        });

        // 4. Hostel Beds
        Schema::create('hostel_beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostel_room_id')->constrained('hostel_rooms')->cascadeOnDelete();
            $table->string('bed_number', 50); // e.g. Bed 1, Bed 2, Bed A, Bed B
            $table->string('status', 30)->default('available')->index(); // available, occupied, reserved, maintenance
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hostel_room_id', 'bed_number'], 'hr_bed_unique');
        });

        // 5. Hostel Allocations
        Schema::create('hostel_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('allocation_no', 50)->unique(); // e.g. HST-2026-0001
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('resident_type', 30)->default('student')->index(); // student, staff
            $table->foreignId('hostel_room_id')->constrained('hostel_rooms')->cascadeOnDelete();
            $table->foreignId('hostel_bed_id')->constrained('hostel_beds')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable(); // Optional timeline duration
            $table->string('duration_type', 30)->nullable(); // days, months, years, custom, indefinite
            $table->unsignedInteger('duration_value')->nullable();
            $table->date('actual_vacated_date')->nullable();
            $table->string('status', 30)->default('active')->index(); // active, extended, vacated, cancelled
            $table->text('remarks')->nullable(); // Remarks / Notes during accommodation

            // Physical Hard-Copy Hostel Form Tracking
            $table->boolean('hard_copy_submitted')->default(false)->index();
            $table->date('hard_copy_submitted_at')->nullable();
            $table->foreignId('hard_copy_received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('hard_copy_notes')->nullable();

            // Digital Soft-Copy Hostel Form Tracking
            $table->string('soft_copy_path', 500)->nullable();
            $table->dateTime('soft_copy_uploaded_at')->nullable();
            $table->foreignId('soft_copy_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('soft_copy_verified')->default(false)->index();
            $table->dateTime('soft_copy_verified_at')->nullable();
            $table->foreignId('soft_copy_verified_by')->nullable()->constrained('users')->nullOnDelete();

            // Previous Batch / Legacy Student Resident Tracking
            $table->boolean('is_previous_batch_student')->default(false)->index();
            $table->string('previous_batch_name')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 6. Hostel Extension Requests
        Schema::create('hostel_extension_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 50)->unique(); // e.g. HER-2026-0001
            $table->foreignId('hostel_allocation_id')->constrained('hostel_allocations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('current_end_date')->nullable();
            $table->date('requested_end_date');
            $table->string('extension_duration_type', 30)->default('months'); // days, months, years, custom
            $table->unsignedInteger('extension_duration_value')->default(1);
            $table->text('reason'); // Resident reason for requesting extension
            $table->string('status', 30)->default('pending')->index(); // pending, approved, rejected
            $table->text('admin_notes')->nullable(); // Mandatory on rejection or notes on approval
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hostel_extension_requests');
        Schema::dropIfExists('hostel_allocations');
        Schema::dropIfExists('hostel_beds');
        Schema::dropIfExists('hostel_rooms');
        Schema::dropIfExists('hostel_floors');
        Schema::dropIfExists('hostel_buildings');
    }
};
