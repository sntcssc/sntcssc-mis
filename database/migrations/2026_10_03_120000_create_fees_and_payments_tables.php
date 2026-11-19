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
        // 1. Fee Types Master Table
        Schema::create('fee_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique(); // e.g. tuition, library_security, registration, admission_test
            $table->string('name', 150);
            $table->string('category', 50)->default('academic'); // academic, library, admission, miscellaneous
            $table->string('frequency', 30)->default('monthly'); // one_time, weekly, monthly, quarterly, yearly
            $table->text('description')->nullable();
            $table->decimal('default_amount', 10, 2)->default(0.00);
            $table->boolean('is_refundable')->default(false); // true for library security deposit
            $table->string('status', 30)->default('active'); // active, inactive
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Fee Schedules (Admin batch/course timeline collection schedule)
        Schema::create('fee_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('schedule_no', 50)->unique(); // e.g. SCH-2026-0001
            $table->foreignId('fee_type_id')->constrained('fee_types')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('title', 200); // e.g. "Tuition Fees for January 2026"
            $table->string('academic_year', 20); // e.g. "2026-2027"
            $table->unsignedTinyInteger('period_month')->nullable(); // 1 to 12
            $table->unsignedSmallInteger('period_year')->nullable(); // e.g. 2026
            $table->string('billing_cycle', 30)->default('monthly'); // monthly, quarterly, yearly, one_time
            $table->date('due_date'); // Target due date (e.g. 7th of the month)
            $table->unsignedSmallInteger('grace_period_days')->default(7); // e.g. 1st to 7th
            $table->decimal('late_fee_amount', 10, 2)->default(0.00);
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active'); // draft, active, closed
            $table->unsignedInteger('total_targeted_students')->default(0);
            $table->decimal('total_invoiced_amount', 12, 2)->default(0.00);
            $table->decimal('total_collected_amount', 12, 2)->default(0.00);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['batch_id', 'course_id', 'status']);
        });

        // 3. Student Fee Invoices (Individual dues / bills)
        Schema::create('student_fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 50)->unique(); // e.g. INV-2026-00001
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // student
            $table->string('student_id', 20)->nullable()->index(); // 9-digit academic ID
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('fee_schedule_id')->nullable()->constrained('fee_schedules')->nullOnDelete();
            $table->foreignId('fee_type_id')->constrained('fee_types')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->unsignedSmallInteger('period_year')->nullable();
            $table->decimal('amount', 10, 2)->default(0.00); // Base calculated fee
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('late_fee_amount', 10, 2)->default(0.00);
            $table->decimal('total_amount', 10, 2)->default(0.00); // Net payable
            $table->decimal('paid_amount', 10, 2)->default(0.00);
            $table->decimal('balance_amount', 10, 2)->default(0.00);
            $table->date('due_date');
            $table->string('status', 30)->default('pending'); // pending, partial, paid, overdue, waived, cancelled
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->unsignedInteger('reminder_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['due_date', 'status']);
        });

        // 4. Payment Transactions (Online & Offline payments)
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_no', 50)->unique(); // e.g. TXN-2026-00001
            $table->foreignId('invoice_id')->nullable()->constrained('student_fee_invoices')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('student_id', 20)->nullable()->index();
            $table->foreignId('fee_type_id')->nullable()->constrained('fee_types')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('INR');
            $table->string('gateway', 30); // razorpay, phonepe, ccavenue, offline
            $table->string('payment_method', 50); // upi, card, netbanking, static_qr, neft, imps, bank_transfer, cash, cheque, dd
            $table->string('status', 30)->default('pending'); // pending, under_review, successful, failed, refunded, rejected
            $table->boolean('is_offline')->default(false);

            // Online Gateway Fields (Immutable once confirmed online)
            $table->string('gateway_order_id', 150)->nullable()->index();
            $table->string('gateway_payment_id', 150)->nullable()->index();
            $table->string('gateway_signature', 255)->nullable();
            $table->json('gateway_response')->nullable();

            // Offline Gateway Fields (Reviewable and editable by authorized staff)
            $table->string('offline_reference_id', 100)->nullable()->index(); // UTR, Cheque No, Bank Ref
            $table->date('offline_payment_date')->nullable();
            $table->string('offline_payment_time', 20)->nullable();
            $table->text('user_remarks')->nullable();
            $table->string('proof_document_path', 500)->nullable();

            // Administrative Verification & Receipting
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->text('admin_remarks')->nullable();
            $table->string('receipt_no', 50)->nullable()->unique(); // e.g. RCT-2026-00001
            $table->dateTime('receipt_generated_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['gateway', 'status']);
            $table->index(['is_offline', 'status']);
        });

        // 5. Student Ledgers (Unified Double-entry / Banking Architecture)
        Schema::create('student_ledgers', function (Blueprint $table) {
            $table->id();
            $table->string('ledger_no', 50)->unique(); // e.g. LDG-2026-00001
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('student_id', 20)->nullable()->index();
            $table->string('entry_type', 10); // debit (charge/liability), credit (payment/waiver)
            $table->decimal('amount', 10, 2);
            $table->decimal('balance_after', 10, 2)->default(0.00); // Running cumulative student liability balance
            $table->string('reference_type', 50); // invoice, payment, refund, deposit, waiver, adjustment
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description', 255);
            $table->date('transaction_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'entry_type']);
            $table->index(['transaction_date']);
        });

        // 6. Library Deposits & Refunds (Refundable Security Deposit Management)
        Schema::create('library_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('deposit_no', 50)->unique(); // e.g. LIB-DEP-2026-00001
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('student_id', 20)->nullable()->index();
            $table->decimal('amount', 10, 2); // 500, 1000, 1500, 2000, 2500, 3000 etc.
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->date('deposit_date');
            $table->string('status', 30)->default('active'); // active, refund_requested, refund_approved, refund_processed, refund_rejected, forfeited

            // Refund Request Lifecycle
            $table->dateTime('refund_requested_at')->nullable();
            $table->foreignId('refund_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('refund_reason')->nullable();
            $table->string('bank_name', 150)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('ifsc_code', 20)->nullable();
            $table->string('account_holder_name', 150)->nullable();
            $table->string('upi_id', 100)->nullable();

            // Refund Review & Processing
            $table->foreignId('refund_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('refund_reviewed_at')->nullable();
            $table->decimal('refund_approved_amount', 10, 2)->nullable();
            $table->foreignId('refund_processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('refund_processed_at')->nullable();
            $table->string('refund_transaction_ref', 100)->nullable(); // UTR / IMPS ref of refund paid to student
            $table->string('refund_receipt_path', 500)->nullable();
            $table->text('admin_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_deposits');
        Schema::dropIfExists('student_ledgers');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('student_fee_invoices');
        Schema::dropIfExists('fee_schedules');
        Schema::dropIfExists('fee_types');
    }
};
