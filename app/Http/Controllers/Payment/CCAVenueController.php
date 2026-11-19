<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Services\Payment\FeePaymentService;
use App\Services\Payment\Gateways\CCAVenueGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class CCAVenueController extends Controller
{
    /**
     * Render auto-submitting POST redirection form to CCAvenue.
     */
    public function redirect(Request $request, PaymentTransaction $transaction): View|RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->id !== $transaction->user_id && ! $user->can('payments.create')) {
            abort(403, __('You are not authorized to access this payment transaction.'));
        }

        if ($transaction->status === PaymentTransaction::STATUS_SUCCESSFUL) {
            $teamSlug = $user?->ensureCurrentTeam()?->slug ?? 'default';

            return redirect()->route('student.payments.index', ['current_team' => $teamSlug])
                ->with('toast', ['type' => 'info', 'message' => __('This payment has already been completed.')]);
        }

        $gateway = new CCAVenueGateway;

        if (! $gateway->isEnabled() || ! $gateway->isConfigured()) {
            $teamSlug = $user?->ensureCurrentTeam()?->slug ?? 'default';

            return redirect()->route('student.payments.index', ['current_team' => $teamSlug])
                ->with('toast', ['type' => 'error', 'message' => __('CCAVenue payment gateway is currently unavailable.')]);
        }

        $orderData = $gateway->createOrder($transaction, [
            'redirect_url' => route('payments.ccavenue.response'),
            'cancel_url' => route('payments.ccavenue.cancel'),
        ]);

        return view('payments.ccavenue-redirect', [
            'transaction' => $transaction,
            'orderData' => $orderData,
        ]);
    }

    /**
     * Handle POST callback redirect from CCAvenue when payment completes.
     */
    public function response(Request $request): RedirectResponse
    {
        $gateway = new CCAVenueGateway;
        $encResponse = $request->input('encResp') ?? $request->input('enc_response');

        if (empty($encResponse)) {
            Log::warning('CCAVenue response received with empty encResp.');

            return $this->fallbackRedirect(__('Invalid or empty payment gateway response.'));
        }

        $workingKey = $gateway->getWorkingKey();
        $decryptedString = $gateway->decrypt((string) $encResponse, $workingKey);
        parse_str($decryptedString, $responseData);

        $orderId = $responseData['order_id'] ?? null;
        $trackingId = $responseData['tracking_id'] ?? null;
        $merchantParam1 = $responseData['merchant_param1'] ?? null;
        $merchantParam4 = $responseData['merchant_param4'] ?? null;

        // Resolve transaction through robust identifier matching
        $transaction = null;
        if (! empty($merchantParam4) && is_numeric($merchantParam4)) {
            $transaction = PaymentTransaction::find((int) $merchantParam4);
        }
        if (! $transaction && ! empty($merchantParam1)) {
            $transaction = PaymentTransaction::where('transaction_no', $merchantParam1)->first();
            if (! $transaction && is_numeric($merchantParam1)) {
                $transaction = PaymentTransaction::find((int) $merchantParam1);
            }
        }
        if (! $transaction && ! empty($orderId)) {
            $cleanOrderId = preg_replace('/^CC_/', '', (string) $orderId);
            $cleanOrderId = str_replace('_', '-', $cleanOrderId);
            $transaction = PaymentTransaction::where('gateway_order_id', $orderId)->first()
                ?? PaymentTransaction::where('transaction_no', $orderId)->first()
                ?? PaymentTransaction::where('transaction_no', $cleanOrderId)->first();
        }

        if (! $transaction) {
            Log::error('CCAVenue callback could not match transaction', [
                'order_id' => $orderId,
                'tracking_id' => $trackingId,
                'merchant_param1' => $merchantParam1,
            ]);

            return $this->fallbackRedirect(__('Payment transaction could not be located. Please contact accounts support.'));
        }

        // Restore authenticated user session if lost across cross-site POST
        if (! Auth::check() && $transaction->user) {
            Auth::login($transaction->user);
        }

        $verification = $gateway->verifyPayment($transaction, ['raw_response' => $responseData]);

        $teamSlug = $transaction->user?->ensureCurrentTeam()?->slug ?? 'default';
        $redirectRoute = route('student.payments.index', ['current_team' => $teamSlug]);

        if ($verification['success']) {
            if ($verification['amount_mismatch']) {
                FeePaymentService::handleAmountMismatch(
                    transaction: $transaction,
                    data: [
                        'gateway_tracking_id' => $verification['transaction_id'] ?? $trackingId,
                        'gateway_bank_ref_no' => $verification['bank_ref_no'] ?? null,
                        'gateway_charged_amount' => $verification['charged_amount'],
                        'gateway_fee' => $verification['gateway_fee'],
                        'response_payload' => $responseData,
                    ],
                    reason: $verification['amount_mismatch_notes'] ?: __('Amount mismatch detected between expected fee and gateway response.')
                );

                return redirect($redirectRoute)->with('toast', [
                    'type' => 'warning',
                    'message' => __('Payment authorized by bank but flagged for administrative review due to an amount difference. Please check back shortly.'),
                ]);
            }

            FeePaymentService::completeOnlinePaymentWithCharges(
                transaction: $transaction,
                data: [
                    'gateway_payment_id' => $verification['transaction_id'] ?? $trackingId,
                    'gateway_tracking_id' => $verification['transaction_id'] ?? $trackingId,
                    'gateway_bank_ref_no' => $verification['bank_ref_no'] ?? null,
                    'gateway_payment_mode' => $verification['payment_mode'] ?? null,
                    'gateway_card_name' => $verification['card_name'] ?? null,
                    'gateway_charged_amount' => $verification['charged_amount'],
                    'gateway_fee' => $verification['gateway_fee'],
                    'gateway_tax' => $verification['gateway_tax'],
                    'response_payload' => $responseData,
                    'payment_method' => $verification['payment_mode'] ? strtolower((string) $verification['payment_mode']) : 'ccavenue',
                ]
            );

            return redirect($redirectRoute)->with('toast', [
                'type' => 'success',
                'message' => __('Payment successfully completed! Official e-receipt :receipt has been generated.', [
                    'receipt' => $transaction->receipt_no,
                ]),
            ]);
        }

        // Payment Failed or Aborted
        $transaction->update([
            'status' => PaymentTransaction::STATUS_FAILED,
            'gateway_tracking_id' => $trackingId ?: $transaction->gateway_tracking_id,
            'gateway_response' => $responseData,
            'admin_remarks' => $verification['message'] ?? 'Transaction failed at payment gateway',
        ]);

        return redirect($redirectRoute)->with('toast', [
            'type' => 'error',
            'message' => __('Payment failed or was declined: :reason', [
                'reason' => $verification['message'] ?? __('Unknown gateway decline'),
            ]),
        ]);
    }

    /**
     * Handle POST cancel callback when student clicks Cancel on CCAvenue.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $gateway = new CCAVenueGateway;
        $encResponse = $request->input('encResp') ?? $request->input('enc_response');

        $transaction = null;

        if (! empty($encResponse)) {
            $workingKey = $gateway->getWorkingKey();
            $decryptedString = $gateway->decrypt((string) $encResponse, $workingKey);
            parse_str($decryptedString, $responseData);

            $orderId = $responseData['order_id'] ?? null;
            $merchantParam4 = $responseData['merchant_param4'] ?? null;
            $merchantParam1 = $responseData['merchant_param1'] ?? null;

            if (! empty($merchantParam4)) {
                $transaction = PaymentTransaction::find((int) $merchantParam4);
            }
            if (! $transaction && ! empty($orderId)) {
                $transaction = PaymentTransaction::where('gateway_order_id', $orderId)->first();
            }
            if (! $transaction && ! empty($merchantParam1)) {
                $transaction = PaymentTransaction::where('transaction_no', $merchantParam1)->first();
            }

            if ($transaction && ! Auth::check() && $transaction->user) {
                Auth::login($transaction->user);
            }

            if ($transaction && $transaction->status === PaymentTransaction::STATUS_PENDING) {
                $transaction->update([
                    'status' => PaymentTransaction::STATUS_FAILED,
                    'gateway_response' => $responseData,
                    'admin_remarks' => __('Transaction cancelled by customer on CCAvenue checkout page.'),
                ]);
            }
        }

        $user = Auth::user() ?? $transaction?->user;
        $teamSlug = $user?->ensureCurrentTeam()?->slug ?? 'default';

        return redirect()->route('student.payments.index', ['current_team' => $teamSlug])->with('toast', [
            'type' => 'info',
            'message' => __('Payment was cancelled. No amount was debited from your account.'),
        ]);
    }

    protected function fallbackRedirect(string $message): RedirectResponse
    {
        if (Auth::check()) {
            $teamSlug = Auth::user()->ensureCurrentTeam()?->slug ?? 'default';

            return redirect()->route('student.payments.index', ['current_team' => $teamSlug])
                ->with('toast', ['type' => 'error', 'message' => $message]);
        }

        return redirect()->route('home')->with('error', $message);
    }
}
