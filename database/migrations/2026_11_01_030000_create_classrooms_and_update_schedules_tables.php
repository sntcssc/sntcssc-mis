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
        // 1. Classrooms Master Table
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->string('room_code', 50)->unique();
            $table->string('name', 191);
            $table->string('building', 100);
            $table->string('floor', 50);
            $table->unsignedInteger('capacity')->nullable();
            $table->string('room_type', 50)->default('lecture_hall'); // lecture_hall, seminar_room, smart_classroom, lab, auditorium, other
            $table->string('status', 30)->default('active')->index(); // active, inactive, maintenance
            $table->json('facilities')->nullable(); // projector, smartboard, air_conditioned, sound_system, video_conferencing, wifi
            $table->json('assigned_courses')->nullable(); // course_ids assigned to this classroom
            $table->json('assigned_sections')->nullable(); // section_ids assigned to this classroom
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['building', 'floor', 'status']);
        });

        // 2. Add photo and CV to faculties
        Schema::table('faculties', function (Blueprint $table) {
            if (! Schema::hasColumn('faculties', 'photo_path')) {
                $table->string('photo_path', 500)->nullable()->after('avatar_path');
            }
            if (! Schema::hasColumn('faculties', 'cv_path')) {
                $table->string('cv_path', 500)->nullable()->after('photo_path');
            }
        });

        // 3. Add classroom_id and reminder tracking to class_schedules
        Schema::table('class_schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('class_schedules', 'classroom_id')) {
                $table->foreignId('classroom_id')->nullable()->after('classroom')->constrained('classrooms')->nullOnDelete();
            }
            if (! Schema::hasColumn('class_schedules', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable()->after('rescheduled_reason');
            }
            if (! Schema::hasColumn('class_schedules', 'reminder_count')) {
                $table->unsignedInteger('reminder_count')->default(0)->after('reminder_sent_at');
            }
            if (! Schema::hasColumn('class_schedules', 'last_reminder_channel')) {
                $table->string('last_reminder_channel', 50)->nullable()->after('reminder_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('class_schedules', 'classroom_id')) {
                $table->dropForeign(['classroom_id']);
                $table->dropColumn('classroom_id');
            }
            if (Schema::hasColumn('class_schedules', 'reminder_sent_at')) {
                $table->dropColumn(['reminder_sent_at', 'reminder_count', 'last_reminder_channel']);
            }
        });

        Schema::table('faculties', function (Blueprint $table) {
            if (Schema::hasColumn('faculties', 'photo_path')) {
                $table->dropColumn(['photo_path', 'cv_path']);
            }
        });

        Schema::dropIfExists('classrooms');
    }
};
