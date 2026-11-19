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
        // 1. Course Withdrawal / Discontinuation Requests table
        Schema::create('course_withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 50)->unique();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->string('type', 30)->default('withdrawal'); // withdrawal, discontinuation
            $table->string('reason', 100); // medical, employment, exam_cleared, personal, relocation, financial, academic_difficulty, other
            $table->text('reason_details')->nullable();
            $table->string('supporting_document', 255)->nullable();
            $table->string('status', 30)->default('pending')->index(); // pending, approved, rejected, withdrawn
            $table->text('admin_notes')->nullable();
            $table->date('effective_date')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['enrollment_id', 'status']);
            $table->index(['course_id', 'status']);
        });

        // 2. Student Disciplinary Actions / Suspension / Bans table
        Schema::create('student_disciplinary_actions', function (Blueprint $table) {
            $table->id();
            $table->string('action_no', 50)->unique(); // e.g. DISC-2026-00001
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('action_type', 30)->default('suspension'); // suspension, ban, course_disbarment
            $table->string('order_no', 100);
            $table->date('order_date');
            $table->date('start_date');
            $table->date('end_date')->nullable(); // null for lifetime / permanent ban
            $table->boolean('is_lifetime')->default(false);
            $table->string('reason_category', 100); // malpractice_exam, disciplinary_misconduct, ragging_harassment, forged_documents, unauthorized_absence, non_payment, code_of_conduct_violation, other
            $table->text('reason_details')->nullable();
            $table->string('supporting_document', 255)->nullable();
            $table->string('status', 30)->default('active')->index(); // active, revoked, expired
            $table->text('revocation_reason')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'is_lifetime']);
        });

        // 3. Extend enrollments table with direct discontinuation tracking fields if not present
        Schema::table('enrollments', function (Blueprint $table) {
            if (! Schema::hasColumn('enrollments', 'cancellation_reason')) {
                $table->string('cancellation_reason', 150)->nullable()->after('remarks');
            }
            if (! Schema::hasColumn('enrollments', 'discontinued_at')) {
                $table->date('discontinued_at')->nullable()->after('cancellation_reason');
            }
            if (! Schema::hasColumn('enrollments', 'discontinued_by')) {
                $table->foreignId('discontinued_by')->nullable()->after('discontinued_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            if (Schema::hasColumn('enrollments', 'discontinued_by')) {
                $table->dropForeign(['discontinued_by']);
                $table->dropColumn('discontinued_by');
            }
            if (Schema::hasColumn('enrollments', 'discontinued_at')) {
                $table->dropColumn('discontinued_at');
            }
            if (Schema::hasColumn('enrollments', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
        });

        Schema::dropIfExists('student_disciplinary_actions');
        Schema::dropIfExists('course_withdrawal_requests');
    }
};
