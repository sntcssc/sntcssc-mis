<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ __('Library Card') }} - {{ $member_number }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #0f172a;
            margin: 0;
            padding: 0;
            font-size: 11px;
            background-color: #ffffff;
        }
        .rupee {
            font-family: DejaVu Sans, sans-serif;
        }
        .sheet-header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 12px;
        }
        .sheet-title {
            font-size: 16px;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 4px 0;
        }
        .sheet-sub {
            font-size: 10px;
            color: #64748b;
            margin: 0;
        }
        .card-container {
            width: 100%;
            margin-bottom: 25px;
        }
        .card-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 15px 0;
        }
        .card-box {
            width: 50%;
            border: 2px solid #0f172a;
            border-radius: 10px;
            background-color: #ffffff;
            vertical-align: top;
            padding: 0;
            overflow: hidden;
        }
        .card-header-bar {
            background-color: #0f172a;
            color: #ffffff;
            padding: 10px 14px;
            text-align: center;
        }
        .card-header-inst {
            font-size: 10.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }
        .card-header-tag {
            font-size: 8.5px;
            color: #38bdf8;
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        .card-body {
            padding: 12px 14px;
        }
        .photo-table {
            width: 100%;
            margin-bottom: 10px;
        }
        .photo-cell {
            width: 80px;
            vertical-align: top;
        }
        .photo-box {
            width: 72px;
            height: 86px;
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
        .member-name {
            font-size: 13px;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 4px;
            line-height: 1.2;
        }
        .member-id-tag {
            display: inline-block;
            background-color: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #0f172a;
            font-size: 9px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 4px;
            margin-bottom: 6px;
            font-family: monospace;
        }
        .info-row {
            font-size: 9.5px;
            color: #475569;
            margin-bottom: 3px;
        }
        .info-label {
            font-weight: 600;
            color: #64748b;
            display: inline-block;
            width: 70px;
        }
        .barcode-section {
            text-align: center;
            border-top: 1px dashed #cbd5e1;
            padding-top: 8px;
            margin-top: 8px;
        }
        .barcode-img {
            max-width: 180px;
            height: 38px;
        }
        .card-footer {
            background-color: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 6px 14px;
            font-size: 8px;
            color: #64748b;
            text-align: center;
        }
        .rules-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #0f172a;
            margin-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 3px;
        }
        .rules-list {
            margin: 0 0 10px 0;
            padding-left: 14px;
            font-size: 8.5px;
            color: #475569;
            line-height: 1.4;
        }
        .rules-list li {
            margin-bottom: 3px;
        }
        .deposit-highlight {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 5px;
            padding: 6px 8px;
            margin-bottom: 8px;
        }
        .deposit-highlight-label {
            font-size: 8.5px;
            color: #166534;
            font-weight: 600;
            text-transform: uppercase;
        }
        .deposit-highlight-value {
            font-size: 13px;
            font-weight: bold;
            color: #15803d;
            margin-top: 2px;
        }
        .issued-books-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
            margin-top: 6px;
        }
        .issued-books-table th {
            background-color: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding: 3px 5px;
            text-align: left;
            font-weight: bold;
            color: #334155;
        }
        .issued-books-table td {
            border: 1px solid #e2e8f0;
            padding: 3px 5px;
            color: #475569;
        }
        .qr-cell {
            text-align: center;
            vertical-align: middle;
            width: 70px;
        }
        .qr-img {
            width: 65px;
            height: 65px;
        }
        .sign-table {
            width: 100%;
            margin-top: 10px;
        }
        .sign-cell {
            text-align: center;
            vertical-align: bottom;
            font-size: 8px;
            color: #64748b;
        }
        .sign-line {
            border-top: 1px solid #94a3b8;
            width: 80%;
            margin: 0 auto 3px auto;
        }
    </style>
</head>
<body>

    <div class="sheet-header">
        <h1 class="sheet-title">{{ $institution_name }}</h1>
        <p class="sheet-sub">{{ $institution_sub }} &bull; {{ __('Central Library Services & Digital Access Portal') }}</p>
    </div>

    <div class="card-container">
        <table class="card-table">
            <tr>
                <!-- FRONT OF CARD -->
                <td class="card-box">
                    <div class="card-header-bar">
                        <div class="card-header-inst">{{ $institution_name }}</div>
                        <div class="card-header-tag">{{ __('Official Digital Library Card') }}</div>
                    </div>

                    <div class="card-body">
                        <table class="photo-table">
                            <tr>
                                <td class="photo-cell">
                                    <div class="photo-box">
                                        @if(!empty($photo_url))
                                            <img src="{{ $photo_url }}" class="photo-img" alt="Photo" />
                                        @else
                                            <div style="padding-top: 26px; color: #94a3b8; font-size: 9px; font-weight: bold;">
                                                PHOTO
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td class="details-cell">
                                    <div class="member-name">{{ $name }}</div>
                                    <div class="member-id-tag">{{ $member_number }}</div>

                                    <div class="info-row">
                                        <span class="info-label">{{ __('Student/ID') }}:</span>
                                        <strong>{{ $student_id }}</strong>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">{{ __('Category') }}:</span>
                                        {{ $member_type }}
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">{{ __('Valid Till') }}:</span>
                                        <strong>{{ $expiry_date }}</strong>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <div class="barcode-section">
                            <img src="{{ $barcode_uri }}" class="barcode-img" alt="Barcode" />
                            <div style="font-family: monospace; font-size: 9px; font-weight: bold; margin-top: 2px; color: #334155;">
                                {{ $member_number }}
                            </div>
                        </div>

                        <table class="sign-table">
                            <tr>
                                <td class="sign-cell" style="width: 50%;">
                                    <div class="sign-line"></div>
                                    {{ __('Cardholder Signature') }}
                                </td>
                                <td class="sign-cell" style="width: 50%;">
                                    <div class="sign-line"></div>
                                    {{ __('Librarian / Authority') }}
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="card-footer">
                        {{ __('This card is strictly non-transferable. Loss must be reported immediately.') }}
                    </div>
                </td>

                <!-- BACK OF CARD -->
                <td class="card-box">
                    <div class="card-header-bar" style="background-color: #1e293b;">
                        <div class="card-header-inst">{{ __('Library Regulations & Verification') }}</div>
                        <div class="card-header-tag" style="color: #cbd5e1;">{{ __('Barcode / QR Scanner Verification') }}</div>
                    </div>

                    <div class="card-body">
                        <table style="width: 100%; margin-bottom: 8px;">
                            <tr>
                                <td style="vertical-align: top;">
                                    <div class="rules-title">{{ __('Borrowing Rules') }}</div>
                                    <ul class="rules-list">
                                        <li>{{ __('Standard book borrowing timeline is maximum 15 days.') }}</li>
                                        <li>{{ __('Books must be returned or renewed on or before the due date.') }}</li>
                                        <li>{{ __('Overdue penalties apply on unreturned items past 15 days.') }}</li>
                                        <li>{{ __('Borrower is responsible for physical damage or loss of books.') }}</li>
                                    </ul>
                                </td>
                                <td class="qr-cell">
                                    <img src="{{ $qr_uri }}" class="qr-img" alt="QR Code" />
                                    <div style="font-size: 7.5px; color: #64748b; margin-top: 2px;">{{ __('Scan to Verify') }}</div>
                                </td>
                            </tr>
                        </table>

                        {{-- OPTIONAL ENTRY: SECURITY DEPOSIT DETAILS --}}
                        @if($with_deposit_details)
                            <div class="deposit-highlight">
                                <div class="deposit-highlight-label">{{ __('Library Security Deposit Record') }}</div>
                                <div class="deposit-highlight-value">
                                    <span class="rupee">&#8377;</span>{{ number_format($security_deposit_amount, 2) }}
                                    <span style="font-size: 9px; font-weight: normal; color: #166534; margin-left: 6px;">
                                        ({{ __('Refundable Deposit Balance') }})
                                    </span>
                                </div>
                            </div>
                        @endif

                        {{-- OPTIONAL ENTRY: CURRENTLY ISSUED BOOKS DETAILS --}}
                        @if($with_issued_books && !empty($issued_books))
                            <div style="margin-top: 6px;">
                                <div style="font-size: 8.5px; font-weight: bold; color: #0f172a; text-transform: uppercase;">
                                    {{ __('Active Book Borrowing Register') }} ({{ count($issued_books) }})
                                </div>
                                <table class="issued-books-table">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Accession') }}</th>
                                            <th>{{ __('Book Title') }}</th>
                                            <th>{{ __('Due Date') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($issued_books as $book)
                                            <tr>
                                                <td style="font-family: monospace;">{{ $book['accession_no'] }}</td>
                                                <td>{{ \Illuminate\Support\Str::limit($book['title'], 28) }}</td>
                                                <td>{{ $book['due_date'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @elseif($with_issued_books)
                            <div style="font-size: 8.5px; color: #64748b; font-style: italic; margin-top: 4px;">
                                {{ __('No books currently issued on this card.') }}
                            </div>
                        @endif
                    </div>

                    <div class="card-footer">
                        {{ __('Issued by Central Library, SNTCSSC. Web: sntcssc.in') }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div style="margin-top: 30px; text-align: center; color: #94a3b8; font-size: 9px; border-top: 1px solid #f1f5f9; padding-top: 10px;">
        {{ __('Printed on :date via SNTCSSC MIS Library Module. Valid across all central reading rooms and book stacks.', ['date' => now()->format('d M Y, h:i A')]) }}
    </div>

</body>
</html>
