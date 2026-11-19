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
        // 1. Add Biometric and Staff Fields to Users Table
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'biometric_pin')) {
                $table->string('biometric_pin', 50)->nullable()->unique()->after('status');
            }
            if (! Schema::hasColumn('users', 'rfid_card_no')) {
                $table->string('rfid_card_no', 50)->nullable()->unique()->after('biometric_pin');
            }
            if (! Schema::hasColumn('users', 'employee_id')) {
                $table->string('employee_id', 50)->nullable()->unique()->after('rfid_card_no');
            }
            if (! Schema::hasColumn('users', 'staff_department')) {
                $table->string('staff_department', 100)->nullable()->after('employee_id');
            }
        });

        // 2. Leave Categories Table
        Schema::create('leave_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 30)->unique();
            $table->string('applicable_to', 20)->default('both')->index(); // 'staff', 'student', 'both'
            $table->string('color', 30)->default('blue'); // 'emerald', 'amber', 'rose', 'indigo', etc.
            $table->decimal('annual_quota', 5, 1)->default(12.0);
            $table->decimal('monthly_limit', 4, 1)->nullable();
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_paid')->default(true); // For office staff
            $table->boolean('is_active')->default(true)->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 3. Leave Allocations Table (Annual/Session Quotas per User)
        Schema::create('leave_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('leave_category_id')->constrained('leave_categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('year')->default(2026)->index();
            $table->decimal('total_days', 5, 1)->default(0);
            $table->decimal('used_days', 5, 1)->default(0);
            $table->decimal('pending_days', 5, 1)->default(0);
            $table->decimal('carried_forward_days', 5, 1)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'leave_category_id', 'year'], 'leave_alloc_user_cat_year_unique');
        });

        // 4. Leave Applications Table
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_no', 50)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('leave_category_id')->constrained('leave_categories')->cascadeOnDelete();
            $table->string('user_type', 20)->default('student')->index(); // 'staff', 'student'
            $table->date('from_date')->index();
            $table->date('to_date')->index();
            $table->decimal('total_days', 5, 1)->default(1.0);
            $table->boolean('is_half_day')->default(false);
            $table->string('half_day_type', 20)->nullable(); // 'first_half', 'second_half'
            $table->text('reason');
            $table->string('attachment_path', 255)->nullable();
            $table->string('emergency_contact', 100)->nullable();
            $table->string('status', 30)->default('pending')->index(); // 'pending', 'approved', 'rejected', 'cancelled'
            $table->foreignId('action_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('action_at')->nullable();
            $table->text('action_remarks')->nullable();
            $table->boolean('is_synced_to_attendance')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });

        // 5. Working Calendars & Shifts Table (Different days/timings for Staff vs Students)
        Schema::create('working_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('target_type', 20)->default('staff')->index(); // 'staff', 'student'
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->string('department', 100)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('monday_active')->default(true);
            $table->boolean('tuesday_active')->default(true);
            $table->boolean('wednesday_active')->default(true);
            $table->boolean('thursday_active')->default(true);
            $table->boolean('friday_active')->default(true);
            $table->boolean('saturday_active')->default(false);
            $table->boolean('sunday_active')->default(false);
            $table->string('saturday_rules', 30)->default('all_off'); // 'all_off', '2nd_4th_off', 'all_working'
            $table->time('expected_start_time')->default('09:30:00');
            $table->time('expected_end_time')->default('17:30:00');
            $table->unsignedSmallInteger('late_grace_minutes')->default(15);
            $table->decimal('half_day_hours', 4, 1)->default(4.0);
            $table->boolean('is_default')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 6. Dynamic Holidays & Calendar Offs Table
        Schema::create('attendance_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->date('date')->index();
            $table->date('to_date')->nullable();
            $table->string('type', 30)->index(); // 'govt_holiday', 'institution_holiday', 'weekly_off', 'weekly_test', 'exam_day', 'restricted_holiday', 'emergency_off', 'vacation'
            $table->string('target_type', 20)->default('all')->index(); // 'all', 'staff', 'student'
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_restricted')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 7. Biometric Devices Table
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_name', 100);
            $table->string('device_serial_number', 100)->unique();
            $table->string('device_ip', 45)->nullable();
            $table->unsignedInteger('port')->default(4370);
            $table->string('location', 150)->nullable();
            $table->string('device_type', 50)->default('hybrid'); // 'fingerprint', 'facial_recognition', 'rfid_card', 'hybrid'
            $table->string('api_token', 100)->nullable()->unique();
            $table->string('status', 20)->default('online')->index(); // 'online', 'offline', 'maintenance'
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 8. Biometric Raw Logs Table
        Schema::create('biometric_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->string('biometric_pin', 50)->nullable()->index();
            $table->string('card_number', 50)->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('punch_time')->index();
            $table->string('punch_type', 20)->default('auto'); // 'check_in', 'check_out', 'auto'
            $table->json('raw_payload')->nullable();
            $table->boolean('is_processed')->default(false)->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // 9. Master Attendances Table (Unified for Staff & Students, Biometric & Manual)
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->string('attendance_no', 50)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date')->index();
            $table->string('user_type', 20)->default('student')->index(); // 'staff', 'student'
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('status', 20)->index(); // 'present', 'absent', 'late', 'half_day', 'on_leave', 'holiday', 'weekly_off'
            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();
            $table->decimal('working_hours', 5, 2)->default(0.00);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);
            $table->string('entry_source', 30)->default('manual_single'); // 'manual_single', 'manual_bulk', 'biometric_device', 'rfid_card', 'mobile_qr', 'system_auto_leave', 'import'
            $table->foreignId('leave_application_id')->nullable()->constrained('leave_applications')->nullOnDelete();
            $table->foreignId('biometric_device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remarks', 255)->nullable();
            $table->boolean('notification_sent')->default(false)->index();
            $table->timestamp('notification_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['date', 'user_id', 'deleted_at'], 'attendances_date_user_deleted_unique');
            $table->index(['date', 'user_type', 'status']);
            $table->index(['course_id', 'batch_id', 'date']);
        });

        // 10. Attendance Notifications & Reminders Audit Log
        Schema::create('attendance_notifications_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 30); // 'database', 'email', 'sms', 'whatsapp', 'telegram'
            $table->string('notification_type', 40); // 'marked_present', 'marked_absent', 'marked_late', 'daily_digest', 'reminder'
            $table->string('recipient', 100)->nullable();
            $table->string('status', 20)->default('sent'); // 'sent', 'failed', 'queued'
            $table->string('message_preview', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'notification_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_notifications_log');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('biometric_logs');
        Schema::dropIfExists('biometric_devices');
        Schema::dropIfExists('attendance_holidays');
        Schema::dropIfExists('working_calendars');
        Schema::dropIfExists('leave_applications');
        Schema::dropIfExists('leave_allocations');
        Schema::dropIfExists('leave_categories');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['biometric_pin', 'rfid_card_no', 'employee_id', 'staff_department']);
        });
    }
};
