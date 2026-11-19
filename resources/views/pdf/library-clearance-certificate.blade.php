<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ __('Library Clearance Certificate') }} - {{ $deposit->clearance_certificate_no ?: $deposit->deposit_no }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 20mm;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1e293b;
            background: #ffffff;
            margin: 0;
            padding: 0;
            line-height: 1.5;
            -webkit-print-color-adjust: exact;
        }
        .cert-container {
            border: 3px double #0f766e;
            padding: 24px;
            position: relative;
            background: #fafdfd;
        }
        .cert-inner-border {
            border: 1px solid #14b8a6;
            padding: 24px;
            background: #ffffff;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0f766e;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        .institute-name {
            font-size: 20px;
            font-weight: 800;
            color: #0f766e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 4px 0;
        }
        .institute-sub {
            font-size: 11px;
            color: #64748b;
            margin: 0 0 6px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .department-title {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin: 0;
        }
        .cert-badge {
            display: inline-block;
            background: #0f766e;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            padding: 6px 20px;
            border-radius: 4px;
            margin-top: 14px;
        }
        .meta-row {
            margin: 16px 0 20px 0;
            font-size: 12px;
        }
        .meta-left {
            float: left;
            width: 50%;
        }
        .meta-right {
            float: right;
            width: 50%;
            text-align: right;
        }
        .clear {
            clear: both;
        }
        .cert-body {
            margin: 24px 0;
            font-size: 13px;
            line-height: 1.8;
            color: #334155;
            text-align: justify;
        }
        .highlight {
            font-weight: 700;
            color: #0f766e;
        }
        .details-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 14px 18px;
            margin: 20px 0;
            font-size: 12px;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
        }
        .details-table td {
            padding: 5px 8px;
            vertical-align: top;
        }
        .details-table td.label {
            width: 32%;
            font-weight: 600;
            color: #475569;
        }
        .details-table td.val {
            font-weight: 700;
            color: #0f172a;
        }
        .verification-statement {
            background: #f0fdf4;
            border-left: 4px solid #16a34a;
            padding: 12px 16px;
            margin: 20px 0;
            font-size: 12px;
            color: #166534;
            font-weight: 600;
            line-height: 1.6;
        }
        .signatures {
            margin-top: 40px;
            padding-top: 10px;
        }
        .sig-col {
            float: left;
            width: 50%;
            text-align: center;
        }
        .sig-line {
            width: 180px;
            border-top: 1px solid #334155;
            margin: 40px auto 6px auto;
        }
        .sig-title {
            font-size: 11px;
            font-weight: 700;
            color: #1e293b;
            text-transform: uppercase;
        }
        .sig-sub {
            font-size: 10px;
            color: #64748b;
        }
        .footer-note {
            margin-top: 30px;
            padding-top: 12px;
            border-top: 1px dashed #cbd5e1;
            font-size: 10px;
            color: #94a3b8;
            text-align: center;
        }
        .qr-section {
            float: right;
            text-align: center;
        }
        .qr-section img {
            width: 75px;
            height: 75px;
        }
    </style>
</head>
<body>
    <div class="cert-container">
        <div class="cert-inner-border">
            <div class="header">
                <h1 class="institute-name">{{ $instituteName }}</h1>
                <p class="institute-sub">{{ $instituteAddress }}</p>
                <h2 class="department-title">{{ __('CENTRAL LIBRARY & LEARNING RESOURCE CENTRE') }}</h2>
                <div class="cert-badge">{{ __('Official Library Clearance & No-Dues Certificate') }}</div>
            </div>

            <div class="meta-row">
                <div class="meta-left">
                    <strong>{{ __('Certificate No') }}:</strong> <span class="highlight">{{ $deposit->clearance_certificate_no ?: $deposit->deposit_no }}</span><br>
                    <strong>{{ __('Issue Date') }}:</strong> {{ $deposit->cleared_at ? $deposit->cleared_at->format('d F Y') : now()->format('d F Y') }}
                </div>
                <div class="meta-right">
                    @if(!empty($qrDataUri))
                        <div class="qr-section">
                            <img src="{{ $qrDataUri }}" alt="QR Code Verification">
                            <div style="font-size: 8px; color: #64748b; margin-top: 2px;">{{ __('Scan to Verify') }}</div>
                        </div>
                    @endif
                    <div style="float: right; margin-right: 15px; text-align: right;">
                        <strong>{{ __('Deposit Reference') }}:</strong> {{ $deposit->deposit_no }}<br>
                        <strong>{{ __('Status') }}:</strong> <span style="color: #16a34a; font-weight: 700;">{{ __('CLEARED') }}</span>
                    </div>
                    <div class="clear"></div>
                </div>
                <div class="clear"></div>
            </div>

            <div class="cert-body">
                {{ __('This is to officially certify that the student / member specified below has completed all bibliographic requirements, returned all books, serials, and academic materials in satisfactory condition, and has zero outstanding overdue fines or library liabilities.') }}
            </div>

            <div class="details-box">
                <table class="details-table">
                    <tr>
                        <td class="label">{{ __('Candidate Name') }}:</td>
                        <td class="val">{{ $user->name }}</td>
                        <td class="label">{{ __('Member ID / No') }}:</td>
                        <td class="val">{{ $member?->member_number ?? 'LIB-'.str_pad((string)$user->id, 5, '0', STR_PAD_LEFT) }}</td>
                    </tr>
                    <tr>
                        <td class="label">{{ __('Student ID / Roll') }}:</td>
                        <td class="val">{{ $user->student_id ?: 'N/A' }}</td>
                        <td class="label">{{ __('Email Address') }}:</td>
                        <td class="val">{{ $user->email }}</td>
                    </tr>
                    <tr>
                        <td class="label">{{ __('Security Deposit No') }}:</td>
                        <td class="val">{{ $deposit->deposit_no }}</td>
                        <td class="label">{{ __('Deposit Amount') }}:</td>
                        <td class="val">₹{{ number_format($deposit->amount, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="label">{{ __('Deposit Date') }}:</td>
                        <td class="val">{{ $deposit->deposit_date ? $deposit->deposit_date->format('d M Y') : 'N/A' }}</td>
                        <td class="label">{{ __('Books Outstanding') }}:</td>
                        <td class="val" style="color: #16a34a;">{{ __('0 (Zero) Books on Loan') }}</td>
                    </tr>
                    @if($deposit->clearance_remarks)
                    <tr>
                        <td class="label">{{ __('Librarian Remarks') }}:</td>
                        <td class="val" colspan="3" style="font-weight: 500; font-style: italic;">{{ $deposit->clearance_remarks }}</td>
                    </tr>
                    @endif
                </table>
            </div>

            <div class="verification-statement">
                ✓ <strong>{{ __('CLEARANCE VERIFICATION COMPLETED') }}</strong><br>
                {{ __('The Central Library hereby issues clearance for the refund/release of the security deposit amount of') }}
                <strong>₹{{ number_format($deposit->amount, 2) }}</strong>.
                {{ __('The Administration and Accounts division is authorized to proceed with the security deposit refund approval.') }}
            </div>

            <div class="signatures">
                <div class="sig-col">
                    @if(!empty($barcodeDataUri))
                        <img src="{{ $barcodeDataUri }}" alt="Barcode" style="height: 30px; margin-bottom: 5px;"><br>
                    @endif
                    <span style="font-size: 10px; color: #64748b;">{{ __('Ref') }}: {{ $deposit->clearance_certificate_no ?: $deposit->deposit_no }}</span>
                    <div class="sig-line"></div>
                    <div class="sig-title">{{ __('Verified & Issued By') }}</div>
                    <div class="sig-sub">{{ $clearedBy ? $clearedBy->name : __('Librarian / In-Charge') }}</div>
                    <div class="sig-sub">{{ __('Central Library Services') }}</div>
                </div>

                <div class="sig-col">
                    <div style="height: 45px;"></div>
                    <div class="sig-line"></div>
                    <div class="sig-title">{{ __('Administrative Officer') }}</div>
                    <div class="sig-sub">{{ __('Accounts & Finance Division') }}</div>
                    <div class="sig-sub">{{ $instituteName }}</div>
                </div>
                <div class="clear"></div>
            </div>

            <div class="footer-note">
                {{ __('This document is an official computer-generated Library Clearance Certificate issued on :date. Authenticity can be verified at Central Library Desk.', ['date' => $generatedAt]) }}
            </div>
        </div>
    </div>
</body>
</html>
