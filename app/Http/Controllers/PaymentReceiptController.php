<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Services\Payment\ReceiptService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentReceiptController extends Controller
{
    /**
     * Download payment receipt PDF.
     */
    public function download(Request $request, PaymentTransaction|int|string $transaction): Response
    {
        $model = $transaction instanceof PaymentTransaction ? $transaction : PaymentTransaction::findOrFail($transaction);
        $user = $request->user();

        // Authorization check: student can only download their own receipt, or staff with permissions
        if ($user->id !== $model->user_id && ! $user->can('payments.view') && ! $user->hasRole('Super Administrator')) {
            abort(403, __('You are not authorized to view this receipt.'));
        }

        if ($model->status !== PaymentTransaction::STATUS_SUCCESSFUL) {
            abort(400, __('Receipt is only available for confirmed and cleared payments.'));
        }

        return ReceiptService::download($model);
    }
}
