<?php

namespace App\Services\Payment\Gateways;

use App\Models\PaymentTransaction;
use App\Models\Setting;
use App\Services\Payment\Contracts\PaymentGatewayInterface;

class RazorpayGateway implements PaymentGatewayInterface
{
    public function id(): string
    {
        return 'razorpay';
    }

    public function name(): string
    {
        return 'Razorpay (Cards, UPI, NetBanking, Wallets)';
    }

    public function isEnabled(): bool
    {
        return (bool) Setting::get('payment.enabled', true) && (bool) Setting::get('payment.razorpay_enabled', false);
    }

    public function isConfigured(): bool
    {
        $keyId = Setting::get('payment.razorpay_key_id');

        return ! empty($keyId);
    }

    public function isOnline(): bool
    {
        return true;
    }

    public function createOrder(PaymentTransaction $transaction, array $extra = []): array
    {
        $keyId = (string) Setting::get('payment.razorpay_key_id');
        $amountInPaise = (int) round($transaction->amount * 100);

        // Generate clean receipt / order reference
        $orderId = 'order_'.substr(md5($transaction->transaction_no.time()), 0, 16);

        $transaction->update([
            'gateway' => $this->id(),
            'gateway_order_id' => $orderId,
            'is_offline' => false,
        ]);

        return [
            'gateway' => $this->id(),
            'key_id' => $keyId,
            'amount' => $amountInPaise,
            'currency' => $transaction->currency ?: 'INR',
            'order_id' => $orderId,
            'name' => 'Satyendranath Tagore CSSC',
            'description' => $transaction->invoice?->title ?: 'Institute Fee Payment',
            'prefill' => [
                'name' => $transaction->user->name,
                'email' => $transaction->user->email,
                'contact' => $transaction->user->phone,
            ],
            'notes' => [
                'transaction_no' => $transaction->transaction_no,
                'student_id' => $transaction->student_id,
            ],
        ];
    }

    public function verifyPayment(PaymentTransaction $transaction, array $payload): array
    {
        $keySecret = (string) Setting::get('payment.razorpay_key_secret');
        $orderId = $payload['razorpay_order_id'] ?? $transaction->gateway_order_id;
        $paymentId = $payload['razorpay_payment_id'] ?? null;
        $signature = $payload['razorpay_signature'] ?? null;

        if (empty($paymentId) || empty($signature)) {
            return [
                'success' => false,
                'message' => 'Missing Razorpay payment ID or signature.',
            ];
        }

        $expectedSignature = hash_hmac('sha256', $orderId.'|'.$paymentId, $keySecret);

        if (! hash_equals($expectedSignature, $signature)) {
            return [
                'success' => false,
                'message' => 'Invalid payment signature verification failed.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Payment verified successfully via Razorpay.',
            'transaction_id' => $paymentId,
        ];
    }
}
