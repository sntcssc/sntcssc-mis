<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\LibraryDeposit;
use App\Models\PaymentTransaction;
use App\Models\Setting;
use App\Models\StudentFeeInvoice;
use App\Models\StudentLedger;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Library\LibraryClearanceService;
use App\Services\Payment\FeePaymentService;
use App\Services\Payment\FeeReminderService;
use App\Services\Payment\FeeScheduleService;
use App\Services\Payment\Gateways\OfflineGateway;
use App\Services\Payment\LibraryDepositService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\ReceiptService;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Database\Seeders\FeeTypeSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);
    $this->seed(FeeTypeSeeder::class);
    $this->seed(SettingsSeeder::class);
    Storage::fake('public');
});

test('fee types are seeded properly including monthly tuition and refundable library deposit', function () {
    $tuition = FeeType::where('code', 'tuition')->first();
    $library = FeeType::where('code', 'library_security')->first();

    expect($tuition)->not->toBeNull()
        ->and($tuition->category)->toBe(FeeType::CATEGORY_ACADEMIC)
        ->and($tuition->frequency)->toBe(FeeType::FREQUENCY_MONTHLY)
        ->and($tuition->is_refundable)->toBeFalse()
        ->and($library)->not->toBeNull()
        ->and($library->category)->toBe(FeeType::CATEGORY_LIBRARY)
        ->and($library->is_refundable)->toBeTrue();
});

test('batch fee schedule initiation generates monthly tuition invoices with category pricing and ledger debit', function () {
    $course = Course::where('code', 'CC')->first(); // 1000 UR, 500 Reserved
    $batch = Batch::first();

    // Create 2 students: one UR, one SC
    $urStudent = User::factory()->create();
    $urStudent->assignRole('Student');
    StudentProfile::create([
        'user_id' => $urStudent->id,
        'category' => 'UR',
    ]);
    Enrollment::create([
        'user_id' => $urStudent->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'status' => 'active',
    ]);

    $scStudent = User::factory()->create();
    $scStudent->assignRole('Student');
    StudentProfile::create([
        'user_id' => $scStudent->id,
        'category' => 'SC',
    ]);
    Enrollment::create([
        'user_id' => $scStudent->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'status' => 'active',
    ]);

    $tuitionType = FeeType::where('code', 'tuition')->first();

    $schedule = FeeScheduleService::initiateBatchCourseFeeSchedule([
        'title' => 'Tuition Fees for January 2026',
        'fee_type_id' => $tuitionType->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'period_month' => 1,
        'period_year' => 2026,
        'due_date' => '2026-01-07',
        'grace_period_days' => 3,
        'late_fee_amount' => 50.00,
    ]);

    expect($schedule)->not->toBeNull()
        ->and($schedule->status)->toBe(FeeSchedule::STATUS_ACTIVE);

    // Verify UR student invoice: ₹1000
    $urInvoice = StudentFeeInvoice::where('user_id', $urStudent->id)
        ->where('fee_schedule_id', $schedule->id)
        ->first();
    expect($urInvoice)->not->toBeNull()
        ->and((float) $urInvoice->total_amount)->toBe(1000.0)
        ->and($urInvoice->status)->toBe(StudentFeeInvoice::STATUS_PENDING);

    // Verify SC student invoice: ₹500
    $scInvoice = StudentFeeInvoice::where('user_id', $scStudent->id)
        ->where('fee_schedule_id', $schedule->id)
        ->first();
    expect($scInvoice)->not->toBeNull()
        ->and((float) $scInvoice->total_amount)->toBe(500.0)
        ->and($scInvoice->status)->toBe(StudentFeeInvoice::STATUS_PENDING);

    // Verify Double-Entry Ledger: Debit entries created for both students
    $urLedger = StudentLedger::where('user_id', $urStudent->id)
        ->where('entry_type', StudentLedger::ENTRY_DEBIT)
        ->first();
    expect($urLedger)->not->toBeNull()
        ->and((float) $urLedger->amount)->toBe(1000.0)
        ->and((float) $urLedger->balance_after)->toBe(1000.0);

    $scLedger = StudentLedger::where('user_id', $scStudent->id)
        ->where('entry_type', StudentLedger::ENTRY_DEBIT)
        ->first();
    expect($scLedger)->not->toBeNull()
        ->and((float) $scLedger->amount)->toBe(500.0)
        ->and((float) $scLedger->balance_after)->toBe(500.0);
});

test('offline payment submission, review, approval, receipt generation, and ledger credit', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $tuitionType = FeeType::where('code', 'tuition')->first();
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'title' => 'Tuition Fees for January 2026',
        'period_month' => 1,
        'period_year' => 2026,
        'due_date' => '2026-01-07',
        'amount' => 1000.00,
        'total_amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    // Record initial invoice debit in ledger
    StudentLedger::recordDebit(
        userId: $student->id,
        amount: 1000.00,
        referenceType: StudentLedger::REF_INVOICE,
        referenceId: $invoice->id,
        description: 'Invoiced: Tuition Fees for January 2026'
    );

    // Student submits offline payment via Static UPI QR
    $proofFile = UploadedFile::fake()->image('payment_receipt.jpg');
    $transaction = FeePaymentService::recordOfflinePayment([
        'user_id' => $student->id,
        'invoice_id' => $invoice->id,
        'amount' => 1000.00,
        'payment_method' => 'Static UPI QR',
        'offline_reference_id' => 'UPI9876543210',
        'offline_payment_date' => '2026-01-05',
        'offline_payment_time' => '14:30',
        'user_remarks' => 'Paid via GPay to Institute UPI QR code',
    ], $proofFile, $student->id);

    expect($transaction->status)->toBe(PaymentTransaction::STATUS_UNDER_REVIEW)
        ->and($transaction->is_offline)->toBeTrue()
        ->and($transaction->proof_document_path)->not->toBeNull()
        ->and(Storage::disk('public')->exists($transaction->proof_document_path))->toBeTrue();

    // Staff admin verifies and approves payment
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $approvedTxn = FeePaymentService::verifyOfflinePayment(
        transaction: $transaction,
        approved: true,
        verifiedBy: $admin->id,
        adminRemarks: 'Verified in institute ICICI Bank account',
        updatedFields: [
            'amount' => 1000.00,
            'offline_reference_id' => 'UPI9876543210-VERIFIED',
        ]
    );

    expect($approvedTxn->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL)
        ->and($approvedTxn->verified_by)->toBe($admin->id)
        ->and($approvedTxn->receipt_no)->not->toBeNull();

    // Verify invoice is now paid
    $freshInvoice = $invoice->fresh();
    expect($freshInvoice->status)->toBe(StudentFeeInvoice::STATUS_PAID)
        ->and((float) $freshInvoice->paid_amount)->toBe(1000.00)
        ->and((float) $freshInvoice->balance_amount)->toBe(0.00);

    // Verify double-entry ledger credit: Running balance becomes ₹0
    $latestLedger = StudentLedger::where('user_id', $student->id)->latest('id')->first();
    expect($latestLedger->entry_type)->toBe(StudentLedger::ENTRY_CREDIT)
        ->and((float) $latestLedger->amount)->toBe(1000.00)
        ->and((float) $latestLedger->balance_after)->toBe(0.00);
});

test('online payment gateway settlement is immutable and clears invoice', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $tuitionType = FeeType::where('code', 'tuition')->first();
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'title' => 'Tuition Fees for February 2026',
        'period_month' => 2,
        'period_year' => 2026,
        'due_date' => '2026-02-07',
        'amount' => 1000.00,
        'total_amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    // Create online pending payment transaction
    $txn = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'invoice_id' => $invoice->id,
        'user_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'amount' => 1000.00,
        'currency' => 'INR',
        'gateway' => 'razorpay',
        'payment_method' => 'card',
        'status' => PaymentTransaction::STATUS_PENDING,
        'is_offline' => false,
    ]);

    // Complete online transaction
    $completedTxn = FeePaymentService::completeOnlinePayment(
        transaction: $txn,
        gatewayPaymentId: 'pay_ABC123456789',
        signature: 'sig_XYZ987654',
        responsePayload: ['bank' => 'HDFC', 'status' => 'captured']
    );

    expect($completedTxn->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL)
        ->and($completedTxn->is_offline)->toBeFalse()
        ->and($completedTxn->gateway)->toBe('razorpay')
        ->and($invoice->fresh()->status)->toBe(StudentFeeInvoice::STATUS_PAID)
        ->and($completedTxn->receipt_no)->not->toBeNull();

    // Online transactions cannot be edited by verifyOfflinePayment
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    expect(fn () => FeePaymentService::verifyOfflinePayment($completedTxn, true, $admin->id))
        ->toThrow(ValidationException::class);
});

test('library security deposit collection, student refund claim, admin approval and disbursement flow', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    // Collect ₹2,000 library security deposit directly
    $deposit = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 2000.00,
        'payment_method' => 'Static UPI QR',
        'payment_reference' => 'LIB-UPI-100200',
        'remarks' => 'Book deposit for UPSC civil services reading hall',
    ], recordedBy: $admin->id);

    expect($deposit->status)->toBe(LibraryDeposit::STATUS_ACTIVE)
        ->and((float) $deposit->amount)->toBe(2000.00)
        ->and($deposit->deposit_no)->toStartWith('LIB-DEP-');

    // Student raises refund request with bank details
    $refundClaim = LibraryDepositService::requestRefund($deposit, requestedBy: $student->id, bankDetails: [
        'refund_reason' => 'Completed course and returned all borrowed books in good condition',
        'bank_name' => 'State Bank of India',
        'account_holder_name' => $student->name,
        'account_number' => '30123456789',
        'ifsc_code' => 'SBIN0001234',
        'branch_name' => 'Salt Lake Sector 1',
    ]);

    expect($refundClaim->status)->toBe(LibraryDeposit::STATUS_REFUND_REQUESTED)
        ->and($refundClaim->refund_requested_at)->not->toBeNull();

    // Librarian issues clearance certificate
    LibraryClearanceService::issueClearanceCertificate($refundClaim, clearedBy: $admin->id, remarks: 'Verified physical records. Zero unreturned books.');

    // Admin reviews and approves refund request
    $approvedClaim = LibraryDepositService::approveRefund($refundClaim, reviewedBy: $admin->id, approvedAmount: 2000.00, adminNotes: 'Library card verified, zero dues clearance confirmed');
    expect($approvedClaim->status)->toBe(LibraryDeposit::STATUS_REFUND_APPROVED)
        ->and($approvedClaim->refund_reviewed_at)->not->toBeNull();

    // Accountant disburses refund with UTR reference
    $proofFile = UploadedFile::fake()->image('disbursement_advice.png');
    $disbursedClaim = LibraryDepositService::processDisbursement(
        deposit: $approvedClaim,
        processedBy: $admin->id,
        transactionRef: 'NEFT-SBI-20261003-8899',
        proofFile: $proofFile,
        adminNotes: 'NEFT initiated from institute operational account'
    );

    expect($disbursedClaim->status)->toBe(LibraryDeposit::STATUS_REFUND_PROCESSED)
        ->and($disbursedClaim->refund_transaction_ref)->toBe('NEFT-SBI-20261003-8899')
        ->and($disbursedClaim->refund_receipt_path)->not->toBeNull();

    // Verify Ledger records debit for the refunded security deposit
    $refundLedger = StudentLedger::where('user_id', $student->id)
        ->where('entry_type', StudentLedger::ENTRY_DEBIT)
        ->where('reference_type', StudentLedger::REF_REFUND)
        ->first();
    expect($refundLedger)->not->toBeNull()
        ->and((float) $refundLedger->amount)->toBe(2000.00)
        ->and((float) $refundLedger->balance_after)->toBe(0.00);
});

test('receipt service generates valid official receipt pdf and authorizes download', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $tuitionType = FeeType::where('code', 'tuition')->first();
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'title' => 'Tuition Fees for March 2026',
        'period_month' => 3,
        'period_year' => 2026,
        'due_date' => '2026-03-07',
        'amount' => 1000.00,
        'total_amount' => 1000.00,
        'paid_amount' => 1000.00,
        'balance_amount' => 0.00,
        'status' => StudentFeeInvoice::STATUS_PAID,
    ]);

    $transaction = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'receipt_no' => PaymentTransaction::generateReceiptNo(),
        'user_id' => $student->id,
        'invoice_id' => $invoice->id,
        'fee_type_id' => $tuitionType->id,
        'gateway' => PaymentTransaction::GATEWAY_OFFLINE,
        'amount' => 1000.00,
        'currency' => 'INR',
        'payment_method' => 'Static UPI QR',
        'is_offline' => true,
        'status' => PaymentTransaction::STATUS_SUCCESSFUL,
        'paid_at' => now(),
    ]);

    $pdf = ReceiptService::generatePdf($transaction);

    expect($pdf)->not->toBeNull()
        ->and($pdf->output())->not->toBeEmpty();

    // Student can download their own receipt
    $response = $this->actingAs($student)->get(route('receipts.download', $transaction));
    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    // Another student cannot access
    $otherStudent = User::factory()->create();
    $otherStudent->assignRole('Student');
    $unauthorizedResponse = $this->actingAs($otherStudent)->get(route('receipts.download', $transaction));
    $unauthorizedResponse->assertForbidden();

    // Admin can download any receipt
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $adminResponse = $this->actingAs($admin)->get(route('receipts.download', $transaction));
    $adminResponse->assertOk();
});

test('fee reminder service dispatches multi-channel alerts and records logs', function () {
    $student = User::factory()->create(['phone' => '9876543210']);
    $student->assignRole('Student');

    Setting::set('email.is_enabled', true);
    Setting::set('sms.enabled', true);
    Setting::set('sms.driver', 'log');

    $tuitionType = FeeType::where('code', 'tuition')->first();
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'title' => 'Tuition Fees for April 2026',
        'period_month' => 4,
        'period_year' => 2026,
        'due_date' => '2026-04-07',
        'amount' => 1000.00,
        'total_amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    $result = FeeReminderService::sendInvoiceReminder($invoice, ['database', 'email', 'sms', 'whatsapp']);

    expect($result['success'])->toBeTrue()
        ->and($result['channels_sent'])->toContain('database', 'email', 'sms', 'whatsapp')
        ->and($invoice->fresh()->reminder_sent_at)->not->toBeNull()
        ->and($invoice->fresh()->reminder_count)->toBe(1);
});

test('payment gateway manager falls back to offline gateway when online gateways are disabled', function () {
    $manager = app(PaymentGatewayManager::class);

    // By default in settings online gateways are disabled or unconfigured
    $gateways = $manager->getAvailableGateways();

    expect($gateways)->toHaveKey('offline');
    $offline = $gateways['offline'];

    expect($offline->name())->toBe('Offline Payment (Static UPI QR, NEFT/IMPS, Cash Counter)')
        ->and($offline->id())->toBe('offline');
});

test('livewire admin and student payments components render without errors', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create();
    $student->assignRole('Student');

    // Admin components
    $this->actingAs($admin);
    Livewire::test('pages::admin.payments.fees')
        ->assertStatus(200);

    Livewire::test('pages::admin.payments.schedules')
        ->assertStatus(200);

    Livewire::test('pages::admin.payments.verifications')
        ->assertStatus(200);

    Livewire::test('pages::admin.payments.library')
        ->assertStatus(200);

    Livewire::test('pages::admin.payments.transactions')
        ->assertStatus(200);

    Livewire::test('pages::admin.payments.reports')
        ->assertStatus(200);

    // Student component
    $this->actingAs($student);
    Livewire::test('pages::student.payments')
        ->assertStatus(200);
});

test('admin can revert a fee schedule, cancelling unpaid invoices and posting ledger offsetting credits', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $course = Course::first();
    $batch = Batch::first();

    $student = User::factory()->create();
    $student->assignRole('Student');
    Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'status' => 'active',
    ]);

    $tuitionType = FeeType::where('code', 'tuition')->first();
    $schedule = FeeScheduleService::initiateBatchCourseFeeSchedule([
        'title' => 'Tuition Fees for May 2026',
        'fee_type_id' => $tuitionType->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'period_month' => 5,
        'period_year' => 2026,
        'due_date' => '2026-05-07',
    ]);

    expect($schedule->invoices()->count())->toBe(1);
    $invoice = $schedule->invoices()->first();
    expect($invoice->status)->toBe(StudentFeeInvoice::STATUS_PENDING);

    // Initial student ledger should have debit
    $initialBalance = StudentLedger::getCurrentBalance($student->id);
    expect($initialBalance)->toBeGreaterThan(0);

    // Revert the schedule
    $result = FeeScheduleService::revertSchedule($schedule, 'Course restructuring for May 2026', $admin->id);

    expect($result['success'])->toBeTrue()
        ->and($result['cancelled_count'])->toBe(1)
        ->and($schedule->fresh()->status)->toBe(FeeSchedule::STATUS_CANCELLED)
        ->and($invoice->fresh()->status)->toBe(StudentFeeInvoice::STATUS_CANCELLED);

    // Student ledger should now have 0 net balance due to offsetting adjustment
    $finalBalance = StudentLedger::getCurrentBalance($student->id);
    expect($finalBalance)->toBe(0.0);
});

test('admin cannot delete a fee schedule if payments were already collected, but can delete uncollected schedules', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $course = Course::first();
    $batch = Batch::first();
    $student = User::factory()->create();
    $student->assignRole('Student');
    Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'status' => 'active',
    ]);

    $tuitionType = FeeType::where('code', 'tuition')->first();
    $schedule = FeeScheduleService::initiateBatchCourseFeeSchedule([
        'title' => 'Tuition Fees for June 2026',
        'fee_type_id' => $tuitionType->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'period_month' => 6,
        'period_year' => 2026,
        'due_date' => '2026-06-07',
    ]);

    $invoice = $schedule->invoices()->first();

    // Collect payment on the invoice
    FeePaymentService::recordManualCollection([
        'student_id' => $student->id,
        'invoice_id' => $invoice->id,
        'amount' => (float) $invoice->total_amount,
        'payment_method' => 'cash',
        'payment_reference' => 'CASH-REC-001',
    ], $admin->id);

    // Update schedule stats
    $schedule->updateTotalCollected();
    expect($schedule->fresh()->total_collected_amount)->toBeGreaterThan(0);

    // Hard delete must fail due to financial safeguards
    expect(fn () => FeeScheduleService::deleteSchedule($schedule, $admin->id))
        ->toThrow(ValidationException::class);

    // Now test an uncollected schedule can be cleanly deleted
    $uncollectedSchedule = FeeScheduleService::initiateBatchCourseFeeSchedule([
        'title' => 'Tuition Fees for July 2026',
        'fee_type_id' => $tuitionType->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'period_month' => 7,
        'period_year' => 2026,
        'due_date' => '2026-07-07',
    ]);

    $delResult = FeeScheduleService::deleteSchedule($uncollectedSchedule, $admin->id);
    expect($delResult['success'])->toBeTrue()
        ->and(FeeSchedule::find($uncollectedSchedule->id))->toBeNull();
});

test('admin can manually collect and add fees for any student with live receipt generation and ledger entry', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $tuitionType = FeeType::where('code', 'tuition')->first();

    // 1. Collect ad-hoc fee without existing invoice
    $payment = FeePaymentService::recordManualCollection([
        'student_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'custom_title' => 'Special Coaching Fee',
        'amount' => 1500.00,
        'payment_method' => 'upi',
        'payment_reference' => 'UPI-REF-998877',
        'period_month' => 8,
        'period_year' => 2026,
        'remarks' => 'Direct payment received via counter UPI QR',
    ], $admin->id);

    expect($payment)->not->toBeNull()
        ->and($payment->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL)
        ->and((float) $payment->amount)->toBe(1500.00)
        ->and($payment->receipt_no)->not->toBeNull()
        ->and($payment->invoice)->not->toBeNull()
        ->and($payment->invoice->status)->toBe(StudentFeeInvoice::STATUS_PAID);

    // Verify student ledger balance is 0 after debit + credit
    expect(StudentLedger::getCurrentBalance($student->id))->toBe(0.0);

    // 2. Collect library security deposit manually - verify LibraryDeposit record is activated
    $libraryType = FeeType::where('code', 'library_security')->first();
    $libPayment = FeePaymentService::recordManualCollection([
        'student_id' => $student->id,
        'fee_type_id' => $libraryType->id,
        'custom_title' => 'Library Caution Money Deposit',
        'amount' => 2000.00,
        'payment_method' => 'bank_transfer',
        'payment_reference' => 'NEFT-554433221',
        'remarks' => 'Refundable security deposit collected',
    ], $admin->id);

    expect($libPayment)->not->toBeNull()
        ->and($libPayment->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL);

    $deposit = LibraryDeposit::where('user_id', $student->id)->first();
    expect($deposit)->not->toBeNull()
        ->and($deposit->status)->toBe(LibraryDeposit::STATUS_ACTIVE)
        ->and((float) $deposit->amount)->toBe(2000.00);
});

test('admin can cancel or delete individual invoices with audit safeguards', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create();
    $student->assignRole('Student');
    $tuitionType = FeeType::where('code', 'tuition')->first();

    // Create invoice
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'title' => 'Tuition Fees for August 2026',
        'period_month' => 8,
        'period_year' => 2026,
        'due_date' => '2026-08-07',
        'amount' => 1200.00,
        'total_amount' => 1200.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1200.00,
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);
    StudentLedger::recordDebit(
        userId: $student->id,
        amount: 1200.00,
        description: 'Invoice generated: August 2026',
        referenceType: StudentLedger::REF_INVOICE,
        referenceId: $invoice->id
    );

    expect(StudentLedger::getCurrentBalance($student->id))->toBe(1200.0);

    // Cancel invoice
    $cancelResult = FeePaymentService::cancelInvoice($invoice, 'Administrative waiver', $admin->id);
    expect($cancelResult)->toBeInstanceOf(StudentFeeInvoice::class)
        ->and($cancelResult->status)->toBe(StudentFeeInvoice::STATUS_CANCELLED)
        ->and($invoice->fresh()->status)->toBe(StudentFeeInvoice::STATUS_CANCELLED)
        ->and(StudentLedger::getCurrentBalance($student->id))->toBe(0.0);

    // Create another invoice and test uncollected delete
    $invoice2 = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'title' => 'Tuition Fees for September 2026',
        'period_month' => 9,
        'period_year' => 2026,
        'due_date' => '2026-09-07',
        'amount' => 1200.00,
        'total_amount' => 1200.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1200.00,
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);
    StudentLedger::recordDebit(
        userId: $student->id,
        amount: 1200.00,
        description: 'Invoice generated: September 2026',
        referenceType: StudentLedger::REF_INVOICE,
        referenceId: $invoice2->id
    );

    $delResult = FeePaymentService::deleteInvoice($invoice2, $admin->id);
    expect($delResult)->toBeTrue()
        ->and(StudentFeeInvoice::find($invoice2->id))->toBeNull()
        ->and(StudentLedger::getCurrentBalance($student->id))->toBe(0.0);
});

test('livewire schedules and fees admin workflows support revert, delete, and manual fee collection', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $course = Course::first();
    $batch = Batch::first();
    Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'status' => 'active',
    ]);

    $tuitionType = FeeType::where('code', 'tuition')->first();
    $schedule = FeeScheduleService::initiateBatchCourseFeeSchedule([
        'title' => 'Tuition Fees for October 2026',
        'fee_type_id' => $tuitionType->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'period_month' => 10,
        'period_year' => 2026,
        'due_date' => '2026-10-07',
    ]);

    $this->actingAs($admin);

    // Test Schedules component revert modal and execution
    Livewire::test('pages::admin.payments.schedules')
        ->call('openRevertModal', $schedule->id)
        ->assertSet('showRevertModal', true)
        ->set('revertReason', 'Term schedule cancelled by administration')
        ->call('revertSchedule')
        ->assertSet('showRevertModal', false);

    expect($schedule->fresh()->status)->toBe(FeeSchedule::STATUS_CANCELLED);

    // Test Fees component manual collection workflow
    Livewire::test('pages::admin.payments.fees')
        ->call('openDirectCollectionModal')
        ->assertSet('showCollectModal', true)
        ->call('selectStudent', $student->id)
        ->assertSet('selectedUserId', $student->id)
        ->set('selectedFeeTypeId', $tuitionType->id)
        ->set('collectAmount', 850.00)
        ->set('collectMethod', 'cash')
        ->set('collectReference', 'CTR-CASH-789')
        ->call('saveCollection')
        ->assertSet('showCollectModal', false);

    $latestPayment = PaymentTransaction::where('user_id', $student->id)->latest('id')->first();
    expect($latestPayment)->not->toBeNull()
        ->and((float) $latestPayment->amount)->toBe(850.00);
});

test('receipt service generates PDF with DejaVu Sans unicode rupee symbol without errors', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $tuitionType = FeeType::where('code', 'tuition')->first();

    $payment = FeePaymentService::recordManualCollection([
        'student_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'custom_title' => 'Tuition Fees for February 2026',
        'amount' => 1000.00,
        'payment_method' => 'cash',
        'payment_reference' => 'CASH-REC-PDF-01',
        'period_month' => 2,
        'period_year' => 2026,
    ]);

    $pdf = ReceiptService::generatePdf($payment);
    $output = $pdf->output();

    expect($output)->not->toBeEmpty()
        ->and(str_starts_with($output, '%PDF'))->toBeTrue();
});

test('manual fee collection modal supports month and year selection for tuition fees and stores billing period', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $tuitionType = FeeType::where('code', 'tuition')->first();

    $this->actingAs($admin);

    Livewire::test('pages::admin.payments.fees')
        ->call('openDirectCollectionModal')
        ->assertSet('showCollectModal', true)
        ->call('selectStudent', $student->id)
        ->set('selectedFeeTypeId', $tuitionType->id)
        ->set('periodMonth', 3)
        ->set('periodYear', 2026)
        ->assertSet('periodMonth', 3)
        ->assertSet('periodYear', 2026)
        ->assertSet('customFeeTitle', "{$tuitionType->name} for March 2026")
        ->set('collectAmount', 1200.00)
        ->set('collectMethod', 'cash')
        ->set('collectReference', 'CASH-MAR-2026')
        ->call('saveCollection')
        ->assertSet('showCollectModal', false);

    $invoice = StudentFeeInvoice::where('user_id', $student->id)->latest('id')->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->period_month)->toBe(3)
        ->and($invoice->period_year)->toBe(2026)
        ->and($invoice->title)->toContain('March 2026')
        ->and((float) $invoice->paid_amount)->toBe(1200.00);
});

test('fee reports component generates fee type month-wise reports with filters and supports excel, csv, and pdf export', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create();
    $student->assignRole('Student');
    $tuitionType = FeeType::where('code', 'tuition')->first();

    // Create an invoice
    FeePaymentService::recordManualCollection([
        'student_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'custom_title' => 'Tuition Fees for April 2026',
        'amount' => 1000.00,
        'payment_method' => 'upi',
        'payment_reference' => 'UPI-APR-2026',
        'period_month' => 4,
        'period_year' => 2026,
    ], $admin->id);

    $this->actingAs($admin);

    $component = Livewire::test('pages::admin.payments.reports')
        ->assertSet('activeTab', 'feetype')
        ->set('feeTypeFilter', (string) $tuitionType->id)
        ->set('monthFilter', '4')
        ->set('yearFilter', '2026')
        ->set('statusFilter', 'paid')
        ->assertSee('April 2026')
        ->assertSee('₹1,000.00');

    // Test XLSX, CSV, and PDF exports
    $xlsxExport = $component->call('export', 'xlsx');
    expect($xlsxExport)->not->toBeNull();

    $csvExport = $component->call('export', 'csv');
    expect($csvExport)->not->toBeNull();

    $pdfExport = $component->call('export', 'pdf');
    expect($pdfExport)->not->toBeNull();
});

test('non-student users and administrators cannot access student portal routes and are redirected', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $team = $admin->currentTeam;

    // Super Administrator trying to access student payments should be redirected to admin fees desk
    $this->actingAs($admin);
    $response = $this->get(route('student.payments.index', ['current_team' => $team->slug]));
    $response->assertRedirect(route('admin.payments.fees', ['current_team' => $team->slug]));

    // Super Administrator trying to access student academics should be redirected to courses
    $academicsResponse = $this->get(route('student.academics.index', ['current_team' => $team->slug]));
    $academicsResponse->assertRedirect(route('admin.courses.index', ['current_team' => $team->slug]));

    // Plain candidate/user without roles trying to access student payments is redirected to dashboard
    $plainUser = User::factory()->create();
    $plainTeam = $plainUser->currentTeam;
    $this->actingAs($plainUser);
    $plainResponse = $this->get(route('student.payments.index', ['current_team' => $plainTeam->slug]));
    $plainResponse->assertRedirect(route('dashboard', ['current_team' => $plainTeam->slug]));
});

test('sidebar MAIN navigation items only show Academics and Fees for enrolled students, not for administrators', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $team = $admin->currentTeam;

    $this->actingAs($admin);
    $response = $this->get(route('dashboard', ['current_team' => $team->slug]));
    $response->assertOk();

    // Student specific links in MAIN must NOT appear for admin
    $content = $response->getContent();
    // The admin sidebar should have admin payment desks, not the student portal links in MAIN
    expect($content)->toContain(route('admin.payments.fees'))
        ->and($admin->isStudent())->toBeFalse();

    // For a student, isStudent() is true and they can access the student portal
    $student = User::factory()->create();
    $student->assignRole('Student');
    $studentTeam = $student->currentTeam;
    expect($student->isStudent())->toBeTrue();

    $this->actingAs($student);
    $studentResponse = $this->get(route('student.payments.index', ['current_team' => $studentTeam->slug]));
    $studentResponse->assertOk();
});

test('compact fee receipt pdf renders strictly on a single A4 page', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $course = Course::first();
    $batch = Batch::first();

    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    $tuitionType = FeeType::where('code', 'tuition')->first();
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'enrollment_id' => $enrollment->id,
        'title' => 'Monthly Tuition Fees for November 2026 - Comprehensive Course',
        'period_month' => 11,
        'period_year' => 2026,
        'due_date' => '2026-11-07',
        'amount' => 1200.00,
        'discount_amount' => 200.00,
        'late_fee_amount' => 50.00,
        'total_amount' => 1050.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1050.00,
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $payment = FeePaymentService::recordManualCollection([
        'student_id' => $student->id,
        'invoice_id' => $invoice->id,
        'amount' => 1050.00,
        'payment_method' => 'bank_transfer',
        'payment_reference' => 'NEFT-COMPACT-TEST-001',
    ], $admin->id);

    $pdf = ReceiptService::generatePdf($payment);
    $dompdf = $pdf->getDomPDF();
    $pageCount = $dompdf->getCanvas()->get_page_count();

    expect($pageCount)->toBe(1);
});

test('offline gateway generates valid static upi qr code image data uri', function () {
    $offline = new OfflineGateway;
    $details = $offline->getUpiDetails(750.00, 'TXN-QR-TEST');

    expect($details['enabled'])->toBeTrue()
        ->and($details['upi_id'])->not->toBeEmpty()
        ->and($details['upi_uri'])->toContain('pa=')
        ->and($details['upi_uri'])->toContain('am=750.00')
        ->and($details['has_custom_qr'])->toBeFalse()
        ->and($details['qr_code_data_uri'])->toStartWith('data:image/')
        ->and($details['qr_code_data_uri'])->toContain(';base64,')
        ->and(strlen($details['qr_code_data_uri']))->toBeGreaterThan(100);
});

test('offline gateway supports custom uploaded qr code standee image with fallback', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->image('institute_upi_standee.png', 400, 400);
    $path = $file->store('payments/qr', 'public');

    Setting::set('payment.offline_custom_qr_image', $path);

    $offline = new OfflineGateway;
    $details = $offline->getUpiDetails(1200.00, 'TXN-CUSTOM-QR');

    expect($details['has_custom_qr'])->toBeTrue()
        ->and($details['custom_qr_image_url'])->toContain($path)
        ->and($details['qr_image_url'])->toContain($path)
        ->and($details['qr_code_data_uri'])->toStartWith('data:image/');

    // Test removing custom image reverts back to dynamic generation
    Setting::set('payment.offline_custom_qr_image', null);
    $freshDetails = $offline->getUpiDetails(1200.00, 'TXN-CUSTOM-QR');

    expect($freshDetails['has_custom_qr'])->toBeFalse()
        ->and($freshDetails['custom_qr_image_url'])->toBeNull()
        ->and($freshDetails['qr_image_url'])->toStartWith('data:image/');
});

test('admin can upload and remove custom qr code image in payment settings', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $this->actingAs($admin);

    Storage::fake('public');
    $file = UploadedFile::fake()->image('custom_sbi_qr.png', 500, 500);

    Livewire::test('pages::admin.settings.payment')
        ->set('customQrImage', $file)
        ->call('save')
        ->assertHasNoErrors();

    $savedPath = Setting::get('payment.offline_custom_qr_image');
    expect($savedPath)->not->toBeEmpty();
    Storage::disk('public')->assertExists($savedPath);

    // Call removeCustomQrImage
    Livewire::test('pages::admin.settings.payment')
        ->call('removeCustomQrImage')
        ->assertHasNoErrors();

    expect(Setting::get('payment.offline_custom_qr_image'))->toBeNull();
    Storage::disk('public')->assertMissing($savedPath);
});

test('configured and enabled online payment gateway displays in available gateways and student portal', function () {
    // Configure CCAVenue
    Setting::set('payment.enabled', true);
    Setting::set('payment.driver', 'ccavenue');
    Setting::set('payment.ccavenue_enabled', true);
    Setting::set('payment.ccavenue_merchant_id', '2955447');
    Setting::set('payment.ccavenue_access_code', 'ABHAHFFYUUUAUUAUAUUAUAUA');
    Setting::set('payment.ccavenue_mode', 'test');
    Setting::set('payment.offline_enabled', true);

    $manager = app(PaymentGatewayManager::class);
    $available = $manager->getAvailableGateways();

    expect($available)->toHaveKey('ccavenue')
        ->and($available)->toHaveKey('offline')
        ->and($available['ccavenue']->isOnline())->toBeTrue()
        ->and($available['ccavenue']->isEnabled())->toBeTrue()
        ->and($available['ccavenue']->isConfigured())->toBeTrue()
        ->and($manager->getDefaultGateway()->id())->toBe('ccavenue');

    // Test in Student Portal Livewire component
    $student = User::factory()->create();
    $student->assignRole('Student');
    $this->actingAs($student);

    Livewire::test('pages::student.payments')
        ->call('openDepositCheckout')
        ->assertSee('CCAVenue Payment Gateway')
        ->assertSee('Offline Payment');
});

test('student fee portal supports payment gateway selection between online and offline', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $course = Course::first();
    $batch = Batch::first();
    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    $tuitionType = FeeType::where('code', 'tuition')->first();
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'enrollment_id' => $enrollment->id,
        'title' => 'Tuition Fees Checkout Test',
        'period_month' => 5,
        'period_year' => 2026,
        'due_date' => '2026-05-07',
        'amount' => 800.00,
        'total_amount' => 800.00,
        'paid_amount' => 0.00,
        'balance_amount' => 800.00,
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    // Enable both CCAVenue online and Offline
    Setting::set('payment.enabled', true);
    Setting::set('payment.ccavenue_enabled', true);
    Setting::set('payment.ccavenue_merchant_id', '2955447');
    Setting::set('payment.ccavenue_access_code', 'ABHAHFFYUUUAUUAUAUUAUAUA');
    Setting::set('payment.offline_enabled', true);

    $this->actingAs($student);

    // 1. Online Gateway Checkout Flow
    Livewire::test('pages::student.payments')
        ->call('openInvoiceCheckout', $invoice->id)
        ->assertSet('showCheckoutModal', true)
        ->set('selectedGateway', 'ccavenue')
        ->call('submitPayment')
        ->assertHasNoErrors()
        ->assertSet('showCheckoutModal', false);

    expect($invoice->fresh()->status)->toBe(StudentFeeInvoice::STATUS_PAID)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(800.00)
        ->and((float) $invoice->fresh()->balance_amount)->toBe(0.00);

    // 2. Offline Gateway Checkout Flow with second invoice
    $invoice2 = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'enrollment_id' => $enrollment->id,
        'title' => 'Tuition Fees Offline Checkout Test',
        'period_month' => 6,
        'period_year' => 2026,
        'due_date' => '2026-06-07',
        'amount' => 800.00,
        'total_amount' => 800.00,
        'paid_amount' => 0.00,
        'balance_amount' => 800.00,
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    Livewire::test('pages::student.payments')
        ->call('openInvoiceCheckout', $invoice2->id)
        ->assertSet('showCheckoutModal', true)
        ->set('selectedGateway', 'offline')
        ->set('offlineMethod', 'static_qr')
        ->set('offlineRefId', 'UPI-UTR-9988776655')
        ->set('offlineDate', now()->toDateString())
        ->call('submitPayment')
        ->assertHasNoErrors()
        ->assertSet('showCheckoutModal', false);

    $txn = PaymentTransaction::where('invoice_id', $invoice2->id)->first();
    expect($txn)->not->toBeNull()
        ->and($txn->status)->toBe(PaymentTransaction::STATUS_UNDER_REVIEW)
        ->and($txn->offline_reference_id)->toBe('UPI-UTR-9988776655');
});

test('student can deposit library security fee multiple times and track all active deposits', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    // Deposit 1: ₹1,000
    $dep1 = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 1000.00,
        'payment_method' => 'cash',
        'payment_reference' => 'LIB-DEP-001',
    ], recordedBy: $student->id);

    // Deposit 2: ₹500
    $dep2 = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 500.00,
        'payment_method' => 'upi',
        'payment_reference' => 'LIB-DEP-002',
    ], recordedBy: $student->id);

    // Deposit 3: ₹1,500
    $dep3 = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 1500.00,
        'payment_method' => 'static_qr',
        'payment_reference' => 'LIB-DEP-003',
    ], recordedBy: $student->id);

    expect($student->libraryDeposits()->count())->toBe(3)
        ->and($student->activeLibraryDeposits()->count())->toBe(3)
        ->and($student->totalActiveLibraryDepositAmount())->toBe(3000.00)
        ->and($student->totalHeldLibraryDepositAmount())->toBe(3000.00);

    // Student dashboard Livewire test with multiple deposits
    Livewire::actingAs($student)
        ->test('pages::student.payments')
        ->assertSee($dep1->deposit_no)
        ->assertSee($dep2->deposit_no)
        ->assertSee($dep3->deposit_no)
        ->assertSee('₹3,000.00');
});

test('student can raise refund request for only one deposit while leaving other deposits active', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $dep1 = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 1000.00,
        'payment_method' => 'cash',
    ], recordedBy: $student->id);

    $dep2 = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 1500.00,
        'payment_method' => 'upi',
    ], recordedBy: $student->id);

    // Request refund for ONLY deposit #1 via Livewire
    Livewire::actingAs($student)
        ->test('pages::student.payments')
        ->call('openRefundModalForSingle', $dep1->id)
        ->assertSet('showLibraryRefundModal', true)
        ->assertSet('selectedDepositIdsForRefund', [$dep1->id])
        ->set('refundReason', 'Course completed, refunding initial deposit.')
        ->set('refundUpiId', 'student@upi')
        ->call('submitLibraryRefund')
        ->assertHasNoErrors()
        ->assertSet('showLibraryRefundModal', false);

    expect($dep1->fresh()->status)->toBe(LibraryDeposit::STATUS_REFUND_REQUESTED)
        ->and($dep2->fresh()->status)->toBe(LibraryDeposit::STATUS_ACTIVE)
        ->and($student->totalActiveLibraryDepositAmount())->toBe(1500.00)
        ->and($student->totalHeldLibraryDepositAmount())->toBe(2500.00);
});

test('student and admin can request, approve, and disburse multiple deposits at once', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $dep1 = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 500.00,
        'payment_method' => 'cash',
    ], recordedBy: $admin->id);

    $dep2 = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 1000.00,
        'payment_method' => 'cash',
    ], recordedBy: $admin->id);

    // Batch refund request for both deposits
    $requested = LibraryDepositService::requestRefundMultiple(
        depositIds: [$dep1->id, $dep2->id],
        requestedBy: $student->id,
        bankDetails: [
            'refund_reason' => 'All study completed.',
            'upi_id' => 'student@okaxis',
        ]
    );

    expect($requested)->toHaveCount(2)
        ->and($dep1->fresh()->status)->toBe(LibraryDeposit::STATUS_REFUND_REQUESTED)
        ->and($dep2->fresh()->status)->toBe(LibraryDeposit::STATUS_REFUND_REQUESTED);

    // Librarian issues clearance certificate for batch
    LibraryClearanceService::issueClearanceCertificate($dep1->fresh(), clearedBy: $admin->id, remarks: 'Zero dues verified');
    LibraryClearanceService::issueClearanceCertificate($dep2->fresh(), clearedBy: $admin->id, remarks: 'Zero dues verified');

    // Admin batch approval
    $approved = LibraryDepositService::approveRefundMultiple(
        depositIds: [$dep1->id, $dep2->id],
        reviewedBy: $admin->id,
        adminNotes: 'Batch approved all clearance deposits'
    );

    expect($approved)->toHaveCount(2)
        ->and($dep1->fresh()->status)->toBe(LibraryDeposit::STATUS_REFUND_APPROVED)
        ->and($dep2->fresh()->status)->toBe(LibraryDeposit::STATUS_REFUND_APPROVED);

    // Admin batch disbursement
    $disbursed = LibraryDepositService::processDisbursementMultiple(
        depositIds: [$dep1->id, $dep2->id],
        processedBy: $admin->id,
        transactionRef: 'BATCH-UTR-20261004-ALL',
        adminNotes: 'NEFT credit successful'
    );

    expect($disbursed)->toHaveCount(2)
        ->and($dep1->fresh()->status)->toBe(LibraryDeposit::STATUS_REFUND_PROCESSED)
        ->and($dep2->fresh()->status)->toBe(LibraryDeposit::STATUS_REFUND_PROCESSED)
        ->and($dep1->fresh()->refund_transaction_ref)->toBe('BATCH-UTR-20261004-ALL')
        ->and($dep2->fresh()->refund_transaction_ref)->toBe('BATCH-UTR-20261004-ALL');

    // Student ledger should reflect debit for both refunds
    $refundDebits = StudentLedger::where('user_id', $student->id)
        ->where('entry_type', StudentLedger::ENTRY_DEBIT)
        ->where('reference_type', StudentLedger::REF_REFUND)
        ->get();

    expect($refundDebits)->toHaveCount(2)
        ->and((float) $refundDebits->sum('amount'))->toBe(1500.00);
});

test('one student can make payment against a month only once in case of tuition fees', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $tuitionType = FeeType::where('code', 'tuition')->first();

    // 1. Record manual collection for March 2026 tuition fee
    $payment1 = FeePaymentService::recordManualCollection([
        'user_id' => $student->id,
        'fee_type_id' => $tuitionType->id,
        'title' => 'Tuition Fees for March 2026',
        'amount' => 1000.00,
        'payment_method' => 'cash',
        'reference_no' => 'COUNTER-MARCH-01',
        'period_month' => 3,
        'period_year' => 2026,
    ], $admin->id);

    expect($payment1)->not->toBeNull()
        ->and($payment1->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL);

    // 2. Attempt to pay tuition fee AGAIN for March 2026 -> should throw ValidationException
    expect(function () use ($student, $tuitionType, $admin) {
        FeePaymentService::recordManualCollection([
            'user_id' => $student->id,
            'fee_type_id' => $tuitionType->id,
            'title' => 'Duplicate Tuition Fees for March 2026',
            'amount' => 1000.00,
            'payment_method' => 'upi',
            'reference_no' => 'COUNTER-MARCH-DUPLICATE',
            'period_month' => 3,
            'period_year' => 2026,
        ], $admin->id);
    })->toThrow(ValidationException::class);

    // 3. Admin Livewire UI should detect already paid tuition fee
    Livewire::actingAs($admin)
        ->test('pages::admin.payments.fees')
        ->call('openDirectCollectionModal')
        ->set('selectedUserId', $student->id)
        ->set('selectedFeeTypeId', $tuitionType->id)
        ->set('periodMonth', 3)
        ->set('periodYear', 2026)
        ->set('collectAmount', 1000)
        ->assertSee('Tuition fee for March 2026 has already been paid for this student')
        ->call('saveCollection')
        ->assertHasErrors(['periodMonth']);
});

test('non-tuition fees can be paid multiple times for the same student without monthly restriction', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $examFeeType = FeeType::where('code', 'examination')->first();

    // First exam payment
    $payment1 = FeePaymentService::recordManualCollection([
        'user_id' => $student->id,
        'fee_type_id' => $examFeeType->id,
        'title' => 'Mock Test Series Fee - Exam 1',
        'amount' => 300.00,
        'payment_method' => 'cash',
        'reference_no' => 'EXAM-001',
        'period_month' => 3,
        'period_year' => 2026,
    ], $admin->id);

    // Second exam payment for same month and student is ALLOWED
    $payment2 = FeePaymentService::recordManualCollection([
        'user_id' => $student->id,
        'fee_type_id' => $examFeeType->id,
        'title' => 'Mock Test Series Fee - Exam 2',
        'amount' => 300.00,
        'payment_method' => 'cash',
        'reference_no' => 'EXAM-002',
        'period_month' => 3,
        'period_year' => 2026,
    ], $admin->id);

    expect($payment1->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL)
        ->and($payment2->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL)
        ->and(PaymentTransaction::where('user_id', $student->id)->where('fee_type_id', $examFeeType->id)->count())->toBe(2);
});

test('receipt and transaction sequence generators prevent duplicate entry collisions even with large jumps or existing keys', function () {
    $year = date('Y');
    $prefix = "RCT-{$year}-";

    // Manually create a transaction with an artificial high receipt number (e.g. 5 digits vs 6 digits)
    $student = User::factory()->create();
    $student->assignRole('Student');

    $existing1 = PaymentTransaction::create([
        'transaction_no' => 'TXN-TEST-RCT-01',
        'user_id' => $student->id,
        'fee_type_id' => 1,
        'amount' => 100,
        'currency' => 'INR',
        'gateway' => 'offline',
        'payment_method' => 'cash',
        'status' => PaymentTransaction::STATUS_SUCCESSFUL,
        'is_offline' => true,
        'receipt_no' => $prefix.'00050',
    ]);

    // Next receipt should be at least 00051
    $nextReceipt1 = PaymentTransaction::generateReceiptNo();
    expect($nextReceipt1)->toBe($prefix.'00051');

    // Create 00051
    $existing2 = PaymentTransaction::create([
        'transaction_no' => 'TXN-TEST-RCT-02',
        'user_id' => $student->id,
        'fee_type_id' => 1,
        'amount' => 100,
        'currency' => 'INR',
        'gateway' => 'offline',
        'payment_method' => 'cash',
        'status' => PaymentTransaction::STATUS_SUCCESSFUL,
        'is_offline' => true,
        'receipt_no' => $nextReceipt1,
    ]);

    // Next receipt should safely increment to 00052 without duplicate collision
    $nextReceipt2 = PaymentTransaction::generateReceiptNo();
    expect($nextReceipt2)->toBe($prefix.'00052');
});

test('online payment completion and direct library deposit collection generate unique receipts and deposits successfully', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');
    $libFeeType = FeeType::where('code', 'library_security')->first();

    // 1. Student initiates online payment
    $onlineTxn = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'user_id' => $student->id,
        'student_id' => $student->student_id,
        'fee_type_id' => $libFeeType->id,
        'amount' => 1000.00,
        'currency' => 'INR',
        'gateway' => 'ccavenue',
        'payment_method' => 'online',
        'status' => PaymentTransaction::STATUS_PENDING,
        'is_offline' => false,
        'gateway_order_id' => 'CC_TEST_ORDER_01',
    ]);

    // Complete online payment
    $completedTxn = FeePaymentService::completeOnlinePayment(
        $onlineTxn,
        gatewayPaymentId: 'PAY_CC_TEST_123',
        signature: 'SIG_CC_TEST_123',
        responsePayload: ['status' => 'success']
    );

    expect($completedTxn->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL)
        ->and($completedTxn->receipt_no)->not->toBeNull()
        ->and(LibraryDeposit::where('payment_transaction_id', $onlineTxn->id)->exists())->toBeTrue();

    // 2. Admin directly collects library security deposit for another or same student
    $adminCollected = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 2000.00,
        'payment_method' => 'bank_transfer',
        'payment_reference' => 'LIB-NEFT-TEST-99',
        'remarks' => 'Direct deposit collected',
    ], $admin->id);

    expect($adminCollected)->toBeInstanceOf(LibraryDeposit::class)
        ->and($adminCollected->status)->toBe(LibraryDeposit::STATUS_ACTIVE)
        ->and($adminCollected->paymentTransaction->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL)
        ->and($adminCollected->paymentTransaction->receipt_no)->not->toBeNull()
        ->and($adminCollected->paymentTransaction->receipt_no)->not->toBe($completedTxn->receipt_no);
});

test('student wise financial ledger component renders and allows admin to inspect single student ledger passbook', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $course = Course::where('code', 'CC')->first();
    $batch = Batch::first();
    $feeType = FeeType::where('code', 'tuition')->first();

    $student = User::factory()->create([
        'name' => 'Sourav Ganguly',
        'student_id' => '202600888',
    ]);
    $student->assignRole('Student');
    StudentProfile::create([
        'user_id' => $student->id,
        'social_category' => 'General',
    ]);
    Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'status' => Enrollment::STATUS_ACTIVE,
        'enrolled_at' => now(),
    ]);

    // Create an invoice: ₹1000
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $student->id,
        'student_id' => $student->student_id,
        'title' => 'Tuition Fee - October 2026',
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'fee_type_id' => $feeType->id,
        'period_month' => 10,
        'period_year' => 2026,
        'amount' => 1000.00,
        'paid_amount' => 400.00,
        'balance_amount' => 600.00,
        'status' => StudentFeeInvoice::STATUS_PARTIAL,
        'due_date' => now()->addDays(15),
    ]);

    // Create student ledger entries
    StudentLedger::create([
        'ledger_no' => StudentLedger::generateLedgerNo(),
        'user_id' => $student->id,
        'student_id' => $student->student_id,
        'entry_type' => StudentLedger::ENTRY_DEBIT,
        'amount' => 1000.00,
        'balance_after' => 1000.00,
        'reference_type' => StudentLedger::REF_INVOICE,
        'reference_id' => $invoice->id,
        'description' => 'Tuition fee demand for October 2026',
        'transaction_date' => now()->subDays(5),
    ]);

    // Create a payment transaction: ₹400
    $payment = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'user_id' => $student->id,
        'student_id' => $student->student_id,
        'fee_type_id' => $feeType->id,
        'invoice_id' => $invoice->id,
        'amount' => 400.00,
        'currency' => 'INR',
        'gateway' => 'cash',
        'payment_method' => 'cash',
        'status' => PaymentTransaction::STATUS_SUCCESSFUL,
        'is_offline' => true,
        'receipt_no' => PaymentTransaction::generateReceiptNo(),
        'paid_at' => now()->subDays(2),
    ]);

    StudentLedger::create([
        'ledger_no' => StudentLedger::generateLedgerNo(),
        'user_id' => $student->id,
        'student_id' => $student->student_id,
        'entry_type' => StudentLedger::ENTRY_CREDIT,
        'amount' => 400.00,
        'balance_after' => 600.00,
        'reference_type' => StudentLedger::REF_PAYMENT,
        'reference_id' => $payment->id,
        'description' => 'Payment received via cash',
        'transaction_date' => now()->subDays(2),
    ]);

    // Test livewire component rendering in single student mode
    $component = Livewire::actingAs($admin)
        ->test('pages::admin.payments.ledger', ['student' => $student->id])
        ->assertOk()
        ->assertSee('Sourav Ganguly')
        ->assertSee('202600888')
        ->assertSee('Tuition fee demand for October 2026')
        ->assertSee('Payment received via cash')
        ->assertSee('1,000.00')
        ->assertSee('400.00')
        ->assertSee('600.00')
        ->assertSet('selectedStudentId', $student->id);

    // Test filtering by entry type: credit only
    $component->set('entryTypeFilter', 'credit')
        ->assertSee('Payment received via cash')
        ->assertDontSee('Tuition fee demand for October 2026');

    // Test filtering by entry type: debit only
    $component->set('entryTypeFilter', 'debit')
        ->assertSee('Tuition fee demand for October 2026')
        ->assertDontSee('Payment received via cash');

    // Test sorting
    $component->set('entryTypeFilter', 'all')
        ->set('sortBy', 'amount')
        ->set('sortDir', 'desc')
        ->assertOk();

    // Test tabs
    $component->set('singleTab', 'invoices')
        ->assertSee($invoice->invoice_no)
        ->set('singleTab', 'payments')
        ->assertSee($payment->transaction_no);
});

test('student wise financial ledger cohort mode filters by course batch section and balance status', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $course = Course::where('code', 'CC')->first();
    $batch = Batch::first();

    $student1 = User::factory()->create(['name' => 'Aniket Roy', 'student_id' => '202600101']);
    $student1->assignRole('Student');
    Enrollment::create([
        'user_id' => $student1->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'status' => Enrollment::STATUS_ACTIVE,
        'enrolled_at' => now(),
    ]);

    $student2 = User::factory()->create(['name' => 'Bikram Das', 'student_id' => '202600102']);
    $student2->assignRole('Student');
    Enrollment::create([
        'user_id' => $student2->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'status' => Enrollment::STATUS_ACTIVE,
        'enrolled_at' => now(),
    ]);

    // Student 1 has dues (debit 1000, credit 0)
    StudentLedger::create([
        'ledger_no' => StudentLedger::generateLedgerNo(),
        'user_id' => $student1->id,
        'student_id' => $student1->student_id,
        'entry_type' => StudentLedger::ENTRY_DEBIT,
        'amount' => 1000.00,
        'balance_after' => 1000.00,
        'reference_type' => StudentLedger::REF_INVOICE,
        'reference_id' => 9991,
        'description' => 'Tuition fee demand',
        'transaction_date' => now(),
    ]);

    // Student 2 is settled (debit 1000, credit 1000)
    StudentLedger::create([
        'ledger_no' => StudentLedger::generateLedgerNo(),
        'user_id' => $student2->id,
        'student_id' => $student2->student_id,
        'entry_type' => StudentLedger::ENTRY_DEBIT,
        'amount' => 1000.00,
        'balance_after' => 1000.00,
        'reference_type' => StudentLedger::REF_INVOICE,
        'reference_id' => 9992,
        'description' => 'Tuition fee demand',
        'transaction_date' => now()->subDay(),
    ]);
    StudentLedger::create([
        'ledger_no' => StudentLedger::generateLedgerNo(),
        'user_id' => $student2->id,
        'student_id' => $student2->student_id,
        'entry_type' => StudentLedger::ENTRY_CREDIT,
        'amount' => 1000.00,
        'balance_after' => 0.00,
        'reference_type' => StudentLedger::REF_PAYMENT,
        'reference_id' => 9993,
        'description' => 'Payment received',
        'transaction_date' => now(),
    ]);

    // Test Cohort Mode
    $component = Livewire::actingAs($admin)
        ->test('pages::admin.payments.ledger')
        ->assertOk()
        ->assertSet('selectedStudentId', null)
        ->set('courseFilter', (string) $course->id)
        ->set('batchFilter', (string) $batch->id)
        ->assertSee('Aniket Roy')
        ->assertSee('Bikram Das');

    // Filter by balance status: has dues
    $component->set('balanceFilter', 'dues')
        ->assertSee('Aniket Roy')
        ->assertDontSee('Bikram Das');

    // Filter by balance status: settled / cleared
    $component->set('balanceFilter', 'cleared')
        ->assertSee('Bikram Das')
        ->assertDontSee('Aniket Roy');

    // Switch cohort view to consolidated ledger
    $component->set('cohortTab', 'ledger')
        ->set('balanceFilter', 'all')
        ->assertSee('Tuition fee demand')
        ->assertSee('Payment received');
});

test('student wise financial ledger exports to excel csv and pdf in single and cohort mode', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create(['name' => 'Debraj Ghosh', 'student_id' => '202600201']);
    $student->assignRole('Student');

    StudentLedger::create([
        'ledger_no' => StudentLedger::generateLedgerNo(),
        'user_id' => $student->id,
        'student_id' => $student->student_id,
        'entry_type' => StudentLedger::ENTRY_DEBIT,
        'amount' => 500.00,
        'balance_after' => 500.00,
        'reference_type' => StudentLedger::REF_INVOICE,
        'reference_id' => 8881,
        'description' => 'Test fee invoice',
        'transaction_date' => now(),
    ]);

    // Single student exports
    $component = Livewire::actingAs($admin)
        ->test('pages::admin.payments.ledger', ['student' => $student->id]);

    $xlsxRes = $component->call('export', 'xlsx');
    expect($xlsxRes)->not->toBeNull();

    $csvRes = $component->call('export', 'csv');
    expect($csvRes)->not->toBeNull();

    $pdfRes = $component->call('export', 'pdf');
    expect($pdfRes)->not->toBeNull();

    // Cohort exports
    $cohortComponent = Livewire::actingAs($admin)
        ->test('pages::admin.payments.ledger');

    $cohortXlsx = $cohortComponent->call('export', 'xlsx');
    expect($cohortXlsx)->not->toBeNull();

    $cohortCsv = $cohortComponent->call('export', 'csv');
    expect($cohortCsv)->not->toBeNull();

    $cohortPdf = $cohortComponent->call('export', 'pdf');
    expect($cohortPdf)->not->toBeNull();
});

test('student can access dedicated library deposit portal and view passbook records and refund tracker', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $dep1 = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 1000.00,
        'payment_method' => 'cash',
        'payment_reference' => 'LIB-PAGE-001',
    ], recordedBy: $student->id);

    $dep2 = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 2000.00,
        'payment_method' => 'online',
        'payment_reference' => 'LIB-PAGE-002',
    ], recordedBy: $student->id);

    // Visit route
    $this->actingAs($student)
        ->get(route('student.library-deposit.index'))
        ->assertOk()
        ->assertSee('Library Security Deposit & Refund Portal');

    // Livewire component test
    Livewire::actingAs($student)
        ->test('pages::student.library-deposit')
        ->assertSee($dep1->deposit_no)
        ->assertSee($dep2->deposit_no)
        ->assertSee('₹3,000.00')
        ->set('activeTab', 'refund_flow')
        ->assertSee('Official 4-Step Security Deposit Refund Flow')
        ->set('activeTab', 'guidelines')
        ->assertSee('Library Security Deposit Policy & FAQs')
        ->set('activeTab', 'records');
});

test('student can submit refund claim for multiple deposits via dedicated library deposit portal', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $dep1 = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 1000.00,
        'payment_method' => 'cash',
    ], recordedBy: $student->id);

    $dep2 = LibraryDepositService::createDirectDeposit([
        'user_id' => $student->id,
        'amount' => 1500.00,
        'payment_method' => 'upi',
    ], recordedBy: $student->id);

    Livewire::actingAs($student)
        ->test('pages::student.library-deposit')
        ->call('toggleSelectDepositForRefund', $dep1->id)
        ->call('toggleSelectDepositForRefund', $dep2->id)
        ->assertSet('selectedDepositIdsForRefund', [$dep1->id, $dep2->id])
        ->call('openRefundModalForSelected')
        ->assertSet('showLibraryRefundModal', true)
        ->set('refundReason', 'Completed UPSC course tenure, requesting full library deposit refund.')
        ->set('refundBankName', 'State Bank of India')
        ->set('refundAccountNo', '123456789012')
        ->set('refundAccountNoConfirmation', '123456789012')
        ->set('refundIfsc', 'SBIN0001234')
        ->set('refundHolderName', $student->name)
        ->call('submitLibraryRefund')
        ->assertHasNoErrors()
        ->assertSet('showLibraryRefundModal', false);

    expect($dep1->fresh()->status)->toBe(LibraryDeposit::STATUS_REFUND_REQUESTED)
        ->and($dep2->fresh()->status)->toBe(LibraryDeposit::STATUS_REFUND_REQUESTED)
        ->and($dep1->fresh()->account_number)->toBe('123456789012');
});

test('student can initiate offline deposit submission via dedicated library deposit portal', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    Livewire::actingAs($student)
        ->test('pages::student.library-deposit')
        ->call('selectTier', 2000.00)
        ->assertSet('depositTier', 2000.00)
        ->call('openDepositCheckout')
        ->assertSet('showCheckoutModal', true)
        ->set('selectedGateway', 'offline')
        ->set('offlineMethod', 'upi')
        ->set('offlineRefId', 'UPI-LIB-TEST-9988')
        ->set('offlineDate', now()->toDateString())
        ->set('offlineRemarks', 'Paid via Google Pay to SNTSSC Library account')
        ->call('submitDepositPayment')
        ->assertHasNoErrors()
        ->assertSet('showCheckoutModal', false);

    $txn = PaymentTransaction::where('offline_reference_id', 'UPI-LIB-TEST-9988')->first();
    expect($txn)->not->toBeNull()
        ->and((float) $txn->amount)->toBe(2000.00)
        ->and($txn->status)->toBe(PaymentTransaction::STATUS_UNDER_REVIEW);
});

test('student library security deposit page renders cleanly with pending transactions and no unknown column errors', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $libFeeType = FeeType::firstOrCreate(
        ['code' => 'library_security'],
        ['name' => 'Library Security Deposit', 'category' => 'library', 'amount' => 1000.00, 'is_active' => true]
    );

    // Create a pending online transaction for this student
    PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'user_id' => $student->id,
        'student_id' => $student->student_id,
        'fee_type_id' => $libFeeType->id,
        'amount' => 1000.00,
        'currency' => 'INR',
        'gateway' => 'ccavenue',
        'payment_method' => 'online',
        'status' => PaymentTransaction::STATUS_PENDING,
        'is_offline' => false,
    ]);

    // Test direct top-level URL redirect to team URL
    $this->actingAs($student)
        ->get('/student/library-deposit')
        ->assertRedirect();

    // Test component renders successfully with pending transactions banner
    $this->actingAs($student)
        ->get(route('student.library-deposit.index'))
        ->assertOk()
        ->assertSee('Library Security Deposit & Refund Portal')
        ->assertSee('Pending Gateway Verification');

    Livewire::actingAs($student)
        ->test('pages::student.library-deposit')
        ->assertSee('Pending Gateway Verification')
        ->assertSee('Verify / Check Status')
        ->assertSee('Cancel Attempt');
});

test('administrator is redirected to admin payment desk and impersonated student can access library deposit portal', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Administrator');
    $team = $superAdmin->currentTeam;

    $this->actingAs($superAdmin)
        ->get(route('student.library-deposit.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('admin.payments.fees', ['current_team' => $team->slug]));

    $student = User::factory()->create();
    $student->assignRole('Student');

    // Impersonating student grants access
    session()->put('impersonator_id', $superAdmin->id);
    $this->actingAs($student)
        ->get(route('student.library-deposit.index'))
        ->assertOk()
        ->assertSee('Library Security Deposit & Refund Portal');
    session()->forget('impersonator_id');
});
