<?php

namespace App\Services\Payment\Contracts;

use App\Models\PaymentTransaction;

interface PaymentGatewayInterface
{
    /**
     * Unique identifier key of the gateway.
     */
    public function id(): string;

    /**
     * Human-readable display label.
     */
    public function name(): string;

    /**
     * Check if gateway is globally enabled in settings.
     */
    public function isEnabled(): bool;

    /**
     * Check if gateway has all required API credentials configured.
     */
    public function isConfigured(): bool;

    /**
     * Whether this is an online automated gateway.
     */
    public function isOnline(): bool;

    /**
     * Initialize order / checkout session for a transaction.
     *
     * @return array<string, mixed>
     */
    public function createOrder(PaymentTransaction $transaction, array $extra = []): array;

    /**
     * Verify payment confirmation or webhook callback payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message: string, transaction_id?: ?string, error?: ?string}
     */
    public function verifyPayment(PaymentTransaction $transaction, array $payload): array;
}
