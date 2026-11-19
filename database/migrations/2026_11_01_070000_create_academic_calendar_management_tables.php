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
        Schema::create('academic_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('calendar_code', 50)->unique();
            $table->string('academic_year', 30)->index(); // e.g. "2026-2027"
            $table->string('title', 255);
            $table->string('theme_or_motto', 255)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('total_working_days')->default(0);
            $table->unsignedSmallInteger('total_instructional_days')->default(0);
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('published')->index();
            $table->boolean('is_current')->default(false)->index();
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('academic_calendar_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('academic_calendar_id');
            $table->string('version_code', 20); // e.g. "v1.0", "v1.1", "v2.0"
            $table->unsignedInteger('revision_number')->default(1);
            $table->string('version_title', 255);
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_mime', 100)->nullable();
            $table->text('change_summary')->nullable(); // Revision notes / changelog
            $table->string('approved_by', 150)->nullable();
            $table->date('approval_date')->nullable();
            $table->date('effective_from')->nullable();
            $table->boolean('is_current')->default(false)->index();
            $table->unsignedInteger('download_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('academic_calendar_id', 'ac_ver_cal_fk')->references('id')->on('academic_calendars')->cascadeOnDelete();
            $table->index(['academic_calendar_id', 'is_current'], 'ac_ver_cal_cur_idx');
            $table->index(['academic_calendar_id', 'revision_number'], 'ac_ver_cal_rev_idx');
        });

        Schema::create('academic_calendar_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('academic_calendar_id');
            $table->unsignedBigInteger('academic_calendar_version_id')->nullable();
            $table->string('activity_code', 50)->unique();
            $table->string('title', 255);
            $table->enum('category', [
                'academic',
                'examination',
                'admission',
                'holiday',
                'co_curricular',
                'administrative',
                'sports_cultural',
                'other',
            ])->default('academic')->index();
            $table->date('start_date')->index();
            $table->date('end_date')->nullable();
            $table->unsignedTinyInteger('month')->index(); // 1-12
            $table->unsignedSmallInteger('year')->index();
            $table->string('target_audience', 100)->default('all'); // all, students, faculty, staff, aspirants
            $table->string('department', 150)->nullable();
            $table->enum('venue_mode', ['offline', 'online', 'hybrid'])->default('offline');
            $table->string('venue_or_link', 255)->nullable();
            $table->boolean('is_tentative')->default(false);
            $table->boolean('is_notified')->default(false);
            $table->enum('status', ['planned', 'ongoing', 'completed', 'postponed', 'cancelled'])->default('planned')->index();
            $table->text('description')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('academic_calendar_id', 'ac_act_cal_fk')->references('id')->on('academic_calendars')->cascadeOnDelete();
            $table->foreign('academic_calendar_version_id', 'ac_act_ver_fk')->references('id')->on('academic_calendar_versions')->nullOnDelete();
            $table->index(['academic_calendar_id', 'month', 'year'], 'ac_act_cal_mo_yr_idx');
            $table->index(['start_date', 'category'], 'ac_act_date_cat_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_calendar_activities');
        Schema::dropIfExists('academic_calendar_versions');
        Schema::dropIfExists('academic_calendars');
    }
};
