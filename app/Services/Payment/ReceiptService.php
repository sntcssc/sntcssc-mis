<?php

namespace App\Services\Payment;

use App\Models\PaymentTransaction;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class ReceiptService
{
    /**
     * Generate printable PDF fee payment receipt.
     */
    public static function generatePdf(PaymentTransaction|int $transaction): \Barryvdh\DomPDF\PDF
    {
        $model = $transaction instanceof PaymentTransaction
            ? $transaction
            : PaymentTransaction::with(['user.studentProfile', 'invoice.enrollment.course', 'invoice.enrollment.batch', 'feeType', 'verifier'])
                ->findOrFail($transaction);

        if (! $model->relationLoaded('user')) {
            $model->load(['user.studentProfile', 'invoice.enrollment.course', 'invoice.enrollment.batch', 'feeType', 'verifier']);
        }

        $instituteName = Setting::get('general.institute_name', 'Satyendranath Tagore Civil Services Study Centre');
        $instituteAddress = Setting::get('general.institute_address', 'Administrative Building, Salt Lake, Sector V, Kolkata - 700091');
        $instituteContact = Setting::get('general.contact_email', 'accounts@sntcssc.in');

        $data = [
            'transaction' => $model,
            'user' => $model->user,
            'invoice' => $model->invoice,
            'instituteName' => $instituteName,
            'instituteAddress' => $instituteAddress,
            'instituteContact' => $instituteContact,
            'amountInWords' => static::numberToWords($model->amount),
            'generatedAt' => now(),
        ];

        return Pdf::loadView('pdf.fee-receipt', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true)
            ->setOption('enable_font_subsetting', true)
            ->setOption('defaultFont', 'DejaVu Sans');
    }

    /**
     * Download or stream PDF receipt.
     */
    public static function download(PaymentTransaction|int $transaction): Response
    {
        $pdf = static::generatePdf($transaction);
        $receiptNo = $transaction instanceof PaymentTransaction ? $transaction->receipt_no : "RCT-{$transaction}";
        $filename = ($receiptNo ?: 'receipt').'.pdf';

        return response()->streamDownload(
            function () use ($pdf) {
                echo $pdf->output();
            },
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]
        );
    }

    /**
     * Convert currency number into English words (Indian Rupees style).
     */
    public static function numberToWords(float $amount): string
    {
        $amount = round($amount, 2);
        $rupees = (int) $amount;
        $paise = (int) round(($amount - $rupees) * 100);

        $words = static::convertWholeNumber($rupees).' Rupees';
        if ($paise > 0) {
            $words .= ' and '.static::convertWholeNumber($paise).' Paise';
        }

        return $words.' Only';
    }

    private static function convertWholeNumber(int $num): string
    {
        if ($num === 0) {
            return 'Zero';
        }

        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        if ($num < 20) {
            return $ones[$num];
        }

        if ($num < 100) {
            return $tens[(int) ($num / 10)].($num % 10 !== 0 ? ' '.$ones[$num % 10] : '');
        }

        if ($num < 1000) {
            return $ones[(int) ($num / 100)].' Hundred'.($num % 100 !== 0 ? ' and '.static::convertWholeNumber($num % 100) : '');
        }

        if ($num < 100000) {
            return static::convertWholeNumber((int) ($num / 1000)).' Thousand'.($num % 1000 !== 0 ? ' '.static::convertWholeNumber($num % 1000) : '');
        }

        if ($num < 10000000) {
            return static::convertWholeNumber((int) ($num / 100000)).' Lakh'.($num % 100000 !== 0 ? ' '.static::convertWholeNumber($num % 100000) : '');
        }

        return static::convertWholeNumber((int) ($num / 10000000)).' Crore'.($num % 10000000 !== 0 ? ' '.static::convertWholeNumber($num % 10000000) : '');
    }
}
