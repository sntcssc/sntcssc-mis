<?php

namespace App\Services\Payment\Gateways;

use App\Models\PaymentTransaction;
use App\Models\Setting;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Storage;

class OfflineGateway implements PaymentGatewayInterface
{
    public function id(): string
    {
        return 'offline';
    }

    public function name(): string
    {
        return 'Offline Payment (Static UPI QR, NEFT/IMPS, Cash Counter)';
    }

    public function isEnabled(): bool
    {
        return (bool) Setting::get('payment.offline_enabled', true);
    }

    public function isConfigured(): bool
    {
        return true; // Always configured with fallback defaults
    }

    public function isOnline(): bool
    {
        return false;
    }

    public function getBankTransferDetails(): array
    {
        return [
            'enabled' => (bool) Setting::get('payment.offline_bank_transfer_enabled', true),
            'bank_name' => Setting::get('payment.offline_bank_name', 'State Bank of India'),
            'account_name' => Setting::get('payment.offline_account_name', 'Satyendranath Tagore Civil Services Study Centre'),
            'account_number' => Setting::get('payment.offline_account_number', '39824589201'),
            'ifsc_code' => Setting::get('payment.offline_ifsc_code', 'SBIN0000123'),
            'branch' => Setting::get('payment.offline_branch', 'Kolkata Main Branch'),
        ];
    }

    public function getUpiDetails(?float $amount = null, ?string $transactionNo = null): array
    {
        $upiId = Setting::get('payment.offline_upi_id', 'sntcssc@indianbk');
        $payeeName = Setting::get('payment.offline_upi_payee_name', 'SNTCSSC Kolkata');
        $enabled = (bool) Setting::get('payment.offline_upi_enabled', true);

        // Check if custom uploaded QR code image exists
        $customQrPath = Setting::get('payment.offline_custom_qr_image');
        $customQrUrl = null;
        if (! empty($customQrPath)) {
            if (Storage::disk('public')->exists($customQrPath)) {
                $customQrUrl = Storage::disk('public')->url($customQrPath);
            } elseif (file_exists(public_path($customQrPath))) {
                $customQrUrl = asset($customQrPath);
            }
        }

        $params = [
            'pa' => $upiId,
            'pn' => $payeeName,
            'cu' => 'INR',
        ];

        if ($amount !== null && $amount > 0) {
            $params['am'] = number_format($amount, 2, '.', '');
        }

        if ($transactionNo) {
            $params['tn'] = 'Fee payment '.$transactionNo;
            $params['tr'] = $transactionNo;
        }

        $upiUri = 'upi://pay?'.http_build_query($params);
        $qrCodeDataUri = $this->generateQrCodeDataUri($upiUri);

        return [
            'enabled' => $enabled,
            'upi_id' => $upiId,
            'payee_name' => $payeeName,
            'upi_uri' => $upiUri,
            'has_custom_qr' => ! empty($customQrUrl),
            'custom_qr_image_url' => $customQrUrl,
            'qr_code_data_uri' => $qrCodeDataUri,
            'qr_image_url' => $customQrUrl ?: $qrCodeDataUri,
        ];
    }

    public function getCashDetails(): array
    {
        return [
            'enabled' => (bool) Setting::get('payment.offline_cash_enabled', true),
            'instructions' => Setting::get('payment.offline_cash_instructions', 'Pay cash directly at Accounts Counter, Room 102, Administrative Block between 10:00 AM and 4:00 PM on working days and collect official signed receipt.'),
        ];
    }

    public function createOrder(PaymentTransaction $transaction, array $extra = []): array
    {
        $transaction->update([
            'gateway' => $this->id(),
            'is_offline' => true,
            'status' => PaymentTransaction::STATUS_UNDER_REVIEW,
        ]);

        return [
            'gateway' => $this->id(),
            'bank_transfer' => $this->getBankTransferDetails(),
            'upi' => $this->getUpiDetails($transaction->amount, $transaction->transaction_no),
            'cash' => $this->getCashDetails(),
            'transaction_no' => $transaction->transaction_no,
            'amount' => $transaction->amount,
        ];
    }

    public function verifyPayment(PaymentTransaction $transaction, array $payload): array
    {
        // For offline payments, verification is manual by administrator or accounts staff
        return [
            'success' => true,
            'message' => 'Offline payment details submitted for verification.',
            'transaction_id' => $payload['reference_id'] ?? $transaction->offline_reference_id,
        ];
    }

    public function generateQrCodeDataUri(string $content): string
    {
        try {
            if (extension_loaded('gd')) {
                $options = new QROptions([
                    'outputInterface' => QRGdImagePNG::class,
                    'outputBase64' => true,
                    'scale' => 6,
                ]);

                return (new QRCode($options))->render($content);
            }

            $options = new QROptions([
                'outputBase64' => true,
                'scale' => 6,
            ]);

            return (new QRCode($options))->render($content);
        } catch (\Throwable $e) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="200" height="200" fill="#f8fafc" stroke="#cbd5e1" stroke-width="2" rx="12"/><text x="50%" y="45%" dominant-baseline="middle" text-anchor="middle" font-size="14" font-weight="bold" fill="#0f172a">UPI QR Code</text><text x="50%" y="60%" dominant-baseline="middle" text-anchor="middle" font-size="11" fill="#64748b">Scan to Pay Fee</text></svg>';

            return 'data:image/svg+xml;base64,'.base64_encode($svg);
        }
    }
}
