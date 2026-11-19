<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\LibraryDeposit;
use App\Models\PaymentTransaction;
use App\Models\StudentFeeInvoice;
use App\Models\StudentLedger;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Database\Seeders\FeeTypeSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);
    $this->seed(FeeTypeSeeder::class);
    $this->seed(SettingsSeeder::class);
    Storage::fake('public');

    $this->admin = User::factory()->create(['name' => 'Finance Admin User']);
    $this->admin->assignRole('Super Administrator');

    $this->apiKeyService = app(ApiKeyService::class);

    // Full payments key
    $fullKeyData = $this->apiKeyService->generateKey([
        'name' => 'Payments Full Test Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['ping', 'read:payments', 'write:payments', 'download:receipts'],
        'description' => 'Test key for full payments operations',
    ], $this->admin);

    $this->plainKey = $fullKeyData['plainTextKey'];

    // Readonly payments key
    $readKeyData = $this->apiKeyService->generateKey([
        'name' => 'Payments Readonly Test Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
        'scopes' => ['ping', 'read:payments'],
        'description' => 'Test key for readonly payments operations',
    ], $this->admin);

    $this->readOnlyPlainKey = $readKeyData['plainTextKey'];

    // Create a sample student
    $this->studentUser = User::factory()->create([
        'name' => 'Sourav Mukherjee',
        'email' => 'sourav@example.com',
        'student_id' => 'STU-TEST-001',
    ]);
    $this->studentUser->assignRole('Student');

    StudentProfile::create([
        'user_id' => $this->studentUser->id,
        'student_id' => $this->studentUser->student_id,
        'category' => 'General (UR)',
    ]);
});

test('payments API requires api key authentication', function () {
    $response = $this->getJson('/api/v1/payments/dashboard');

    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'API_KEY_MISSING',
            ],
        ]);
});

test('payments API enforces scope authorization on write operations', function () {
    $response = $this->withHeaders([
        'X-API-Key' => $this->readOnlyPlainKey,
    ])->postJson('/api/v1/payments/fee-types', [
        'code' => 'test_fee',
        'name' => 'Test Fee Type',
        'category' => 'miscellaneous',
        'frequency' => 'one_time',
        'default_amount' => 500,
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'INSUFFICIENT_SCOPE',
            ],
        ]);
});

test('payments dashboard and statistics endpoints return valid executive telemetry', function () {
    $tuition = FeeType::where('is_active', true)->first();
    PaymentTransaction::create([
        'transaction_no' => 'TXN-TEST-001',
        'user_id' => $this->studentUser->id,
        'fee_type_id' => $tuition?->id,
        'amount' => 1500.00,
        'currency' => 'INR',
        'gateway' => 'manual',
        'payment_method' => 'cash',
        'status' => PaymentTransaction::STATUS_SUCCESSFUL,
        'is_offline' => true,
        'paid_at' => now(),
    ]);

    $response = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
    ])->getJson('/api/v1/payments/dashboard');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'telemetry' => [
                    'total_collected',
                    'total_invoiced',
                    'total_pending_balance',
                    'total_overdue_amount',
                    'collection_efficiency_percentage',
                    'today_collection',
                    'this_month_collection',
                ],
                'invoices_summary',
                'transactions_summary',
                'payment_method_distribution',
                'monthly_collection_trends' => [
                    '*' => [
                        'month',
                        'collected_amount',
                        'transaction_count',
                    ],
                ],
            ],
        ]);

    expect($response->json('data.monthly_collection_trends'))->not->toBeEmpty();

    $statsResponse = $this->withHeaders([
        'X-API-Key' => $this->plainKey,
    ])->getJson('/api/v1/payments/statistics');

    $statsResponse->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'fee_types',
                'courses',
                'batches',
                'social_category_concession',
                'ledger_totals',
            ],
        ]);
});

test('fee types can be listed created updated and soft deleted with active dependency protection', function () {
    // 1. List
    $listRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->getJson('/api/v1/payments/fee-types');
    $listRes->assertStatus(200)->assertJsonPath('success', true);

    // 2. Create
    $createRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->postJson('/api/v1/payments/fee-types', [
        'code' => 'hostel_caution_deposit',
        'name' => 'Hostel Caution Deposit',
        'category' => 'miscellaneous',
        'frequency' => 'one_time',
        'default_amount' => 2000.00,
        'is_refundable' => true,
        'status' => 'active',
        'description' => 'Refundable caution deposit for hostel boarders',
    ]);

    $createRes->assertStatus(201)
        ->assertJsonPath('data.code', 'hostel_caution_deposit')
        ->assertJsonPath('data.default_amount', 2000);

    $typeId = $createRes->json('data.id');

    // 3. Update
    $updateRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->putJson("/api/v1/payments/fee-types/{$typeId}", [
        'name' => 'Hostel Caution Money',
        'default_amount' => 2500.00,
    ]);

    $updateRes->assertStatus(200)
        ->assertJsonPath('data.name', 'Hostel Caution Money')
        ->assertJsonPath('data.default_amount', 2500);

    // 4. Soft Delete (Unused fee type)
    $deleteRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->deleteJson("/api/v1/payments/fee-types/{$typeId}");
    $deleteRes->assertStatus(200)->assertJsonPath('success', true);

    $this->assertSoftDeleted('fee_types', ['id' => $typeId]);

    // 5. Active Dependency Guard on used fee type
    $tuitionType = FeeType::where('code', 'tuition')->first();
    // Create an invoice with this tuition type
    StudentFeeInvoice::create([
        'invoice_no' => 'INV-TEST-DEP-001',
        'user_id' => $this->studentUser->id,
        'student_id' => $this->studentUser->student_id,
        'fee_type_id' => $tuitionType->id,
        'title' => 'Tuition Fee',
        'amount' => 1000,
        'total_amount' => 1000,
        'paid_amount' => 0,
        'balance_amount' => 1000,
        'due_date' => now()->addDays(7),
        'status' => 'pending',
    ]);

    $blockedDeleteRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->deleteJson("/api/v1/payments/fee-types/{$tuitionType->id}");
    $blockedDeleteRes->assertStatus(409)
        ->assertJsonPath('error.code', 'ACTIVE_DEPENDENCY_PREVENTED');
});

test('fee schedule initiation generates batch invoices with category concession and ledger debits', function () {
    $course = Course::where('code', 'CC')->first() ?? Course::first();
    $batch = Batch::first();
    $feeType = FeeType::where('code', 'tuition')->first();

    // Enroll students: one UR, one SC
    $scStudent = User::factory()->create([
        'name' => 'Priyanka Mondal',
        'student_id' => 'STU-SC-002',
    ]);
    $scStudent->assignRole('Student');
    StudentProfile::create([
        'user_id' => $scStudent->id,
        'student_id' => $scStudent->student_id,
        'category' => 'SC',
    ]);

    Enrollment::create([
        'user_id' => $this->studentUser->id,
        'student_id' => $this->studentUser->student_id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrollment_no' => 'ENR-T1',
        'status' => 'active',
        'fee_status' => 'unpaid',
        'enrolled_at' => now(),
    ]);

    Enrollment::create([
        'user_id' => $scStudent->id,
        'student_id' => $scStudent->student_id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrollment_no' => 'ENR-T2',
        'status' => 'active',
        'fee_status' => 'unpaid',
        'enrolled_at' => now(),
    ]);

    $response = $this->withHeaders(['X-API-Key' => $this->plainKey])->postJson('/api/v1/payments/schedules', [
        'batch_id' => $batch->id,
        'course_id' => $course->id,
        'fee_type_id' => $feeType->id,
        'title' => 'November 2026 Tuition Schedule',
        'due_date' => now()->addDays(10)->toDateString(),
        'period_month' => 11,
        'period_year' => 2026,
        'academic_year' => '2026-2027',
        'grace_period_days' => 5,
        'late_fee_amount' => 50.00,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.title', 'November 2026 Tuition Schedule')
        ->assertJsonPath('data.total_targeted_students', 2);

    $scheduleId = $response->json('data.id');

    // Verify UR student was charged full fee (1000) and SC student was charged reserved fee (500)
    $urInvoice = StudentFeeInvoice::where('fee_schedule_id', $scheduleId)->where('user_id', $this->studentUser->id)->first();
    $scInvoice = StudentFeeInvoice::where('fee_schedule_id', $scheduleId)->where('user_id', $scStudent->id)->first();

    expect($urInvoice)->not->toBeNull()
        ->and((float) $urInvoice->total_amount)->toBe(1000.00)
        ->and($scInvoice)->not->toBeNull()
        ->and((float) $scInvoice->total_amount)->toBe(500.00);

    // Verify ledger debits were created for both students
    expect(StudentLedger::where('user_id', $this->studentUser->id)->where('entry_type', 'debit')->exists())->toBeTrue()
        ->and(StudentLedger::where('user_id', $scStudent->id)->where('entry_type', 'debit')->exists())->toBeTrue();
});

test('manual counter payment collection settles invoice credits ledger and generates receipt', function () {
    $feeType = FeeType::where('code', 'tuition')->first();

    $invoice = StudentFeeInvoice::create([
        'invoice_no' => 'INV-CTR-001',
        'user_id' => $this->studentUser->id,
        'student_id' => $this->studentUser->student_id,
        'fee_type_id' => $feeType->id,
        'title' => 'October Tuition Fee',
        'amount' => 1000.00,
        'total_amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'due_date' => now()->toDateString(),
        'period_month' => 10,
        'period_year' => 2026,
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    StudentLedger::recordDebit(
        userId: $this->studentUser->id,
        amount: 1000.00,
        referenceType: StudentLedger::REF_INVOICE,
        referenceId: $invoice->id,
        description: 'Invoice INV-CTR-001',
        studentId: $this->studentUser->student_id
    );

    $response = $this->withHeaders(['X-API-Key' => $this->plainKey])->postJson('/api/v1/payments/transactions/collect', [
        'student_identifier' => $this->studentUser->student_id,
        'invoice_id' => $invoice->id,
        'amount' => 1000.00,
        'payment_method' => 'cash',
        'remarks' => 'Paid at counter desk',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'successful')
        ->assertJsonPath('data.payment_method', 'cash')
        ->assertJsonStructure(['data' => ['receipt_no', 'transaction_no']]);

    $receiptNo = $response->json('data.receipt_no');
    expect($receiptNo)->not->toBeEmpty();

    // Verify invoice is paid
    $invoice->refresh();
    expect($invoice->status)->toBe(StudentFeeInvoice::STATUS_PAID)
        ->and((float) $invoice->balance_amount)->toBe(0.00)
        ->and((float) $invoice->paid_amount)->toBe(1000.00);

    // Verify credit posted to student ledger
    $credit = StudentLedger::where('user_id', $this->studentUser->id)
        ->where('entry_type', StudentLedger::ENTRY_CREDIT)
        ->latest('id')
        ->first();

    expect($credit)->not->toBeNull()
        ->and((float) $credit->amount)->toBe(1000.00);

    // Verify PDF receipt download
    $pdfRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->get("/api/v1/payments/receipts/{$receiptNo}/download");
    $pdfRes->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

test('offline payment review workflow allows approval or rejection', function () {
    $feeType = FeeType::where('code', 'tuition')->first();

    $invoice = StudentFeeInvoice::create([
        'invoice_no' => 'INV-OFF-001',
        'user_id' => $this->studentUser->id,
        'student_id' => $this->studentUser->student_id,
        'fee_type_id' => $feeType->id,
        'title' => 'December Tuition Fee',
        'amount' => 1000.00,
        'total_amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'due_date' => now()->toDateString(),
        'period_month' => 12,
        'period_year' => 2026,
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    // 1. Student / Agent submits offline payment
    $subRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->postJson('/api/v1/payments/transactions/offline', [
        'student_identifier' => $this->studentUser->student_id,
        'invoice_id' => $invoice->id,
        'amount' => 1000.00,
        'payment_method' => 'upi',
        'offline_reference_id' => 'UPI-UTR-9988776655',
        'offline_payment_date' => now()->toDateString(),
        'user_remarks' => 'Google Pay transfer',
    ]);

    $subRes->assertStatus(201)
        ->assertJsonPath('data.status', 'under_review')
        ->assertJsonPath('data.offline_reference_id', 'UPI-UTR-9988776655');

    $txnId = $subRes->json('data.id');

    // 2. Finance Admin approves offline payment
    $verifyRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->postJson("/api/v1/payments/transactions/{$txnId}/verify", [
        'approved' => true,
        'admin_remarks' => 'Bank statement confirmed receipt of ₹1000',
    ]);

    $verifyRes->assertStatus(200)
        ->assertJsonPath('data.status', 'successful');

    expect($verifyRes->json('data.receipt_no'))->not->toBeEmpty();
});

test('invoice cancellation releases student ledger liability', function () {
    $feeType = FeeType::where('code', 'miscellaneous')->first() ?? FeeType::first();

    $invoice = StudentFeeInvoice::create([
        'invoice_no' => 'INV-CAN-001',
        'user_id' => $this->studentUser->id,
        'student_id' => $this->studentUser->student_id,
        'fee_type_id' => $feeType->id,
        'title' => 'Special Seminar Fee',
        'amount' => 300.00,
        'total_amount' => 300.00,
        'paid_amount' => 0.00,
        'balance_amount' => 300.00,
        'due_date' => now()->toDateString(),
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    StudentLedger::recordDebit(
        userId: $this->studentUser->id,
        amount: 300.00,
        referenceType: StudentLedger::REF_INVOICE,
        referenceId: $invoice->id,
        description: 'Invoice INV-CAN-001',
        studentId: $this->studentUser->student_id
    );

    $cancelRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->postJson("/api/v1/payments/invoices/{$invoice->id}/cancel", [
        'reason' => 'Seminar cancelled by management',
    ]);

    $cancelRes->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled');

    // Verify ledger liability was credited / released
    $adjustmentCredit = StudentLedger::where('user_id', $this->studentUser->id)
        ->where('entry_type', StudentLedger::ENTRY_CREDIT)
        ->where('reference_type', StudentLedger::REF_ADJUSTMENT)
        ->first();

    expect($adjustmentCredit)->not->toBeNull()
        ->and((float) $adjustmentCredit->amount)->toBe(300.00);
});

test('student institutional clearance check returns true when all dues are cleared', function () {
    // Check initial clearance (no pending invoices)
    $res = $this->withHeaders(['X-API-Key' => $this->plainKey])->getJson("/api/v1/payments/students/{$this->studentUser->student_id}/clearance");

    $res->assertStatus(200)
        ->assertJsonPath('data.is_cleared', true)
        ->assertJsonPath('data.total_outstanding_amount', 0);

    // Now introduce an unpaid fee invoice
    $feeType = FeeType::where('code', 'tuition')->first();
    StudentFeeInvoice::create([
        'invoice_no' => 'INV-CLR-001',
        'user_id' => $this->studentUser->id,
        'student_id' => $this->studentUser->student_id,
        'fee_type_id' => $feeType->id,
        'title' => 'Unpaid Tuition Fee',
        'amount' => 1000.00,
        'total_amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'due_date' => now()->subDays(5)->toDateString(),
        'status' => StudentFeeInvoice::STATUS_OVERDUE,
    ]);

    $blockedRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->getJson("/api/v1/payments/students/{$this->studentUser->student_id}/clearance");

    $blockedRes->assertStatus(200)
        ->assertJsonPath('data.is_cleared', false)
        ->assertJsonPath('data.total_outstanding_amount', 1000);
});

test('online checkout order can be initiated for an invoice', function () {
    $feeType = FeeType::where('code', 'tuition')->first();

    $invoice = StudentFeeInvoice::create([
        'invoice_no' => 'INV-ONLINE-001',
        'user_id' => $this->studentUser->id,
        'student_id' => $this->studentUser->student_id,
        'fee_type_id' => $feeType->id,
        'title' => 'Online Checkout Test Fee',
        'amount' => 500.00,
        'total_amount' => 500.00,
        'paid_amount' => 0.00,
        'balance_amount' => 500.00,
        'due_date' => now()->toDateString(),
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    $res = $this->withHeaders(['X-API-Key' => $this->plainKey])->postJson('/api/v1/payments/checkout/order', [
        'invoice_id' => $invoice->id,
        'gateway' => 'razorpay',
    ]);

    $res->assertStatus(200)
        ->assertJsonPath('data.gateway', 'razorpay')
        ->assertJsonPath('data.amount', 500)
        ->assertJsonStructure(['data' => ['transaction_no', 'order']]);
});

test('student self-service feeds return personalized invoices and ledgers', function () {
    $feeType = FeeType::where('code', 'tuition')->first();

    StudentFeeInvoice::create([
        'invoice_no' => 'INV-MY-001',
        'user_id' => $this->studentUser->id,
        'student_id' => $this->studentUser->student_id,
        'fee_type_id' => $feeType->id,
        'title' => 'My Personal Tuition',
        'amount' => 1000.00,
        'total_amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'due_date' => now()->addDays(5)->toDateString(),
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    StudentLedger::recordDebit(
        userId: $this->studentUser->id,
        amount: 1000.00,
        referenceType: StudentLedger::REF_INVOICE,
        referenceId: 1,
        description: 'Invoice INV-MY-001',
        studentId: $this->studentUser->student_id
    );

    // As student authenticated via API Key
    $studentKeyData = $this->apiKeyService->generateKey([
        'name' => 'Student Key',
        'type' => 'student_portal',
        'environment' => 'production',
        'rate_limit_per_minute' => 60,
        'scopes' => ['read:payments', 'write:payments'],
    ], $this->studentUser);

    $invoicesRes = $this->withHeaders(['X-API-Key' => $studentKeyData['plainTextKey']])
        ->getJson('/api/v1/payments/my-invoices');

    $invoicesRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.invoice_no', 'INV-MY-001');

    $ledgerRes = $this->withHeaders(['X-API-Key' => $studentKeyData['plainTextKey']])
        ->getJson('/api/v1/payments/my-ledger');

    $ledgerRes->assertStatus(200)
        ->assertJsonPath('data.summary.total_debits', 1000)
        ->assertJsonPath('data.summary.net_liability_balance', 1000);
});

test('library security deposit refund workflow operates cleanly', function () {
    // 1. Create deposit
    $createRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->postJson('/api/v1/payments/library-deposits', [
        'student_identifier' => $this->studentUser->student_id,
        'amount' => 2000.00,
        'payment_method' => 'cash',
        'remarks' => 'Library deposit on admission',
    ]);

    $createRes->assertStatus(201)
        ->assertJsonPath('data.amount', 2000)
        ->assertJsonPath('data.status', 'active');

    $depositId = $createRes->json('data.id');

    // 2. Request refund
    $refundReqRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->postJson("/api/v1/payments/library-deposits/{$depositId}/request-refund", [
        'bank_name' => 'State Bank of India',
        'account_number' => '123456789012',
        'ifsc_code' => 'SBIN0001234',
        'account_holder_name' => 'Sourav Mukherjee',
        'refund_reason' => 'Completed UPSC course and returned all books',
    ]);

    expect($refundReqRes->status())->toBe(200);
    $refundReqRes->assertJsonPath('data.status', 'refund_requested');

    // Set librarian clearance status to cleared so admin can approve
    LibraryDeposit::where('id', $depositId)->update([
        'clearance_status' => LibraryDeposit::CLEARANCE_CLEARED,
    ]);

    // 3. Approve refund
    $approveRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->postJson("/api/v1/payments/library-deposits/{$depositId}/approve-refund", [
        'approved_amount' => 2000.00,
        'admin_notes' => 'Clearance confirmed by head librarian',
    ]);

    $approveRes->assertStatus(200)
        ->assertJsonPath('data.status', 'refund_approved');

    // 4. Disburse refund
    $disburseRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->postJson("/api/v1/payments/library-deposits/{$depositId}/disburse", [
        'transaction_ref' => 'NEFT-SBIN-2026-999888',
        'admin_notes' => 'Disbursed via direct bank transfer',
    ]);

    $disburseRes->assertStatus(200)
        ->assertJsonPath('data.status', 'refund_processed');
});

test('top level fees routes alias parity with payments routes', function () {
    $res = $this->withHeaders(['X-API-Key' => $this->plainKey])->getJson('/api/v1/fees/dashboard');
    $res->assertStatus(200)->assertJsonPath('success', true);

    $typesRes = $this->withHeaders(['X-API-Key' => $this->plainKey])->getJson('/api/v1/fees/types');
    $typesRes->assertStatus(200)->assertJsonPath('success', true);
});
