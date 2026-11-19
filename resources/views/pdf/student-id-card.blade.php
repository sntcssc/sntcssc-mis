<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ __('Student Identity Cards') }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #0f172a;
            margin: 0;
            padding: 0;
            font-size: 10px;
            background-color: #ffffff;
        }
        .sheet-header {
            text-align: center;
            margin-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 8px;
        }
        .sheet-title {
            font-size: 15px;
            font-weight: bold;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin: 0 0 3px 0;
        }
        .sheet-sub {
            font-size: 9px;
            color: #64748b;
            margin: 0;
        }
        .card-wrapper {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        .card-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 12px 0;
        }
        .card-box {
            width: 50%;
            border: 2px solid #1e3a8a;
            border-radius: 12px;
            background-color: #ffffff;
            vertical-align: top;
            padding: 0;
            overflow: hidden;
        }
        .card-header-bar {
            background-color: #1e3a8a;
            color: #ffffff;
            padding: 9px 12px;
            text-align: center;
        }
        .card-header-inst {
            font-size: 9.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }
        .card-header-tag {
            font-size: 8px;
            color: #93c5fd;
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: bold;
        }
        .card-body {
            padding: 10px 12px;
        }
        .photo-table {
            width: 100%;
            margin-bottom: 8px;
        }
        .photo-cell {
            width: 75px;
            vertical-align: top;
        }
        .photo-box {
            width: 70px;
            height: 85px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background-color: #f8fafc;
            text-align: center;
            overflow: hidden;
            display: block;
        }
        .photo-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .details-cell {
            vertical-align: top;
            padding-left: 10px;
        }
        .student-name {
            font-size: 13px;
            font-weight: bold;
            color: #1e3a8a;
            margin-bottom: 3px;
            line-height: 1.2;
        }
        .student-id-tag {
            display: inline-block;
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            font-size: 8.5px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 4px;
            margin-bottom: 5px;
            font-family: monospace;
        }
        .info-row {
            font-size: 8.5px;
            color: #334155;
            margin-bottom: 2.5px;
            line-height: 1.25;
        }
        .info-label {
            font-weight: bold;
            color: #64748b;
            display: inline-block;
            width: 78px;
        }
        .highlight-text {
            color: #0f172a;
            font-weight: 600;
        }
        .barcode-section {
            text-align: center;
            border-top: 1px dashed #cbd5e1;
            padding-top: 6px;
            margin-top: 6px;
        }
        .barcode-img {
            max-width: 170px;
            height: 32px;
        }
        .barcode-code {
            font-family: monospace;
            font-size: 8px;
            color: #64748b;
            margin-top: 2px;
            letter-spacing: 1px;
        }

        /* BACK SIDE STYLING */
        .back-header {
            background-color: #0f172a;
            color: #ffffff;
            padding: 8px 12px;
            text-align: center;
        }
        .back-title {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .back-body {
            padding: 10px 12px;
        }
        .terms-title {
            font-size: 8.5px;
            font-weight: bold;
            text-transform: uppercase;
            color: #1e3a8a;
            margin-bottom: 5px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 2px;
        }
        .terms-list {
            margin: 0;
            padding-left: 14px;
            font-size: 7.5px;
            line-height: 1.35;
            color: #475569;
        }
        .terms-list li {
            margin-bottom: 3.5px;
        }
        .back-footer-table {
            width: 100%;
            margin-top: 8px;
            border-top: 1px solid #e2e8f0;
            padding-top: 6px;
        }
        .qr-cell {
            width: 60px;
            vertical-align: bottom;
        }
        .qr-img {
            width: 55px;
            height: 55px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 2px;
        }
        .sign-cell {
            vertical-align: bottom;
            text-align: right;
            padding-right: 4px;
        }
        .sign-title {
            font-size: 7.5px;
            font-weight: bold;
            color: #1e3a8a;
            border-top: 1px solid #94a3b8;
            display: inline-block;
            padding-top: 2px;
            margin-top: 25px;
        }
        .cut-mark-notice {
            font-size: 7.5px;
            color: #94a3b8;
            text-align: center;
            margin-top: 4px;
            font-style: italic;
        }
    </style>
</head>
<body>

    @foreach($cards as $index => $c)
        <div class="card-wrapper" @if(!$loop->last) style="page-break-after: {{ ($index + 1) % 2 === 0 ? 'always' : 'auto' }};" @endif>
            @if($loop->first || ($index % 2 === 0))
                <div class="sheet-header">
                    <h1 class="sheet-title">{{ $c['settings']['institution_name'] }}</h1>
                    <p class="sheet-sub">{{ $c['settings']['institution_sub'] }} &bull; Official Student Identification & Dossier</p>
                </div>
            @endif

            <table class="card-table">
                <tr>
                    <!-- FRONT SIDE OF CARD -->
                    <td class="card-box">
                        <div class="card-header-bar">
                            <div class="card-header-inst">{{ $c['settings']['institution_name'] }}</div>
                            <div class="card-header-tag">{{ $c['settings']['card_title'] }}</div>
                        </div>

                        <div class="card-body">
                            <table class="photo-table">
                                <tr>
                                    <td class="photo-cell">
                                        <div class="photo-box">
                                            @if($c['photo_url'])
                                                <img src="{{ $c['photo_url'] }}" alt="{{ $c['name'] }}" class="photo-img"/>
                                            @else
                                                <div style="padding-top: 26px; color: #94a3b8; font-size: 9px; font-weight: bold;">
                                                    PHOTO
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="details-cell">
                                        <div class="student-name">{{ $c['name'] }}</div>
                                        <span class="student-id-tag">ID: {{ $c['student_id'] }}</span>

                                        <div class="info-row">
                                            <span class="info-label">{{ __('Course:') }}</span>
                                            <span class="highlight-text">{{ $c['course'] }}</span>
                                        </div>

                                        <div class="info-row">
                                            <span class="info-label">{{ __('Batch / Sec:') }}</span>
                                            <span>{{ $c['batch'] }} ({{ $c['section'] }})</span>
                                        </div>

                                        <div class="info-row">
                                            <span class="info-label">{{ __('Roll Number:') }}</span>
                                            <span class="highlight-text">{{ $c['roll_no'] }}</span>
                                        </div>

                                        @if($c['settings']['show_optional_subject'])
                                            <div class="info-row">
                                                <span class="info-label">{{ __('Optional Subject:') }}</span>
                                                <span class="highlight-text" style="color: #1e3a8a;">{{ $c['optional_subject'] }}</span>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            </table>

                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 4px 6px; margin-bottom: 6px;">
                                <table style="width: 100%;">
                                    <tr>
                                        @if($c['settings']['show_blood_group'])
                                            <td style="font-size: 8px;">
                                                <strong style="color: #64748b;">Blood:</strong> {{ $c['blood_group'] }}
                                            </td>
                                        @endif
                                        <td style="font-size: 8px;">
                                            <strong style="color: #64748b;">DOB:</strong> {{ $c['dob'] }}
                                        </td>
                                        <td style="font-size: 8px; text-align: right;">
                                            <strong style="color: #64748b;">Valid:</strong> {{ $c['settings']['validity_period'] }}
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            @if($c['settings']['show_barcode'])
                                <div class="barcode-section">
                                    <img src="{{ $c['barcode_uri'] }}" class="barcode-img" alt="Barcode"/>
                                    <div class="barcode-code">{{ $c['student_id'] }}</div>
                                </div>
                            @endif
                        </div>
                    </td>

                    <!-- BACK SIDE OF CARD -->
                    <td class="card-box">
                        <div class="back-header">
                            <div class="back-title">{{ __('RULES & EMERGENCY CONTACTS') }}</div>
                        </div>

                        <div class="back-body">
                            <div class="terms-title">{{ __('Instructions & Conditions') }}</div>
                            <ol class="terms-list">
                                @foreach($c['terms'] as $term)
                                    <li>{{ $term }}</li>
                                @endforeach
                            </ol>

                            @if($c['settings']['show_emergency_contact'])
                                <div style="margin-top: 6px; padding: 4px 6px; background-color: #fef2f2; border: 1px solid #fee2e2; border-radius: 5px; font-size: 7.5px; color: #991b1b;">
                                    <strong>Emergency / Contact Helpline:</strong> {{ $c['emergency_contact'] }} &bull; {{ $c['email'] }}
                                </div>
                            @endif

                            <table class="back-footer-table">
                                <tr>
                                    @if($c['settings']['show_qr_code'])
                                        <td class="qr-cell">
                                            <img src="{{ $c['qr_uri'] }}" class="qr-img" alt="Verification QR Code"/>
                                        </td>
                                    @endif
                                    <td class="sign-cell">
                                        <div style="font-size: 7px; color: #64748b; margin-bottom: 1px;">
                                            Digitally Verified &bull; SNTCSSC MIS
                                        </div>
                                        <div class="sign-title">
                                            {{ $c['settings']['authorized_signatory_title'] }}
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="cut-mark-notice">
                &mdash; Cut along outer border and fold in middle or laminate as double-sided badge (ISO/IEC 7810 ID-1 standard) &mdash;
            </div>
        </div>
    @endforeach

</body>
</html>

