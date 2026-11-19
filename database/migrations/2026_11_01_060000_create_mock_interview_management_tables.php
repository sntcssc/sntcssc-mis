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
        // 1. Mock Interview Sessions
        Schema::create('mock_interview_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_code', 32)->unique();
            $table->string('title');
            $table->unsignedInteger('session_number')->default(1);
            $table->string('academic_year', 16)->default('2026');
            $table->date('session_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->time('reporting_time')->nullable();
            $table->text('venue_address');
            $table->string('room_number', 64)->nullable();
            $table->unsignedInteger('default_interview_duration')->default(30); // in minutes
            $table->unsignedInteger('default_buffer_duration')->default(5); // in minutes
            $table->text('instructions')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 32)->default('scheduled'); // draft, scheduled, in_progress, completed, cancelled
            $table->text('chairman_invitation_template')->nullable();
            $table->text('member_invitation_template')->nullable();
            $table->text('student_invitation_template')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'fk_mi_sess_created')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'fk_mi_sess_updated')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users', 'id', 'fk_mi_sess_deleted')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['session_date', 'status'], 'mi_sessions_date_stat_idx');
            $table->index('academic_year', 'mi_sessions_year_idx');
            $table->index('session_number', 'mi_sessions_num_idx');
        });

        // 2. Interview Board Members (Pool / Directory)
        Schema::create('interview_board_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'fk_mi_board_user')->nullOnDelete();
            $table->string('member_code', 32)->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('designation')->nullable();
            $table->string('organization')->nullable();
            $table->string('specialization')->nullable();
            $table->text('bio')->nullable();
            $table->string('avatar')->nullable();
            $table->string('status', 32)->default('active'); // active, inactive
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'fk_mi_board_created')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'fk_mi_board_updated')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users', 'id', 'fk_mi_board_deleted')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status', 'mi_members_status_idx');
            $table->index('name', 'mi_members_name_idx');
        });

        // 3. Mock Interview Session Members (Board Composition: 1 Chair + Members)
        Schema::create('mock_interview_session_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mock_interview_session_id')->constrained('mock_interview_sessions', 'id', 'fk_mi_smem_sess')->cascadeOnDelete();
            $table->foreignId('interview_board_member_id')->constrained('interview_board_members', 'id', 'fk_mi_smem_member')->cascadeOnDelete();
            $table->string('role', 32)->default('member'); // chairperson, member
            $table->unsignedInteger('order_index')->default(1);
            $table->string('attendance_status', 32)->default('confirmed'); // invited, confirmed, attended, absent
            $table->timestamp('invitation_sent_at')->nullable();
            $table->json('invitation_channels')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['mock_interview_session_id', 'interview_board_member_id'], 'mi_sess_mem_uniq');
            $table->index(['mock_interview_session_id', 'role'], 'mi_sess_mem_role_idx');
        });

        // 4. Mock Interview Candidates (Students assigned into session with sequence, timing, mode, recording)
        Schema::create('mock_interview_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mock_interview_session_id')->constrained('mock_interview_sessions', 'id', 'fk_mi_cand_sess')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'fk_mi_cand_user')->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches', 'id', 'fk_mi_cand_batch')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses', 'id', 'fk_mi_cand_course')->nullOnDelete();
            $table->unsignedInteger('sequence_number')->default(1);
            $table->time('scheduled_start_time');
            $table->time('scheduled_end_time');
            $table->unsignedInteger('duration_minutes')->default(30);
            $table->string('attending_mode', 32)->default('offline'); // offline, online
            $table->text('venue_address')->nullable();
            $table->string('room_number', 64)->nullable();
            $table->string('platform', 64)->nullable(); // Zoom, Google Meet, Microsoft Teams, Cisco Webex Meetings, Others
            $table->string('platform_other')->nullable();
            $table->text('meeting_link')->nullable();
            $table->string('meeting_id')->nullable();
            $table->string('meeting_password')->nullable();
            $table->text('candidate_instructions')->nullable();
            $table->string('status', 32)->default('scheduled'); // scheduled, reported, interviewing, completed, absent, rescheduled
            $table->decimal('interview_score', 5, 2)->nullable();
            $table->text('board_feedback')->nullable();
            $table->string('recording_type', 32)->default('url'); // url, upload
            $table->text('recording_url')->nullable();
            $table->string('recording_file_path')->nullable();
            $table->string('recording_password')->nullable();
            $table->string('recording_status', 32)->default('not_available'); // not_available, processing, available
            $table->timestamp('recording_uploaded_at')->nullable();
            $table->text('recording_notes')->nullable();
            $table->timestamp('invitation_sent_at')->nullable();
            $table->json('invitation_channels')->nullable();
            $table->timestamp('recording_sent_at')->nullable();
            $table->json('recording_sent_channels')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'fk_mi_cand_created')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'fk_mi_cand_updated')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users', 'id', 'fk_mi_cand_deleted')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['mock_interview_session_id', 'sequence_number'], 'mi_cand_seq_idx');
            $table->index('user_id', 'mi_cand_user_idx');
            $table->index('attending_mode', 'mi_cand_mode_idx');
            $table->index('status', 'mi_cand_stat_idx');
        });

        // 5. Invitation & Notification Delivery Logs
        Schema::create('mock_interview_invitation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mock_interview_session_id')->constrained('mock_interview_sessions', 'id', 'fk_mi_log_sess')->cascadeOnDelete();
            $table->foreignId('mock_interview_candidate_id')->nullable()->constrained('mock_interview_candidates', 'id', 'fk_mi_log_cand')->cascadeOnDelete();
            $table->foreignId('mock_interview_session_member_id')->nullable()->constrained('mock_interview_session_members', 'id', 'fk_mi_log_smem')->cascadeOnDelete();
            $table->string('recipient_type', 32); // chairman, board_member, student
            $table->string('recipient_name');
            $table->string('recipient_contact')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'fk_mi_log_user')->nullOnDelete();
            $table->string('channel', 32); // email, sms, whatsapp, telegram, in_app
            $table->string('purpose', 32)->default('invitation'); // invitation, recording, reminder
            $table->string('subject')->nullable();
            $table->text('message_content');
            $table->string('status', 32)->default('sent'); // sent, failed, pending
            $table->text('error_message')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users', 'id', 'fk_mi_log_sent_by')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['mock_interview_session_id', 'recipient_type'], 'mi_logs_recip_idx');
            $table->index(['channel', 'status'], 'mi_logs_chan_stat_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_interview_invitation_logs');
        Schema::dropIfExists('mock_interview_candidates');
        Schema::dropIfExists('mock_interview_session_members');
        Schema::dropIfExists('interview_board_members');
        Schema::dropIfExists('mock_interview_sessions');
    }
};
