<?php

use App\Models\FeeType;
use App\Models\PaymentTransaction;
use App\Models\Setting;
use App\Models\StudentFeeInvoice;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\Payment\Gateways\CCAVenueGateway;
use App\Services\RbacService;
use Database\Seeders\AcademicSeeder;
use Database\Seeders\FeeTypeSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->seed(AcademicSeeder::class);
    $this->seed(FeeTypeSeeder::class);
    $this->seed(SettingsSeeder::class);

    // Configure test merchant keys
    Setting::set('payment.ccavenue_merchant_id', 'TEST_MERCHANT_999');
    Setting::set('payment.ccavenue_access_code', 'TEST_ACCESS_CODE_999');
    Setting::set('payment.ccavenue_working_key', '4886C852179CD45A4543C124239854DB');
    Setting::set('payment.ccavenue_mode', 'test');
    Setting::set('payment.ccavenue_billing_page_enabled', false);

    $this->admin = User::factory()->create(['name' => 'Finance Director']);
    $this->admin->assignRole('Super Administrator');

    $this->apiKeyService = app(ApiKeyService::class);
    $keyData = $this->apiKeyService->generateKey([
        'name' => 'CCAVenue Omnichannel API Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['ping', 'read:payments', 'write:payments', 'download:receipts'],
        'description' => 'Test key for CCAvenue API tests',
    ], $this->admin);

    $this->apiKey = $keyData['plainTextKey'];

    $this->student = User::factory()->create([
        'name' => 'Debraj Ghosh',
        'email' => 'debraj@example.com',
        'phone' => '9876543210',
    ]);
    $this->student->assignRole('Student');

    $this->feeType = FeeType::first() ?? FeeType::create([
        'name' => 'WBCS Foundation Fee',
        'code' => 'WBCS-FEE',
        'amount' => 1500.00,
        'frequency' => 'one_time',
        'category' => 'academic',
        'is_active' => true,
    ]);
});

test('can retrieve ccavenue public config and billing policies via api', function () {
    $response = $this->withHeader('X-API-Key', $this->apiKey)
        ->getJson('/api/v1/payments/gateways/ccavenue/config');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'gateway' => 'ccavenue',
                'merchant_id' => 'TEST_MERCHANT_999',
                'mode' => 'test',
                'currency' => 'INR',
                'billing_page_step_enabled' => false,
            ],
        ]);

    expect($response->json('data.fallback_billing_defaults.city'))->toBe('Kolkata');
    expect($response->json('data.fallback_billing_defaults.zip_code'))->toBe('700106');
    expect($response->json('data.fallback_billing_defaults.state'))->toBe('West Bengal');
});

test('can test ccavenue aes encryption handshake via api', function () {
    $response = $this->withHeader('X-API-Key', $this->apiKey)
        ->postJson('/api/v1/payments/gateways/ccavenue/test-encryption', [
            'plaintext' => 'order_id=TEST_HANDSHAKE_01&amount=500.00&currency=INR',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'encryption_healthy' => true,
                'cipher_algorithm' => 'AES-128-CBC',
            ],
        ]);
});

test('can initiate ccavenue checkout for student invoice via api', function () {
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $this->student->id,
        'student_id' => $this->student->id,
        'fee_type_id' => $this->feeType->id,
        'title' => 'Term 1 WBCS Tuition Fee',
        'total_amount' => 2000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 2000.00,
        'status' => StudentFeeInvoice::STATUS_PENDING,
        'issue_date' => now(),
        'due_date' => now()->addDays(15),
    ]);

    $response = $this->withHeader('X-API-Key', $this->apiKey)
        ->postJson('/api/v1/payments/ccavenue/initiate', [
            'invoice_id' => $invoice->id,
            'amount' => 2000.00,
            'redirect_url' => 'https://app.sntcssc.in/payments/callback',
            'cancel_url' => 'https://app.sntcssc.in/payments/cancel',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'amount' => 2000.0,
                'currency' => 'INR',
                'gateway' => 'ccavenue',
                'access_code' => 'TEST_ACCESS_CODE_999',
            ],
        ]);

    $data = $response->json('data');
    expect($data['enc_request'])->not->toBeEmpty();
    expect($data['order_id'])->toContain('CC_TXN_');
    expect($data['billing_data']['billing_country'])->toBe('India');
});

test('can process successful ccavenue callback via api with surcharge recording', function () {
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $this->student->id,
        'student_id' => $this->student->id,
        'fee_type_id' => $this->feeType->id,
        'title' => 'Library Deposit Fee',
        'total_amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'status' => StudentFeeInvoice::STATUS_PENDING,
        'issue_date' => now(),
        'due_date' => now()->addDays(15),
    ]);

    $transaction = PaymentTransaction::create([
        'user_id' => $this->student->id,
        'invoice_id' => $invoice->id,
        'fee_type_id' => $this->feeType->id,
        'payment_category' => 'academic',
        'amount' => 1000.00,
        'currency' => 'INR',
        'payment_method' => 'online',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'status' => PaymentTransaction::STATUS_PENDING,
        'transaction_no' => 'TXN-API-CC-TEST-001',
    ]);

    $gateway = new CCAVenueGateway;
    $rawResponse = "order_id={$transaction->transaction_no}&tracking_id=CC_TRACK_999888&bank_ref_no=HDFC_776655&order_status=Success&failure_message=&payment_mode=Credit Card&card_name=Visa&status_code=null&status_message=Transaction Successful&currency=INR&amount=1023.60&trans_fee=23.60&merchant_param1={$transaction->transaction_no}&merchant_param4={$transaction->id}";

    $encryptedResponse = $gateway->encrypt($rawResponse);

    $response = $this->postJson('/api/v1/payments/ccavenue/callback', [
        'encResp' => $encryptedResponse,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'successful',
                'gateway_charged_amount' => 1023.6,
                'gateway_fee' => 23.6,
                'gateway_tracking_id' => 'CC_TRACK_999888',
                'gateway_bank_ref_no' => 'HDFC_776655',
            ],
        ]);

    $transaction->refresh();
    expect($transaction->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL);
    expect((float) $transaction->amount)->toBe(1000.00);
    expect((float) $transaction->gateway_charged_amount)->toBe(1023.60);
    expect((float) $transaction->gateway_fee)->toBe(23.60);

    $invoice->refresh();
    expect($invoice->status)->toBe(StudentFeeInvoice::STATUS_PAID);
    expect((float) $invoice->paid_amount)->toBe(1000.00);
});

test('detects amount mismatch on underpayment callback via api', function () {
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $this->student->id,
        'student_id' => $this->student->id,
        'fee_type_id' => $this->feeType->id,
        'title' => 'Underpayment Test Fee',
        'total_amount' => 1000.00,
        'paid_amount' => 0.00,
        'balance_amount' => 1000.00,
        'status' => StudentFeeInvoice::STATUS_PENDING,
        'issue_date' => now(),
        'due_date' => now()->addDays(15),
    ]);

    $transaction = PaymentTransaction::create([
        'user_id' => $this->student->id,
        'invoice_id' => $invoice->id,
        'fee_type_id' => $this->feeType->id,
        'payment_category' => 'academic',
        'amount' => 1000.00,
        'currency' => 'INR',
        'payment_method' => 'online',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'status' => PaymentTransaction::STATUS_PENDING,
        'transaction_no' => 'TXN-API-CC-UNDER-001',
    ]);

    $gateway = new CCAVenueGateway;
    $rawResponse = "order_id={$transaction->transaction_no}&tracking_id=CC_MISMATCH_11&order_status=Success&amount=800.00&merchant_param1={$transaction->transaction_no}&merchant_param4={$transaction->id}";
    $encryptedResponse = $gateway->encrypt($rawResponse);

    $response = $this->postJson('/api/v1/payments/ccavenue/callback', [
        'encResp' => $encryptedResponse,
    ]);

    $response->assertStatus(200);

    $transaction->refresh();
    expect($transaction->status)->toBe(PaymentTransaction::STATUS_UNDER_REVIEW);
    expect($transaction->amount_mismatch)->toBeTrue();
    expect($transaction->amount_mismatch_notes)->toContain('Underpayment received');
});

test('handles aborted or cancelled payment callback via api', function () {
    $transaction = PaymentTransaction::create([
        'user_id' => $this->student->id,
        'amount' => 1200.00,
        'currency' => 'INR',
        'payment_method' => 'online',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'status' => PaymentTransaction::STATUS_PENDING,
        'transaction_no' => 'TXN-API-ABORT-001',
    ]);

    $gateway = new CCAVenueGateway;
    $rawResponse = "order_id={$transaction->transaction_no}&tracking_id=CC_ABORT_1&order_status=Aborted&status_message=User cancelled transaction on bank page&merchant_param1={$transaction->transaction_no}&merchant_param4={$transaction->id}";
    $encryptedResponse = $gateway->encrypt($rawResponse);

    $response = $this->postJson('/api/v1/payments/ccavenue/callback', [
        'encResp' => $encryptedResponse,
    ]);

    $response->assertStatus(400);

    $transaction->refresh();
    expect($transaction->status)->toBe(PaymentTransaction::STATUS_FAILED);
});

test('can sync transaction status with ccavenue tracker via api', function () {
    $gateway = new CCAVenueGateway;
    $statusPayload = json_encode([
        'order_status' => 'Shipped',
        'order_status_result' => [
            'order_no' => 'TXN-API-SYNC-001',
            'order_status' => 'Shipped',
            'order_amt' => '1500.00',
            'order_bank_ref_no' => 'SBI_999888',
            'order_date_time' => '2026-11-09 20:15:00',
        ],
    ]);
    $encResponse = $gateway->encrypt($statusPayload);

    Http::fake([
        '*ccavenue.com*' => Http::response("status=0&enc_response={$encResponse}", 200),
    ]);

    $transaction = PaymentTransaction::create([
        'user_id' => $this->student->id,
        'amount' => 1500.00,
        'currency' => 'INR',
        'payment_method' => 'online',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'status' => PaymentTransaction::STATUS_PENDING,
        'transaction_no' => 'TXN-API-SYNC-001',
    ]);

    $response = $this->withHeader('X-API-Key', $this->apiKey)
        ->postJson('/api/v1/payments/ccavenue/sync-status', [
            'transaction_identifier' => $transaction->transaction_no,
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'sync_result' => [
                    'success' => true,
                    'order_status' => 'Successful',
                ],
            ],
        ]);

    $transaction->refresh();
    expect($transaction->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL);
    expect($transaction->gateway_bank_ref_no)->toBe('SBI_999888');
});

test('can refund transaction via api with role permission', function () {
    $invoice = StudentFeeInvoice::create([
        'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
        'user_id' => $this->student->id,
        'student_id' => $this->student->id,
        'fee_type_id' => $this->feeType->id,
        'title' => 'Refundable Exam Fee',
        'total_amount' => 500.00,
        'paid_amount' => 500.00,
        'balance_amount' => 0.00,
        'status' => StudentFeeInvoice::STATUS_PAID,
        'issue_date' => now(),
        'due_date' => now()->addDays(15),
    ]);

    $transaction = PaymentTransaction::create([
        'user_id' => $this->student->id,
        'invoice_id' => $invoice->id,
        'fee_type_id' => $this->feeType->id,
        'payment_category' => 'academic',
        'amount' => 500.00,
        'currency' => 'INR',
        'payment_method' => 'online',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'status' => PaymentTransaction::STATUS_SUCCESSFUL,
        'paid_at' => now(),
        'transaction_no' => 'TXN-API-REFUND-001',
        'gateway_tracking_id' => 'CC_TRK_REF_001',
    ]);

    $response = $this->actingAs($this->admin)
        ->withHeader('X-API-Key', $this->apiKey)
        ->postJson("/api/v1/payments/transactions/{$transaction->id}/refund", [
            'amount' => 500.00,
            'reason' => 'Student duplicate transaction refund requested.',
            'reference_no' => 'REF-API-VOUCHER-01',
            'attempt_gateway_api' => false,
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'refunded',
                'refund_status' => 'processed',
                'refund_amount' => 500.0,
            ],
        ]);

    $transaction->refresh();
    expect($transaction->isRefunded())->toBeTrue();
    expect((float) $transaction->refund_amount)->toBe(500.00);

    $invoice->refresh();
    expect($invoice->status)->toBe(StudentFeeInvoice::STATUS_PENDING);
    expect((float) $invoice->balance_amount)->toBe(500.00);
});

test('unauthorized user cannot refund transaction via api', function () {
    $transaction = PaymentTransaction::create([
        'user_id' => $this->student->id,
        'amount' => 500.00,
        'currency' => 'INR',
        'payment_method' => 'online',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'status' => PaymentTransaction::STATUS_SUCCESSFUL,
        'paid_at' => now(),
        'transaction_no' => 'TXN-API-UNAUTH-001',
    ]);

    $response = $this->actingAs($this->student)
        ->postJson("/api/v1/payments/transactions/{$transaction->id}/refund", [
            'amount' => 500.00,
            'reason' => 'Unauthorized refund attempt',
        ]);

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'FORBIDDEN',
            ],
        ]);
});

test('can reconcile transaction status via api', function () {
    $transaction = PaymentTransaction::create([
        'user_id' => $this->student->id,
        'amount' => 750.00,
        'currency' => 'INR',
        'payment_method' => 'online',
        'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
        'status' => PaymentTransaction::STATUS_PENDING,
        'transaction_no' => 'TXN-API-RECON-001',
    ]);

    $response = $this->actingAs($this->admin)
        ->withHeader('X-API-Key', $this->apiKey)
        ->postJson("/api/v1/payments/transactions/{$transaction->id}/reconcile", [
            'status' => 'completed',
            'gateway_tracking_id' => 'CC_ADMIN_MANUAL_123',
            'gateway_bank_ref_no' => 'HDFC_ADMIN_999',
            'gateway_payment_mode' => 'Net Banking',
            'gateway_charged_amount' => 750.00,
            'gateway_fee' => 0.00,
            'reason' => 'Verified against bank statement credit confirmation',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'successful',
                'gateway_tracking_id' => 'CC_ADMIN_MANUAL_123',
                'gateway_bank_ref_no' => 'HDFC_ADMIN_999',
            ],
        ]);

    $transaction->refresh();
    expect($transaction->status)->toBe(PaymentTransaction::STATUS_SUCCESSFUL);
    expect($transaction->gateway_tracking_id)->toBe('CC_ADMIN_MANUAL_123');
});

test('developer console docs component can open ccavenue integration guide', function () {
    Livewire\Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.docs')
        ->call('openGuide', 'ccavenue')
        ->assertSet('showGuideModal', true)
        ->assertSee('CCAvenue Payment Gateway, Surcharge Reconciliation & Refund API');
});
