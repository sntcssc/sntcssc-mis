<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ __('Official Fee Payment Receipt') }} - {{ $transaction->receipt_no ?: $transaction->transaction_no }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm 12mm 8mm 12mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 0;
            font-size: 11px;
            line-height: 1.35;
        }
        .rupee {
            font-family: DejaVu Sans, sans-serif;
            font-weight: normal;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        .institute-title {
            font-size: 16px;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
            line-height: 1.2;
        }
        .institute-sub {
            font-size: 9.5px;
            color: #64748b;
            margin-top: 2px;
        }
        .receipt-badge {
            display: inline-block;
            background-color: #0f172a;
            color: #ffffff;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.8px;
            padding: 2px 10px;
            border-radius: 3px;
            margin-top: 4px;
            text-transform: uppercase;
        }
        .meta-table {
            width: 100%;
            margin-bottom: 8px;
            border-collapse: collapse;
        }
        .meta-table td {
            vertical-align: top;
            padding: 2px 4px;
        }
        .label {
            font-size: 9px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .value {
            font-size: 11.5px;
            font-weight: 600;
            color: #0f172a;
        }
        .info-box {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 6px 8px;
            margin-bottom: 8px;
            background-color: #f8fafc;
        }
        .info-grid {
            width: 100%;
            border-collapse: collapse;
        }
        .info-grid td {
            padding: 2px 4px;
            font-size: 10.5px;
            line-height: 1.25;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .items-table th {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: 700;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 5px 8px;
            border-top: 1px solid #cbd5e1;
            border-bottom: 1.5px solid #cbd5e1;
            text-align: left;
        }
        .items-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 10.5px;
            vertical-align: top;
        }
        .amount-col {
            text-align: right;
        }
        .total-box {
            width: 100%;
            margin-bottom: 8px;
        }
        .total-box td {
            padding: 3px 6px;
        }
        .grand-total {
            background-color: #0f172a;
            color: #ffffff;
            font-weight: bold;
            font-size: 12px;
        }
        .words-section {
            background-color: #f8fafc;
            border-left: 3px solid #0f172a;
            padding: 5px 8px;
            font-size: 10px;
            line-height: 1.3;
        }
        .footer-table {
            width: 100%;
            margin-top: 10px;
            border-collapse: collapse;
        }
        .footer-table td {
            vertical-align: bottom;
            font-size: 9px;
            color: #64748b;
            line-height: 1.3;
        }
        .signature-line {
            border-top: 1px dashed #94a3b8;
            padding-top: 4px;
            text-align: center;
            font-weight: bold;
            color: #334155;
            font-size: 9.5px;
        }
        .watermark {
            position: fixed;
            top: 36%;
            left: 18%;
            font-size: 55px;
            color: rgba(15, 23, 42, 0.035);
            transform: rotate(-30deg);
            z-index: -1000;
            font-weight: bold;
            letter-spacing: 3px;
        }
    </style>
</head>
<body>
    <div class="watermark">{{ __('OFFICIAL RECEIPT') }}</div>

    <div class="header">
        <h1 class="institute-title">{{ $instituteName }}</h1>
        <div class="institute-sub">{{ $instituteAddress }} | {{ $instituteContact }}</div>
        <div class="receipt-badge">{{ __('E-Receipt / Money Receipt') }}</div>
    </div>

    <table class="meta-table">
        <tr>
            <td style="width: 50%;">
                <div class="label">{{ __('Receipt Number') }}</div>
                <div class="value" style="font-size: 13.5px; color: #0284c7;">{{ $transaction->receipt_no ?: $transaction->transaction_no }}</div>
                <div class="label" style="margin-top: 4px;">{{ __('Receipt Date') }}</div>
                <div class="value" style="font-size: 11px;">{{ ($transaction->receipt_generated_at ?: $transaction->created_at)->format('d F Y, h:i A') }}</div>
            </td>
            <td style="width: 50%; text-align: right;">
                <div class="label">{{ __('Transaction Reference') }}</div>
                <div class="value" style="font-size: 11.5px;">{{ $transaction->transaction_no }}</div>
                @if($transaction->is_offline && $transaction->offline_reference_id)
                    <div class="label" style="margin-top: 4px;">{{ __('Bank Ref / UTR / Chq No') }}</div>
                    <div class="value" style="font-size: 11px;">{{ $transaction->offline_reference_id }}</div>
                @elseif($transaction->gateway_payment_id)
                    <div class="label" style="margin-top: 4px;">{{ __('Gateway Payment ID') }}</div>
                    <div class="value" style="font-size: 11px;">{{ $transaction->gateway_payment_id }}</div>
                @endif
                <div class="label" style="margin-top: 4px;">{{ __('Payment Status') }}</div>
                <div class="value" style="color: #16a34a; text-transform: uppercase; font-size: 11px;">{{ __('Cleared / Successful') }}</div>
            </td>
        </tr>
    </table>

    <div class="info-box">
        <table class="info-grid">
            <tr>
                <td style="width: 18%;" class="label">{{ __('Student Name:') }}</td>
                <td style="width: 32%;" class="value">{{ $user->name }}</td>
                <td style="width: 18%;" class="label">{{ __('Student ID:') }}</td>
                <td style="width: 32%;" class="value">{{ $transaction->student_id ?: $user->student_id ?: 'N/A' }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('Email Address:') }}</td>
                <td class="value">{{ $user->email }}</td>
                <td class="label">{{ __('Mobile Phone:') }}</td>
                <td class="value">{{ $user->phone ?: 'N/A' }}</td>
            </tr>
            @if($invoice && $invoice->enrollment)
                <tr>
                    <td class="label">{{ __('Enrolled Course:') }}</td>
                    <td class="value">{{ $invoice->enrollment->course?->name }} ({{ $invoice->enrollment->course?->code }})</td>
                    <td class="label">{{ __('Academic Batch:') }}</td>
                    <td class="value">{{ $invoice->enrollment->batch?->name }}</td>
                </tr>
            @endif
            <tr>
                <td class="label">{{ __('Payment Gateway:') }}</td>
                <td class="value" style="text-transform: capitalize;">{{ $transaction->gateway }}</td>
                <td class="label">{{ __('Payment Method:') }}</td>
                <td class="value" style="text-transform: uppercase;">{{ str_replace('_', ' ', $transaction->payment_method) }}</td>
            </tr>
        </table>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 55%;">{{ __('Fee Description / Particulars') }}</th>
                <th style="width: 20%;">{{ __('Category / Head') }}</th>
                <th style="width: 20%;" class="amount-col">{{ __('Amount Paid (INR)') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>
                    <strong>{{ $invoice?->title ?: $transaction->feeType?->name ?: __('Academic Institutional Fee') }}</strong>
                    @if($invoice && $invoice->period_month)
                        <div style="font-size: 9.5px; color: #64748b; margin-top: 1px;">
                            {{ __('Billing Month:') }} {{ \Carbon\Carbon::create(null, $invoice->period_month, 1)->format('F') }} {{ $invoice->period_year }}
                        </div>
                    @endif
                    @if($transaction->feeType && $transaction->feeType->is_refundable)
                        <div style="font-size: 9.5px; color: #0284c7; margin-top: 1px;">
                            * {{ __('Fully Refundable Security Deposit') }}
                        </div>
                    @endif
                </td>
                <td>{{ $transaction->feeType?->category ? ucfirst($transaction->feeType->category) : __('Academic') }}</td>
                <td class="amount-col"><span class="rupee">&#8377;</span>{{ number_format($transaction->amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
        <tr>
            <td style="width: 58%; vertical-align: top;">
                <div class="words-section">
                    <div style="font-size: 9px; font-weight: bold; text-transform: uppercase; color: #64748b;">{{ __('Amount in Words:') }}</div>
                    <div style="font-weight: 600; color: #0f172a; margin-top: 1px;">{{ $amountInWords }}</div>
                </div>
            </td>
            <td style="width: 42%; vertical-align: top;">
                <table style="width: 100%; border-collapse: collapse; text-align: right;">
                    <tr>
                        <td style="padding: 2px 6px; color: #64748b; font-size: 10px;">{{ __('Subtotal:') }}</td>
                        <td style="padding: 2px 6px; font-weight: 600; font-size: 10.5px;"><span class="rupee">&#8377;</span>{{ number_format($transaction->amount, 2) }}</td>
                    </tr>
                    @if($invoice && (float) $invoice->discount_amount > 0)
                        <tr>
                            <td style="padding: 2px 6px; color: #16a34a; font-size: 10px;">{{ __('Fee Concession:') }}</td>
                            <td style="padding: 2px 6px; color: #16a34a; font-size: 10.5px;">- <span class="rupee">&#8377;</span>{{ number_format($invoice->discount_amount, 2) }}</td>
                        </tr>
                    @endif
                    @if($invoice && (float) $invoice->late_fee_amount > 0)
                        <tr>
                            <td style="padding: 2px 6px; color: #dc2626; font-size: 10px;">{{ __('Late Charge:') }}</td>
                            <td style="padding: 2px 6px; color: #dc2626; font-size: 10.5px;">+ <span class="rupee">&#8377;</span>{{ number_format($invoice->late_fee_amount, 2) }}</td>
                        </tr>
                    @endif
                    <tr class="grand-total">
                        <td style="padding: 5px 8px; color: #ffffff; font-size: 11px;">{{ __('Total Received:') }}</td>
                        <td style="padding: 5px 8px; color: #ffffff; font-size: 12.5px;"><span class="rupee">&#8377;</span>{{ number_format($transaction->amount, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="footer-table">
        <tr>
            <td style="width: 62%;">
                <div style="font-weight: bold; color: #0f172a; margin-bottom: 1px; font-size: 9.5px;">{{ __('Terms & Conditions') }}</div>
                <div>1. {{ __('Fees once paid are non-transferable and non-refundable except for refundable security deposits.') }}</div>
                <div>2. {{ __('This computer-generated e-receipt is verifiable in the institute portal under your student dossier.') }}</div>
                @if($transaction->verifier)
                    <div style="margin-top: 2px;">{{ __('Verified by Staff:') }} {{ $transaction->verifier->name }} ({{ $transaction->verified_at?->format('d/m/Y') }})</div>
                @endif
            </td>
            <td style="width: 38%; text-align: center;">
                <div style="height: 25px;"></div>
                <div class="signature-line">{{ __('Authorized Accounts Signatory') }}<br><span style="font-size: 8.5px; font-weight: normal; color: #64748b;">{{ $instituteName }}</span></div>
            </td>
        </tr>
    </table>
</body>
</html>
