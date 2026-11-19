<?php

use App\Models\FeeType;
use App\Models\PaymentTransaction;
use App\Models\Setting;
use App\Models\StudentFeeInvoice;
use App\Models\StudentLedger;
use App\Models\User;
use App\Services\Payment\FeePaymentService;
use App\Services\Payment\Gateways\CCAVenueGateway;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Database\Seeders\FeeTypeSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);
    $this->seed(FeeTypeSeeder::class);
    $this->seed(SettingsSeeder::class);
    Storage::fake('public');

    // Configure test CCAvenue credentials
    Setting::set('payment.ccavenue_enabled', true);
    Setting::set('payment.ccavenue_merchant_id', '123456');
    Setting::set('payment.ccavenue_access_code', 'AVTESTACCESSCODE');
    Setting::set('payment.ccavenue_working_key', '1234567890ABCDEF1234567890ABCDEF');
    Setting::set('payment.ccavenue_mode', 'sandbox');
    Setting::set('payment.ccavenue_billing_page_enabled', false);
    Setting::set('payment.ccavenue_default_billing_address', 'Salt Lake, Sector V');
    Setting::set('payment.ccavenue_default_billing_zip', '700106');
    Setting::set('payment.ccavenue_default_billing_city', 'Kolkata');
    Setting::set('payment.ccavenue_default_billing_state', 'West Bengal');
    Setting::set('payment.ccavenue_default_billing_country', 'India');
    Setting::set('payment.ccavenue_default_billing_tel', '9876543210');
    Setting::set('payment.ccavenue_default_billing_email', 'accounts@sntcssc.in');
});

test('ccavenue encryption and decryption works with AES-128-CBC and PKCS5 padding', function () {
    $gateway = new CCAVenueGateway;
    $workingKey = '1234567890ABCDEF1234567890ABCDEF';
    $plainText = 'tid=123456789&merchant_id=123456&order_id=TXN-100&amount=1000.00&currency=INR';

    $encrypted = $gateway->encrypt($plainText, $workingKey);
    expect($encrypted)->toBeString()->not->toBeEmpty();

    $decrypted = $gateway->decrypt($encrypted, $workingKey);
    expect($decrypted)->toBe($plainText);
});

test('ccavenue createOrder enforces mandatory billing info and fallbacks to protect against 6-month penalty rule', function () {
    $user = User::factory()->create([
        'name' => 'Nilanjan Student',
        'email' => 'nilanjan@example.com',
        'phone' => '9830098300',
    ]);
    $user->assignRole('Student');

    // Create a transaction without student address
    $txn = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'user_id' => $user->id,
        'student_id' => 'STU-TEST-001',
        'amount' => 1000.00,
        'currency' => 'INR',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'payment_method' => 'online',
        'status' => PaymentTransaction::STATUS_PENDING,
        'is_offline' => false,
        'created_by' => $user->id,
    ]);

    $gateway = new CCAVenueGateway;
    $orderData = $gateway->createOrder($txn, [
        'redirect_url' => route('payments.ccavenue.response'),
        'cancel_url' => route('payments.ccavenue.cancel'),
    ]);

    expect($orderData)->toHaveKeys(['gateway', 'action_url', 'enc_request', 'access_code', 'order_id']);

    // Decrypt the enc_request to inspect the parameters sent to CCAvenue
    $decrypted = $gateway->decrypt($orderData['enc_request'], '1234567890ABCDEF1234567890ABCDEF');
    parse_str($decrypted, $params);

    // Assert CCAvenue mandatory billing parameters are ALWAYS present
    expect($params['billing_country'])->toBe('India')
        ->and($params['billing_tel'])->toBe('9830098300')
        ->and($params['billing_email'])->toBe('nilanjan@example.com')
        ->and($params['billing_city'])->toBe('Kolkata')
        ->and($params['billing_state'])->toBe('West Bengal')
        ->and($params['billing_zip'])->toBe('700106')
        ->and($params['billing_address'])->toBe('Salt Lake, Sector V')
        ->and($params['merchant_param1'])->toBe($txn->transaction_no)
        ->and($params['merchant_param4'])->toBe((string) $txn->id);
});

test('ccavenue handles gateway fees and surcharge differences without failing valid payments', function () {
    $user = User::factory()->create();
    $user->assignRole('Student');

    $invoice = StudentFeeInvoice::create([
        'invoice_no' => 'INV-TEST-SURCHARGE',
        'user_id' => $user->id,
        'student_id' => 'STU-001',
        'fee_type_id' => FeeType::where('code', 'tuition')->value('id'),
        'title' => 'November Tuition Fee',
        'amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'due_date' => now()->addDays(7),
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    $txn = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'user_id' => $user->id,
        'student_id' => 'STU-001',
        'invoice_id' => $invoice->id,
        'fee_type_id' => $invoice->fee_type_id,
        'amount' => 1000.00,
        'currency' => 'INR',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'payment_method' => 'online',
        'status' => PaymentTransaction::STATUS_PENDING,
        'is_offline' => false,
        'created_by' => $user->id,
    ]);

    // Student paid using credit card: requested ₹1000.00, gateway charged ₹1023.60 (₹20 fee + ₹3.60 tax)
    FeePaymentService::completeOnlinePaymentWithCharges($txn, [
        'order_status' => 'Success',
        'tracking_id' => 'CCA-TRACK-999',
        'bank_ref_no' => 'HDFC-REF-888',
        'amount' => 1023.60,
        'mer_amount' => 1000.00,
        'trans_fee' => 20.00,
        'service_tax' => 3.60,
        'payment_mode' => 'Credit Card',
        'card_name' => 'Visa',
        'billing_country' => 'India',
        'billing_tel' => '9876543210',
        'billing_email' => 'student@example.com',
    ]);

    $txn->refresh();
    $invoice->refresh();

    // The transaction should be successful
    expect($txn->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL)
        ->and((float) $txn->amount)->toBe(1000.00) // Base institutional fee
        ->and((float) $txn->gateway_charged_amount)->toBe(1023.60) // Actual customer debit
        ->and((float) $txn->gateway_fee)->toBe(23.60) // Gateway surcharge
        ->and($txn->gateway_bank_ref_no)->toBe('HDFC-REF-888')
        ->and($txn->gateway_tracking_id)->toBe('CCA-TRACK-999')
        ->and($txn->receipt_no)->not->toBeNull();

    // The invoice should be fully paid (1000 credited, balance 0)
    expect($invoice->status)->toBe(StudentFeeInvoice::STATUS_PAID)
        ->and((float) $invoice->paid_amount)->toBe(1000.00)
        ->and((float) $invoice->balance_amount)->toBe(0.00);

    // Ledger should have credit entry of 1000.00 (the institutional fee)
    $ledger = StudentLedger::where('user_id', $user->id)
        ->where('reference_type', 'payment')
        ->where('entry_type', 'credit')
        ->first();

    expect($ledger)->not->toBeNull()
        ->and((float) $ledger->amount)->toBe(1000.00);
});

test('ccavenue flags amount mismatch when gateway charged amount is less than demanded', function () {
    $user = User::factory()->create();
    $user->assignRole('Student');

    $invoice = StudentFeeInvoice::create([
        'invoice_no' => 'INV-TEST-UNDERPAY',
        'user_id' => $user->id,
        'student_id' => 'STU-001',
        'fee_type_id' => FeeType::where('code', 'tuition')->value('id'),
        'title' => 'November Tuition Fee',
        'amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'due_date' => now()->addDays(7),
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    $txn = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'user_id' => $user->id,
        'student_id' => 'STU-001',
        'invoice_id' => $invoice->id,
        'fee_type_id' => $invoice->fee_type_id,
        'amount' => 1000.00,
        'currency' => 'INR',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'payment_method' => 'online',
        'status' => PaymentTransaction::STATUS_PENDING,
        'is_offline' => false,
        'created_by' => $user->id,
    ]);

    // Student paid only 500 instead of 1000
    FeePaymentService::handleAmountMismatch($txn, [
        'order_status' => 'Success',
        'tracking_id' => 'CCA-TRACK-LESS',
        'bank_ref_no' => 'BANK-LESS',
        'amount' => 500.00,
        'mer_amount' => 500.00,
        'payment_mode' => 'UPI',
    ], 'Charged amount (₹500.00) is less than demanded invoice amount (₹1000.00). Flagged for administrative audit.');

    $txn->refresh();
    $invoice->refresh();

    expect($txn->status)->toBe(PaymentTransaction::STATUS_UNDER_REVIEW)
        ->and($txn->amount_mismatch)->toBeTrue()
        ->and((float) $txn->gateway_charged_amount)->toBe(500.00)
        ->and($txn->amount_mismatch_notes)->toContain('Charged amount (₹500.00) is less than demanded');

    // Invoice remains pending
    expect($invoice->status)->toBe(StudentFeeInvoice::STATUS_PENDING)
        ->and((float) $invoice->paid_amount)->toBe(0.00);
});

test('admin can reconcile and manually update unconfirmed payment status with ledger settlement', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $invoice = StudentFeeInvoice::create([
        'invoice_no' => 'INV-TEST-MANUAL',
        'user_id' => $student->id,
        'student_id' => 'STU-001',
        'fee_type_id' => FeeType::where('code', 'tuition')->value('id'),
        'title' => 'Tuition Fee',
        'amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'due_date' => now()->addDays(7),
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    // Record initial invoice debit in ledger
    StudentLedger::create([
        'ledger_no' => StudentLedger::generateLedgerNo(),
        'user_id' => $student->id,
        'student_id' => 'STU-001',
        'entry_type' => 'debit',
        'amount' => 1000.00,
        'balance_after' => 1000.00,
        'reference_type' => 'fee_invoice',
        'reference_id' => $invoice->id,
        'description' => 'Tuition Fee Debit',
        'transaction_date' => now(),
    ]);

    $txn = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'user_id' => $student->id,
        'student_id' => 'STU-001',
        'invoice_id' => $invoice->id,
        'fee_type_id' => $invoice->fee_type_id,
        'amount' => 1000.00,
        'currency' => 'INR',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'payment_method' => 'online',
        'status' => PaymentTransaction::STATUS_PENDING,
        'is_offline' => false,
        'created_by' => $student->id,
    ]);

    // Admin verifies payment on CCAvenue Merchant dashboard and manually marks it successful
    FeePaymentService::updatePaymentStatus($txn, PaymentTransaction::STATUS_SUCCESSFUL, [
        'gateway_tracking_id' => 'MANUAL-TRACK-12345',
        'gateway_bank_ref_no' => 'MANUAL-BANK-REF',
        'payment_method' => 'netbanking',
        'admin_remarks' => 'Verified on CCAvenue Merchant Portal. Captured by bank.',
    ], $admin->id);

    $txn->refresh();
    $invoice->refresh();

    expect($txn->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL)
        ->and($txn->gateway_tracking_id)->toBe('MANUAL-TRACK-12345')
        ->and($txn->gateway_bank_ref_no)->toBe('MANUAL-BANK-REF')
        ->and($txn->receipt_no)->not->toBeNull();

    expect($invoice->status)->toBe(StudentFeeInvoice::STATUS_PAID)
        ->and((float) $invoice->balance_amount)->toBe(0.00);

    // Ledger should now be credited
    $credit = StudentLedger::where('user_id', $student->id)
        ->where('entry_type', 'credit')
        ->first();
    expect($credit)->not->toBeNull()
        ->and((float) $credit->amount)->toBe(1000.00);
});

test('admin can refund an unintentional duplicate payment with ledger debit adjustment', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $invoice = StudentFeeInvoice::create([
        'invoice_no' => 'INV-TEST-REFUND',
        'user_id' => $student->id,
        'student_id' => 'STU-001',
        'fee_type_id' => FeeType::where('code', 'tuition')->value('id'),
        'title' => 'Tuition Fee Duplicate',
        'amount' => 1000.00,
        'total_amount' => 1000.00,
        'paid_amount' => 1000.00,
        'balance_amount' => 0.00,
        'due_date' => now()->addDays(7),
        'status' => StudentFeeInvoice::STATUS_PAID,
    ]);

    // Transaction that was cleared
    $txn = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'user_id' => $student->id,
        'student_id' => 'STU-001',
        'invoice_id' => $invoice->id,
        'fee_type_id' => $invoice->fee_type_id,
        'amount' => 1000.00,
        'gateway_charged_amount' => 1000.00,
        'gateway_tracking_id' => 'CCA-DUP-001',
        'receipt_no' => 'REC-DUP-001',
        'currency' => 'INR',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'payment_method' => 'online',
        'status' => PaymentTransaction::STATUS_SUCCESSFUL,
        'is_offline' => false,
        'created_by' => $student->id,
    ]);

    expect($txn->canBeRefunded())->toBeTrue()
        ->and($txn->getRefundableAmount())->toBe(1000.00);

    // Mock CCAvenue refund API endpoint
    Http::fake([
        '*ccavenue.com*' => Http::response(
            'status=0&enc_response='.(new CCAVenueGateway)->encrypt(
                json_encode([
                    'refund_status' => '0',
                    'reason' => 'Success',
                    'Refund_Order_Result' => [
                        'refund_ref_no' => 'CCA-REF-998877',
                    ],
                ]),
                '1234567890ABCDEF1234567890ABCDEF'
            ),
            200
        ),
    ]);

    FeePaymentService::refundPayment(
        transaction: $txn,
        amount: 1000.00,
        reason: 'Student paid twice by mistake. Refunded to card.',
        refundRefNo: 'REF-TEST-001',
        refundedBy: $admin->id,
        attemptGatewayApi: true
    );

    $txn->refresh();
    $invoice->refresh();

    expect($txn->status)->toBe(PaymentTransaction::STATUS_REFUNDED)
        ->and((float) $txn->refund_amount)->toBe(1000.00)
        ->and($txn->refund_reason)->toBe('Student paid twice by mistake. Refunded to card.')
        ->and($txn->refund_status)->toBe('processed')
        ->and($txn->refunded_by)->toBe($admin->id)
        ->and($txn->canBeRefunded())->toBeFalse();

    // Check student ledger: debit entry recorded for refund adjustment
    $refundLedger = StudentLedger::where('user_id', $student->id)
        ->where('reference_type', 'refund')
        ->first();

    expect($refundLedger)->not->toBeNull()
        ->and($refundLedger->entry_type)->toBe('debit')
        ->and((float) $refundLedger->amount)->toBe(1000.00);

    // Check invoice balance reopened
    expect((float) $invoice->balance_amount)->toBe(1000.00)
        ->and((float) $invoice->paid_amount)->toBe(0.00)
        ->and($invoice->status)->toBe(StudentFeeInvoice::STATUS_PENDING);
});

test('ccavenue response controller processes successful encrypted gateway callback', function () {
    $student = User::factory()->create([
        'name' => 'Sourav Ganguly',
        'email' => 'sourav@example.com',
    ]);
    $student->assignRole('Student');

    $invoice = StudentFeeInvoice::create([
        'invoice_no' => 'INV-TEST-CALLBACK',
        'user_id' => $student->id,
        'student_id' => 'STU-001',
        'fee_type_id' => FeeType::where('code', 'tuition')->value('id'),
        'title' => 'Tuition Fee Callback',
        'amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'due_date' => now()->addDays(7),
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    $txn = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'user_id' => $student->id,
        'student_id' => 'STU-001',
        'invoice_id' => $invoice->id,
        'fee_type_id' => $invoice->fee_type_id,
        'amount' => 1000.00,
        'currency' => 'INR',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'payment_method' => 'online',
        'status' => PaymentTransaction::STATUS_PENDING,
        'is_offline' => false,
        'created_by' => $student->id,
    ]);

    // Build CCAvenue encrypted callback response string
    $responsePayload = http_build_query([
        'order_id' => $txn->transaction_no,
        'tracking_id' => 'CCA-TRK-777666',
        'bank_ref_no' => 'HDFC-PAY-444',
        'order_status' => 'Success',
        'failure_message' => '',
        'payment_mode' => 'Debit Card',
        'card_name' => 'MasterCard',
        'status_code' => '',
        'status_message' => 'Transaction Successful',
        'currency' => 'INR',
        'amount' => '1015.00', // ₹15 surcharge
        'mer_amount' => '1000.00',
        'trans_fee' => '12.00',
        'service_tax' => '3.00',
        'merchant_param1' => (string) $txn->id,
        'billing_country' => 'India',
        'billing_tel' => '9830098300',
        'billing_email' => 'sourav@example.com',
    ]);

    $gateway = new CCAVenueGateway;
    $encResp = $gateway->encrypt($responsePayload, '1234567890ABCDEF1234567890ABCDEF');

    // POST to callback route
    $this->actingAs($student);
    $response = $this->post(route('payments.ccavenue.response'), [
        'encResp' => $encResp,
    ]);

    $response->assertRedirect();

    $txn->refresh();
    expect($txn->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL)
        ->and((float) $txn->amount)->toBe(1000.00)
        ->and((float) $txn->gateway_charged_amount)->toBe(1015.00)
        ->and((float) $txn->gateway_fee)->toBe(15.00)
        ->and($txn->gateway_tracking_id)->toBe('CCA-TRK-777666')
        ->and($txn->receipt_no)->not->toBeNull();
});

test('duplicate payment prevention stops user from initiating second payment on pending or paid invoices', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $invoice = StudentFeeInvoice::create([
        'invoice_no' => 'INV-TEST-DUP-PREVENT',
        'user_id' => $student->id,
        'student_id' => 'STU-001',
        'fee_type_id' => FeeType::where('code', 'tuition')->value('id'),
        'title' => 'Tuition Fee Prevention',
        'amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'due_date' => now()->addDays(7),
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    // 1st attempt: active pending transaction created 5 minutes ago
    $pendingTxn = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'user_id' => $student->id,
        'student_id' => 'STU-001',
        'invoice_id' => $invoice->id,
        'fee_type_id' => $invoice->fee_type_id,
        'amount' => 1000.00,
        'currency' => 'INR',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'payment_method' => 'online',
        'status' => PaymentTransaction::STATUS_PENDING,
        'is_offline' => false,
        'created_by' => $student->id,
    ]);
    $pendingTxn->created_at = now()->subMinutes(5);
    $pendingTxn->saveQuietly();

    // Test Livewire component checkout method checks for active pending transaction
    $component = Livewire::actingAs($student)
        ->test('pages::student.payments')
        ->call('openInvoiceCheckout', $invoice->id);

    // Component should NOT open checkout modal and should warn user
    $component->assertDispatched('toast');
    expect($component->get('showCheckoutModal'))->toBeFalse();

    $totalTxns = PaymentTransaction::where('invoice_id', $invoice->id)->count();
    expect($totalTxns)->toBe(1);
});

test('payments:sync-ccavenue-pending artisan command checks and reconciles dropped transactions', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $invoice = StudentFeeInvoice::create([
        'invoice_no' => 'INV-TEST-SYNC',
        'user_id' => $student->id,
        'student_id' => 'STU-001',
        'fee_type_id' => FeeType::where('code', 'tuition')->value('id'),
        'title' => 'Tuition Fee Dropped',
        'amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'due_date' => now()->addDays(7),
        'status' => StudentFeeInvoice::STATUS_PENDING,
    ]);

    // A transaction initiated 30 minutes ago, dropped before callback
    $txn = PaymentTransaction::create([
        'transaction_no' => PaymentTransaction::generateTransactionNo(),
        'user_id' => $student->id,
        'student_id' => 'STU-001',
        'invoice_id' => $invoice->id,
        'fee_type_id' => $invoice->fee_type_id,
        'amount' => 1000.00,
        'currency' => 'INR',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'payment_method' => 'online',
        'status' => PaymentTransaction::STATUS_PENDING,
        'is_offline' => false,
        'created_by' => $student->id,
    ]);
    $txn->created_at = now()->subMinutes(30);
    $txn->saveQuietly();

    // Mock CCAvenue Order Status Tracker API returning Successful
    Http::fake([
        '*ccavenue.com*' => Http::response(
            'status=0&enc_response='.(new CCAVenueGateway)->encrypt(
                json_encode([
                    'status' => '0',
                    'order_status' => 'Shipped', // CCAvenue uses Shipped/Successful
                    'order_bank_ref_no' => 'AUTO-SYNC-REF-123',
                    'order_currncy' => 'INR',
                    'order_amt' => '1000.00',
                    'order_fee_perc_value' => '0.00',
                ]),
                '1234567890ABCDEF1234567890ABCDEF'
            ),
            200
        ),
    ]);

    // Run the scheduled sync command
    $exitCode = Artisan::call('payments:sync-ccavenue-pending', ['--hours' => 2]);
    expect($exitCode)->toBe(0);

    $txn->refresh();
    $invoice->refresh();

    expect($txn->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL)
        ->and($txn->gateway_bank_ref_no)->toBe('AUTO-SYNC-REF-123')
        ->and($invoice->status)->toBe(StudentFeeInvoice::STATUS_PAID);
});
