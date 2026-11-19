<?php

namespace App\Services\Payment\Gateways;

use App\Models\PaymentTransaction;
use App\Models\Setting;
use App\Models\StudentAddress;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CCAVenueGateway implements PaymentGatewayInterface
{
    public const LIVE_CHECKOUT_URL = 'https://secure.ccavenue.com/transaction/transaction.do?command=initiateTransaction';

    public const TEST_CHECKOUT_URL = 'https://test.ccavenue.com/transaction/transaction.do?command=initiateTransaction';

    public const LIVE_API_URL = 'https://api.ccavenue.com/apis/servlet/DoWebTrans';

    public const TEST_API_URL = 'https://apitest.ccavenue.com/apis/servlet/DoWebTrans';

    private const INIT_VECTOR = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f";

    public function id(): string
    {
        return 'ccavenue';
    }

    public function name(): string
    {
        return 'CCAVenue Payment Gateway';
    }

    public function isEnabled(): bool
    {
        $enabled = Setting::get('payment.ccavenue_enabled') ?? Setting::get('ccavenue_enabled') ?? false;

        return (bool) $enabled;
    }

    public function isConfigured(): bool
    {
        $merchantId = Setting::get('payment.ccavenue_merchant_id') ?: Setting::get('ccavenue_merchant_id');
        $accessCode = Setting::get('payment.ccavenue_access_code') ?: Setting::get('ccavenue_access_code');

        return ! empty($merchantId) && ! empty($accessCode);
    }

    public function isOnline(): bool
    {
        return true;
    }

    public function getCheckoutUrl(): string
    {
        $mode = (string) (Setting::get('payment.ccavenue_mode') ?: Setting::get('ccavenue_mode') ?: 'test');

        return in_array($mode, ['live', 'production'], true) ? self::LIVE_CHECKOUT_URL : self::TEST_CHECKOUT_URL;
    }

    public function getApiUrl(): string
    {
        $mode = (string) (Setting::get('payment.ccavenue_mode') ?: Setting::get('ccavenue_mode') ?: 'test');

        return in_array($mode, ['live', 'production'], true) ? self::LIVE_API_URL : self::TEST_API_URL;
    }

    public function isBillingPageEnabled(): bool
    {
        return (bool) (Setting::get('payment.ccavenue_billing_page_enabled') ?? Setting::get('ccavenue_billing_page_enabled') ?? false);
    }

    public function getWorkingKey(): string
    {
        $key = (string) (Setting::get('payment.ccavenue_working_key') ?: Setting::get('ccavenue_working_key'));

        return ! empty($key) ? $key : '4886C852179CD45A4543C124239854DB';
    }

    /**
     * Prepare order payload and encrypted request for CCAvenue checkout.
     *
     * CRITICAL COMPLIANCE NOTICE:
     * When CCAvenue turns off the checkout page Billing Information step,
     * every order MUST still be posted from the backend with:
     * 1. billing_country (mandatory)
     * 2. billing_tel (customer contact number)
     * 3. billing_email (customer email)
     * 4. billing_name, billing_address, billing_city, billing_state, billing_zip
     *
     * If these details are ever missing in live transactions, CCAvenue turns the
     * customer billing page back on without notice for 6 months.
     * System automatically applies configured fallback defaults:
     * Address: Kolkata, City: Kolkata, State: West Bengal, Zip: 700106, Country: India.
     */
    public function createOrder(PaymentTransaction $transaction, array $extra = []): array
    {
        $merchantId = (string) (Setting::get('payment.ccavenue_merchant_id') ?: Setting::get('ccavenue_merchant_id'));
        $accessCode = (string) (Setting::get('payment.ccavenue_access_code') ?: Setting::get('ccavenue_access_code'));
        $workingKey = $this->getWorkingKey();

        $orderId = 'CC_'.str_replace('-', '_', $transaction->transaction_no);

        $user = $transaction->user;

        // Fallback default billing settings
        $defaultAddress = (string) (Setting::get('payment.ccavenue_default_billing_address') ?: Setting::get('ccavenue_default_address') ?: 'Kolkata');
        $defaultCity = (string) (Setting::get('payment.ccavenue_default_billing_city') ?: Setting::get('ccavenue_default_city') ?: 'Kolkata');
        $defaultState = (string) (Setting::get('payment.ccavenue_default_billing_state') ?: Setting::get('ccavenue_default_state') ?: 'West Bengal');
        $defaultZip = (string) (Setting::get('payment.ccavenue_default_billing_zip') ?: Setting::get('ccavenue_default_zip') ?: '700106');
        $defaultCountry = (string) (Setting::get('payment.ccavenue_default_billing_country') ?: Setting::get('ccavenue_default_country') ?: 'India');
        $defaultTel = (string) (Setting::get('payment.ccavenue_default_billing_tel') ?: Setting::get('ccavenue_default_phone') ?: '9999999999');
        $defaultEmail = (string) (Setting::get('payment.ccavenue_default_billing_email') ?: Setting::get('ccavenue_default_email') ?: config('mail.from.address', 'info@sntcssc.in'));

        // Resolve student profile address if available
        /** @var StudentAddress|null $address */
        $address = null;
        if ($user && method_exists($user, 'studentAddresses')) {
            $address = $user->studentAddresses()->where('address_type', 'Permanent')->first()
                ?? $user->studentAddresses()->first();
        }

        // 1. Billing Name
        $billingName = trim((string) ($user?->name ?: 'Student'));

        // 2. Billing Email (Mandatory)
        $billingEmail = trim((string) ($user?->email ?: $defaultEmail));
        if (! filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
            $billingEmail = $defaultEmail;
        }

        // 3. Billing Phone (Mandatory - cleaned numeric digits)
        $rawPhone = preg_replace('/\D+/', '', (string) ($user?->phone ?: ''));
        if (strlen($rawPhone) < 10) {
            $rawPhone = preg_replace('/\D+/', '', $defaultTel) ?: '9999999999';
        }
        $billingTel = substr($rawPhone, -10);

        // 4. Billing Address
        $billingAddress = $address?->address_line1 ?: $defaultAddress;
        if (empty(trim($billingAddress))) {
            $billingAddress = $defaultAddress;
        }

        // 5. Billing City
        $billingCity = $address?->district ?: $defaultCity;
        if (empty(trim($billingCity))) {
            $billingCity = $defaultCity;
        }

        // 6. Billing State
        $billingState = $address?->state ?: $defaultState;
        if (empty(trim($billingState))) {
            $billingState = $defaultState;
        }

        // 7. Billing Zip Code
        $billingZip = $address?->pin_code ?: $defaultZip;
        if (empty(trim($billingZip))) {
            $billingZip = $defaultZip;
        }

        // 8. Billing Country (Mandatory - India by default)
        $billingCountry = $defaultCountry ?: 'India';

        // Redirect & Cancel URLs
        $redirectUrl = $extra['redirect_url'] ?? route('payments.ccavenue.response');
        $cancelUrl = $extra['cancel_url'] ?? route('payments.ccavenue.cancel');

        $params = [
            'merchant_id' => $merchantId,
            'order_id' => $orderId,
            'amount' => number_format((float) $transaction->amount, 2, '.', ''),
            'currency' => $transaction->currency ?: 'INR',
            'redirect_url' => $redirectUrl,
            'cancel_url' => $cancelUrl,
            'language' => 'EN',

            // Mandatory Customer & Bank Verification Data
            'billing_name' => substr($billingName, 0, 60),
            'billing_address' => substr($billingAddress, 0, 150),
            'billing_city' => substr($billingCity, 0, 30),
            'billing_state' => substr($billingState, 0, 30),
            'billing_zip' => substr($billingZip, 0, 15),
            'billing_country' => substr($billingCountry, 0, 50),
            'billing_tel' => substr($billingTel, 0, 20),
            'billing_email' => substr($billingEmail, 0, 70),

            // Mirror delivery fields for full consistency
            'delivery_name' => substr($billingName, 0, 60),
            'delivery_address' => substr($billingAddress, 0, 150),
            'delivery_city' => substr($billingCity, 0, 30),
            'delivery_state' => substr($billingState, 0, 30),
            'delivery_zip' => substr($billingZip, 0, 15),
            'delivery_country' => substr($billingCountry, 0, 50),
            'delivery_tel' => substr($billingTel, 0, 20),

            // Custom metadata references
            'merchant_param1' => (string) $transaction->transaction_no,
            'merchant_param2' => (string) ($transaction->student_id ?: $user?->student_id ?: ''),
            'merchant_param3' => (string) ($transaction->invoice_id ?: ''),
            'merchant_param4' => (string) $transaction->id,
            'merchant_param5' => (string) ($transaction->user_id ?: ''),
        ];

        // Format as merchant_data string (key=value&...)
        $merchantData = '';
        foreach ($params as $key => $val) {
            $merchantData .= $key.'='.urlencode((string) $val).'&';
        }
        $merchantData = rtrim($merchantData, '&');

        $encryptedData = $this->encrypt($merchantData, $workingKey);

        // Record billing snapshot and order ID on transaction
        $transaction->update([
            'gateway' => $this->id(),
            'gateway_order_id' => $orderId,
            'is_offline' => false,
            'billing_country' => $billingCountry,
            'billing_tel' => $billingTel,
            'billing_email' => $billingEmail,
        ]);

        return [
            'gateway' => $this->id(),
            'endpoint' => $this->getCheckoutUrl(),
            'action_url' => $this->getCheckoutUrl(),
            'checkout_url' => $this->getCheckoutUrl(),
            'access_code' => $accessCode,
            'enc_request' => $encryptedData,
            'order_id' => $orderId,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency ?: 'INR',
            'billing' => [
                'name' => $billingName,
                'email' => $billingEmail,
                'tel' => $billingTel,
                'address' => $billingAddress,
                'city' => $billingCity,
                'state' => $billingState,
                'zip' => $billingZip,
                'country' => $billingCountry,
                'billing_country' => $billingCountry,
                'billing_tel' => $billingTel,
                'billing_email' => $billingEmail,
            ],
            'billing_page_enabled' => $this->isBillingPageEnabled(),
        ];
    }

    /**
     * Verify payment completion from CCAvenue encrypted response payload.
     *
     * Handles gateway charges, payment method surcharge, card fee differences,
     * and amount mismatch detection.
     */
    public function verifyPayment(PaymentTransaction $transaction, array $payload): array
    {
        $workingKey = $this->getWorkingKey();
        $encResponse = $payload['encResp'] ?? $payload['enc_response'] ?? null;

        $responseData = [];

        if (! empty($encResponse)) {
            $rcvdString = $this->decrypt((string) $encResponse, $workingKey);
            parse_str($rcvdString, $responseData);
        } elseif (! empty($payload['raw_response']) && is_array($payload['raw_response'])) {
            $responseData = $payload['raw_response'];
        } else {
            $responseData = $payload;
        }

        $orderStatus = trim((string) ($responseData['order_status'] ?? ''));
        $trackingId = $responseData['tracking_id'] ?? $responseData['reference_no'] ?? null;
        $bankRefNo = $responseData['bank_ref_no'] ?? null;
        $paymentMode = $responseData['payment_mode'] ?? null;
        $cardName = $responseData['card_name'] ?? null;
        $failureMessage = $responseData['failure_message'] ?? $responseData['status_message'] ?? null;

        // Financial amount analysis
        $gatewayChargedAmount = isset($responseData['amount']) ? (float) $responseData['amount'] : (float) $transaction->amount;
        $merAmount = isset($responseData['mer_amount']) ? (float) $responseData['mer_amount'] : (float) $transaction->amount;
        $transFee = isset($responseData['trans_fee']) ? (float) $responseData['trans_fee'] : 0.00;
        $serviceTax = isset($responseData['service_tax']) ? (float) $responseData['service_tax'] : 0.00;

        // If gateway added fees (e.g. Card, Netbanking surcharge)
        if ($transFee <= 0.00 && $gatewayChargedAmount > $transaction->amount) {
            $transFee = round($gatewayChargedAmount - (float) $transaction->amount, 2);
        } else {
            $transFee = round($transFee + $serviceTax, 2);
        }

        $isSuccess = strcasecmp($orderStatus, 'Success') === 0 || strcasecmp($orderStatus, 'Successful') === 0;

        if ($isSuccess) {
            $amountMismatch = false;
            $mismatchNotes = null;

            // Scenario: Gateway collected LESS than the institutional fee requirement
            if ($gatewayChargedAmount < ((float) $transaction->amount - 0.01)) {
                $amountMismatch = true;
                $mismatchNotes = sprintf(
                    'Amount Mismatch: Institutional required fee was ₹%s, but gateway response authorized ₹%s (Mer Amount: ₹%s).',
                    number_format((float) $transaction->amount, 2),
                    number_format($gatewayChargedAmount, 2),
                    number_format($merAmount, 2)
                );
            }

            return [
                'success' => true,
                'order_status' => 'Success',
                'message' => 'CCAVenue transaction successfully authorized.',
                'transaction_id' => $trackingId ?: $transaction->gateway_order_id,
                'bank_ref_no' => $bankRefNo,
                'payment_mode' => $paymentMode,
                'card_name' => $cardName,
                'charged_amount' => $gatewayChargedAmount,
                'base_amount' => (float) $transaction->amount,
                'gateway_fee' => $transFee,
                'gateway_tax' => $serviceTax,
                'amount_mismatch' => $amountMismatch,
                'amount_mismatch_notes' => $mismatchNotes,
                'raw_response' => $responseData,
            ];
        }

        $statusNormalized = 'Failure';
        if (strcasecmp($orderStatus, 'Aborted') === 0) {
            $statusNormalized = 'Aborted';
        }

        return [
            'success' => false,
            'order_status' => $statusNormalized,
            'message' => $failureMessage ?: ('CCAVenue returned status: '.($orderStatus ?: 'Declined')),
            'transaction_id' => $trackingId ?: $transaction->gateway_order_id,
            'bank_ref_no' => $bankRefNo,
            'payment_mode' => $paymentMode,
            'raw_response' => $responseData,
        ];
    }

    /**
     * Query CCAvenue Order Status Tracker API.
     *
     * Used when student back-button, network drop, timeout, or callback failure occurred.
     * Reconciles transaction status live with CCAvenue servers.
     *
     * @return array{success: bool, status: string, data?: array<string, mixed>, error?: string}
     */
    public function queryOrderStatus(PaymentTransaction $transaction): array
    {
        $accessCode = (string) Setting::get('payment.ccavenue_access_code');
        $workingKey = $this->getWorkingKey();
        $apiUrl = $this->getApiUrl();

        if (empty($accessCode)) {
            return [
                'success' => false,
                'status' => 'error',
                'error' => __('CCAvenue Access Code is not configured in settings.'),
            ];
        }

        $queryPayload = [
            'order_no' => $transaction->gateway_order_id ?: ('CC_'.str_replace('-', '_', $transaction->transaction_no)),
        ];

        if (! empty($transaction->gateway_tracking_id)) {
            $queryPayload['reference_no'] = (string) $transaction->gateway_tracking_id;
        }

        $encryptedRequest = $this->encrypt(json_encode($queryPayload, JSON_UNESCAPED_SLASHES), $workingKey);

        try {
            $response = Http::withoutVerifying()
                ->asForm()
                ->timeout(20)
                ->post($apiUrl, [
                    'enc_request' => $encryptedRequest,
                    'access_code' => $accessCode,
                    'command' => 'orderStatusTracker',
                    'request_type' => 'JSON',
                    'response_type' => 'JSON',
                    'version' => '1.2',
                ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'status' => 'http_error',
                    'error' => "CCAvenue API returned HTTP {$response->status()}",
                ];
            }

            $body = trim($response->body());
            parse_str($body, $parsed);

            $encResponse = $parsed['enc_response'] ?? '';
            $status = (string) ($parsed['status'] ?? (! empty($encResponse) ? '0' : '1'));

            if ($status === '0' && ! empty($encResponse)) {
                $decrypted = $this->decrypt($encResponse, $workingKey);
                $data = json_decode($decrypted, true);

                if (is_array($data)) {
                    $orderStatus = $data['order_status'] ?? 'Unknown';

                    return [
                        'success' => true,
                        'status' => $orderStatus,
                        'data' => $data,
                    ];
                }
            }

            $errorMsg = $parsed['enc_response'] ?? $parsed['error_desc'] ?? 'Unable to retrieve status from CCAvenue.';

            return [
                'success' => false,
                'status' => 'failed',
                'error' => (string) $errorMsg,
            ];
        } catch (Throwable $e) {
            Log::error('CCAVenue orderStatusTracker error: '.$e->getMessage(), [
                'transaction_id' => $transaction->id,
            ]);

            return [
                'success' => false,
                'status' => 'exception',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute Refund via CCAvenue API.
     *
     * @return array{success: bool, message: string, refund_ref_no?: string, error_code?: string}
     */
    public function refundOrder(
        PaymentTransaction $transaction,
        float $refundAmount,
        string $refundRefNo,
        string $reason = ''
    ): array {
        $accessCode = (string) Setting::get('payment.ccavenue_access_code');
        $workingKey = $this->getWorkingKey();
        $apiUrl = $this->getApiUrl();

        $referenceNo = $transaction->gateway_tracking_id;
        if (empty($referenceNo)) {
            return [
                'success' => false,
                'message' => __('CCAvenue Reference / Tracking No is required to initiate an API refund.'),
            ];
        }

        $queryPayload = [
            'reference_no' => (string) $referenceNo,
            'refund_amount' => number_format($refundAmount, 2, '.', ''),
            'refund_ref_no' => substr($refundRefNo, 0, 30),
        ];

        $encryptedRequest = $this->encrypt(json_encode($queryPayload, JSON_UNESCAPED_SLASHES), $workingKey);

        try {
            $response = Http::withoutVerifying()
                ->asForm()
                ->timeout(25)
                ->post($apiUrl, [
                    'enc_request' => $encryptedRequest,
                    'access_code' => $accessCode,
                    'command' => 'refundOrder',
                    'request_type' => 'JSON',
                    'response_type' => 'JSON',
                    'version' => '1.1',
                ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => "CCAvenue Refund API returned HTTP {$response->status()}",
                ];
            }

            $body = trim($response->body());
            parse_str($body, $parsed);

            $encResponse = $parsed['enc_response'] ?? '';
            $status = (string) ($parsed['status'] ?? (! empty($encResponse) ? '0' : '1'));

            if ($status === '0' && ! empty($encResponse)) {
                $decrypted = $this->decrypt($encResponse, $workingKey);
                $data = json_decode($decrypted, true);

                if (is_array($data) && isset($data['refund_status']) && (int) $data['refund_status'] === 0) {
                    return [
                        'success' => true,
                        'message' => __('Refund successfully processed by CCAvenue.'),
                        'refund_ref_no' => $refundRefNo,
                        'data' => $data,
                    ];
                }

                $reasonMsg = $data['reason'] ?? $data['error_desc'] ?? __('Refund request rejected by CCAvenue.');

                return [
                    'success' => false,
                    'message' => $reasonMsg,
                    'error_code' => (string) ($data['error_code'] ?? ''),
                ];
            }

            return [
                'success' => false,
                'message' => (string) ($parsed['enc_response'] ?? __('Refund API call failed.')),
                'error_code' => (string) ($parsed['enc_error_code'] ?? ''),
            ];
        } catch (Throwable $e) {
            Log::error('CCAVenue refundOrder error: '.$e->getMessage(), [
                'transaction_id' => $transaction->id,
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * AES-128-CBC encryption as per CCAvenue PHP integration kit.
     */
    public function encrypt(string $plainText, ?string $key = null): string
    {
        $key = ! empty($key) ? $key : $this->getWorkingKey();
        $secretKey = hex2bin(md5($key));

        $encrypted = openssl_encrypt(
            $plainText,
            'AES-128-CBC',
            $secretKey,
            OPENSSL_RAW_DATA,
            self::INIT_VECTOR
        );

        return bin2hex($encrypted ?: '');
    }

    /**
     * AES-128-CBC decryption as per CCAvenue PHP integration kit.
     */
    public function decrypt(string $encryptedText, ?string $key = null): string
    {
        $encryptedText = trim($encryptedText);
        if (empty($encryptedText) || ! ctype_xdigit($encryptedText)) {
            return '';
        }

        $key = ! empty($key) ? $key : $this->getWorkingKey();
        $secretKey = hex2bin(md5($key));
        $binary = hex2bin($encryptedText);

        $decrypted = openssl_decrypt(
            $binary,
            'AES-128-CBC',
            $secretKey,
            OPENSSL_RAW_DATA,
            self::INIT_VECTOR
        );

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Parse query string response from CCAvenue into key-value array.
     */
    public function parseResponse(string $responseString): array
    {
        $responseData = [];
        parse_str($responseString, $responseData);

        return $responseData;
    }
}
