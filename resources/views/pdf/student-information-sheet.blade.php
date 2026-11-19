<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ __('Student Detailed Information Sheet') }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm 10mm 10mm 10mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #0f172a;
            margin: 0;
            padding: 0;
            font-size: 8.5px;
            line-height: 1.3;
            background-color: #ffffff;
        }
        .sheet-container {
            width: 100%;
            page-break-after: always;
        }
        .sheet-container:last-child {
            page-break-after: auto;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 5px;
            margin-bottom: 8px;
        }
        .inst-title {
            font-size: 13px;
            font-weight: bold;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
            line-height: 1.2;
        }
        .inst-sub {
            font-size: 8px;
            color: #475569;
            margin-top: 1px;
            margin-bottom: 3px;
        }
        .doc-badge {
            display: inline-block;
            background-color: #1e3a8a;
            color: #ffffff;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.8px;
            padding: 2.5px 10px;
            border-radius: 2px;
            text-transform: uppercase;
        }
        .meta-bar {
            width: 100%;
            margin-bottom: 6px;
            font-size: 8px;
            color: #475569;
        }
        .meta-bar td {
            padding: 1px 0;
        }
        .section-title {
            background-color: #f1f5f9;
            border-left: 3px solid #1e3a8a;
            padding: 2.5px 6px;
            font-weight: bold;
            font-size: 8.5px;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 6px;
            margin-bottom: 3px;
        }
        .table-profile {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }
        .table-profile td {
            border: 1px solid #cbd5e1;
            padding: 3px 5px;
            vertical-align: middle;
            font-size: 8px;
        }
        .table-profile .lbl {
            width: 18%;
            background-color: #f8fafc;
            color: #475569;
            font-weight: bold;
        }
        .table-profile .val {
            width: 32%;
            color: #0f172a;
        }
        .photo-cell {
            width: 100px;
            vertical-align: middle;
            text-align: center;
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 4px;
        }
        .photo-box {
            width: 90px;
            height: 105px;
            margin: 0 auto;
            border: 1px solid #94a3b8;
            background-color: #ffffff;
            overflow: hidden;
            text-align: center;
        }
        .photo-img {
            width: 90px;
            height: 105px;
            object-fit: cover;
        }
        .photo-placeholder {
            padding-top: 35px;
            color: #94a3b8;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }
        .data-table th {
            background-color: #e2e8f0;
            color: #1e293b;
            font-weight: bold;
            font-size: 7.5px;
            text-transform: uppercase;
            padding: 3px 5px;
            border: 1px solid #cbd5e1;
            text-align: left;
        }
        .data-table td {
            border: 1px solid #cbd5e1;
            padding: 3px 5px;
            font-size: 7.5px;
        }
        .data-table tr:nth-child(even) td {
            background-color: #f8fafc;
        }
        .badge {
            display: inline-block;
            padding: 1px 4px;
            font-size: 7px;
            font-weight: bold;
            border-radius: 2px;
            text-transform: uppercase;
        }
        .badge-success { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-info { background-color: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
        .badge-warning { background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-secondary { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .signature-table td {
            width: 50%;
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            vertical-align: bottom;
            font-size: 8px;
        }
        .sign-line {
            border-top: 1px dashed #64748b;
            margin-top: 35px;
            padding-top: 3px;
            font-weight: bold;
            color: #1e3a8a;
        }
        .annexure-page {
            page-break-before: always;
            padding-top: 5px;
        }
        .annexure-header {
            text-align: center;
            border-bottom: 1.5px solid #1e3a8a;
            padding-bottom: 4px;
            margin-bottom: 10px;
        }
        .doc-item-box {
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            padding: 8px;
            margin-bottom: 12px;
            background-color: #ffffff;
            text-align: center;
            page-break-inside: avoid;
        }
        .doc-item-title {
            font-size: 9.5px;
            font-weight: bold;
            color: #1e3a8a;
            margin-bottom: 4px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 3px;
        }
        .doc-img {
            max-width: 95%;
            max-height: 600px;
            border: 1px solid #cbd5e1;
            margin: 6px auto;
            display: block;
        }
        .doc-meta {
            font-size: 7.5px;
            color: #64748b;
            text-align: left;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    @foreach($sheets as $sheetIndex => $sheet)
        <div class="sheet-container">
            <!-- Sheet Header -->
            <div class="header">
                <div class="inst-title">{{ $metadata['institution_name'] }}</div>
                <div class="inst-sub">{{ $metadata['institution_sub'] }} &bull; {{ $metadata['institution_address'] }}</div>
                <div class="doc-badge">{{ __('Student Detailed Information Sheet') }}</div>
            </div>

            <!-- Meta Bar -->
            <table class="meta-bar">
                <tr>
                    <td style="text-align: left;">
                        <strong>{{ __('Student Lifetime ID') }}:</strong>
                        <span style="font-size: 9px; font-weight: bold; color: #1e3a8a;">{{ $sheet['student_id'] }}</span>
                        @if($sheet['urn'])
                            &nbsp;|&nbsp; <strong>{{ __('URN') }}:</strong> {{ $sheet['urn'] }}
                        @endif
                    </td>
                    <td style="text-align: right;">
                        <strong>{{ __('Generated') }}:</strong> {{ $metadata['generated_at'] }}
                        &nbsp;|&nbsp; <strong>{{ __('Status') }}:</strong>
                        <span class="badge {{ $sheet['status'] === 'Active' ? 'badge-success' : 'badge-secondary' }}">{{ $sheet['status'] }}</span>
                    </td>
                </tr>
            </table>

            <!-- Section 1: Basic Identity & Photo -->
            <div class="section-title">1. {{ __('Primary Identity & Personal Particulars') }}</div>
            <table class="table-profile">
                <colgroup>
                    <col style="width: 17%;">
                    <col style="width: 28%;">
                    <col style="width: 16%;">
                    <col style="width: 24%;">
                    <col style="width: 15%;">
                </colgroup>
                <tbody>
                    <tr>
                        <td class="lbl">{{ __('Full Name') }}</td>
                        <td class="val" colspan="3" style="font-weight: bold; font-size: 9.5px; color: #1e3a8a;">
                            {{ $sheet['name'] }}
                        </td>
                        <td rowspan="5" class="photo-cell" style="vertical-align: middle; text-align: center;">
                            <div class="photo-box">
                                @if(!empty($sheet['photo_data_uri']) || !empty($sheet['photo_url']))
                                    <img src="{{ $sheet['photo_data_uri'] ?: $sheet['photo_url'] }}" alt="{{ $sheet['name'] }}" class="photo-img"/>
                                @else
                                    <div class="photo-placeholder">{{ __('PHOTO') }}</div>
                                @endif
                            </div>
                            <div style="font-size: 7px; color: #64748b; margin-top: 3px; font-weight: bold; text-align: center;">
                                {{ $sheet['student_id'] }}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td class="lbl">{{ __('Date of Birth') }}</td>
                        <td class="val">{{ $sheet['dob'] }} ({{ $sheet['age'] }})</td>
                        <td class="lbl">{{ __('Gender') }}</td>
                        <td class="val">{{ $sheet['gender'] }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">{{ __('Primary Contact') }}</td>
                        <td class="val">{{ $sheet['phone'] ?: '—' }}</td>
                        <td class="lbl">{{ __('WhatsApp') }}</td>
                        <td class="val">{{ $sheet['whatsapp_no'] ?: '—' }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">{{ __('Email Address') }}</td>
                        <td class="val" colspan="3">{{ $sheet['email'] }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">{{ __('10th Roll No.') }}</td>
                        <td class="val">{{ $sheet['tenth_roll'] }}</td>
                        <td class="lbl">{{ $sheet['id_type'] }}</td>
                        <td class="val">{{ $sheet['id_number'] }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- Section 2: Social Reservation & Socio-Economic Profile -->
            <div class="section-title">2. {{ __('Social Reservation & Family Background') }}</div>
            <table class="table-profile">
                <tr>
                    <td class="lbl">{{ __('Category') }}</td>
                    <td class="val">
                        <strong>{{ $sheet['profile']['category'] }}</strong>
                        @if($sheet['profile']['subcaste'] && $sheet['profile']['subcaste'] !== '—')
                            ({{ $sheet['profile']['subcaste'] }})
                        @endif
                    </td>
                    <td class="lbl">{{ __('Creamy Layer') }}</td>
                    <td class="val">{{ $sheet['profile']['creamy_layer'] }}</td>
                </tr>
                <tr>
                    <td class="lbl">{{ __('Caste Certificate') }}</td>
                    <td class="val">{{ $sheet['profile']['cert_no'] }}</td>
                    <td class="lbl">{{ __('Issued Date / By') }}</td>
                    <td class="val">
                        {{ $sheet['profile']['issued_date'] }}
                        @if($sheet['profile']['issued_by'] && $sheet['profile']['issued_by'] !== '—')
                            / {{ $sheet['profile']['issued_by'] }}
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="lbl">{{ __('PwBD Status') }}</td>
                    <td class="val">
                        @if($sheet['profile']['is_pwbd'])
                            <span class="badge badge-warning">{{ __('Yes') }} ({{ $sheet['profile']['pwbd_type'] }} - {{ $sheet['profile']['pwd_percentage'] }})</span>
                        @else
                            {{ __('No') }}
                        @endif
                    </td>
                    <td class="lbl">{{ __('PwBD Certificate') }}</td>
                    <td class="val">{{ $sheet['profile']['pwbd_cert_no'] }}</td>
                </tr>
                <tr>
                    <td class="lbl">{{ __('Father\'s Name') }}</td>
                    <td class="val">{{ $sheet['profile']['father_name'] }}</td>
                    <td class="lbl">{{ __('Father\'s Occupation') }}</td>
                    <td class="val">{{ $sheet['profile']['father_occupation'] }}</td>
                </tr>
                <tr>
                    <td class="lbl">{{ __('Mother\'s Name') }}</td>
                    <td class="val">{{ $sheet['profile']['mother_name'] }}</td>
                    <td class="lbl">{{ __('Mother\'s Occupation') }}</td>
                    <td class="val">{{ $sheet['profile']['mother_occupation'] }}</td>
                </tr>
                <tr>
                    <td class="lbl">{{ __('Annual Family Income') }}</td>
                    <td class="val"><strong>{{ $sheet['profile']['family_income'] }}</strong></td>
                    <td class="lbl">{{ __('Working Professional') }}</td>
                    <td class="val">
                        {{ $sheet['profile']['is_working_professional'] ? __('Yes') : __('No') }}
                        @if($sheet['profile']['occupation'] && $sheet['profile']['occupation'] !== '—')
                            ({{ $sheet['profile']['occupation'] }})
                        @endif
                    </td>
                </tr>
            </table>

            <!-- Section 3: Addresses -->
            <div class="section-title">3. {{ __('Address Details') }}</div>
            <table class="table-profile">
                <tr>
                    <td class="lbl" style="width: 15%;">{{ __('Permanent Address') }}</td>
                    <td class="val" style="width: 35%;">
                        {{ $sheet['permanent_address']['formatted'] }}
                    </td>
                    <td class="lbl" style="width: 15%;">{{ __('Correspondence Address') }}</td>
                    <td class="val" style="width: 35%;">
                        {{ $sheet['correspondence_address']['formatted'] }}
                    </td>
                </tr>
            </table>

            <!-- Section 4: Educational Qualifications -->
            <div class="section-title">4. {{ __('Educational Qualifications') }}</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 12%;">{{ __('Level') }}</th>
                        <th style="width: 25%;">{{ __('Board / University') }}</th>
                        <th style="width: 25%;">{{ __('Institute') }}</th>
                        <th style="width: 15%;">{{ __('Stream / Subject') }}</th>
                        <th style="width: 8%;">{{ __('Year') }}</th>
                        <th style="width: 8%;">{{ __('Marks / %') }}</th>
                        <th style="width: 7%;">{{ __('Division') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sheet['educations'] as $edu)
                        <tr>
                            <td>
                                <strong>{{ $edu['level'] }}</strong>
                                @if($edu['is_highest'])
                                    <span style="color: #1e3a8a; font-weight: bold;">*</span>
                                @endif
                            </td>
                            <td>{{ $edu['board_university'] }}</td>
                            <td>{{ $edu['institute'] }}</td>
                            <td>{{ $edu['stream_subject'] }}</td>
                            <td>{{ $edu['passing_year'] }}</td>
                            <td>{{ $edu['percentage'] }}</td>
                            <td>{{ $edu['division'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; color: #64748b; padding: 6px;">{{ __('No educational qualifications entered.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <!-- Section 5: Current & Historical Course Enrollments -->
            <div class="section-title">5. {{ __('Course Enrollments & Academic Admissions') }}</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 15%;">{{ __('Enrollment No.') }}</th>
                        <th style="width: 25%;">{{ __('Course') }}</th>
                        <th style="width: 18%;">{{ __('Batch') }}</th>
                        <th style="width: 14%;">{{ __('Section') }}</th>
                        <th style="width: 10%;">{{ __('Roll No.') }}</th>
                        <th style="width: 9%;">{{ __('Enrolled') }}</th>
                        <th style="width: 9%;">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sheet['enrollments'] as $enr)
                        <tr>
                            <td style="font-family: monospace;">{{ $enr['enrollment_no'] }}</td>
                            <td><strong>{{ $enr['course'] }}</strong></td>
                            <td>{{ $enr['batch'] }}</td>
                            <td>{{ $enr['section'] }}</td>
                            <td style="font-weight: bold;">{{ $enr['roll_no'] }}</td>
                            <td>{{ $enr['enrolled_at'] }}</td>
                            <td>
                                <span class="badge {{ $enr['status'] === 'Active' ? 'badge-success' : 'badge-secondary' }}">{{ $enr['status'] }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; color: #64748b; padding: 6px;">{{ __('No course enrollment records.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <!-- Section 6: UPSC Civil Services Details & Attempts -->
            <div class="section-title">6. {{ __('UPSC Civil Services Information & History') }}</div>
            <table class="table-profile">
                <tr>
                    <td class="lbl">{{ __('Optional Subject') }}</td>
                    <td class="val"><strong>{{ $sheet['other_details']['optional_subject'] }}</strong></td>
                    <td class="lbl">{{ __('Attempts Given / Left') }}</td>
                    <td class="val">{{ $sheet['other_details']['attempts_already_given'] }} given / {{ $sheet['other_details']['attempts_left'] }} left</td>
                </tr>
                <tr>
                    <td class="lbl">{{ __('Year of Appearing') }}</td>
                    <td class="val">{{ $sheet['other_details']['year_of_appearing'] }}</td>
                    <td class="lbl">{{ __('School Medium') }}</td>
                    <td class="val">{{ $sheet['profile']['school_language'] }}</td>
                </tr>
                <tr>
                    <td class="lbl">{{ __('Activities & Hobbies') }}</td>
                    <td class="val" colspan="3">
                        {{ $sheet['other_details']['hobbies'] }}
                        @if($sheet['other_details']['activities'] && $sheet['other_details']['activities'] !== '—')
                            &bull; {{ $sheet['other_details']['activities'] }}
                        @endif
                    </td>
                </tr>
            </table>

            <!-- Section 7: Employment History (If Any) -->
            @if(count($sheet['employments']) > 0)
                <div class="section-title">7. {{ __('Employment & Professional Experience') }}</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 25%;">{{ __('Employer / Organization') }}</th>
                            <th style="width: 22%;">{{ __('Designation') }}</th>
                            <th style="width: 15%;">{{ __('Nature') }}</th>
                            <th style="width: 18%;">{{ __('Location') }}</th>
                            <th style="width: 20%;">{{ __('Duration') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sheet['employments'] as $emp)
                            <tr>
                                <td><strong>{{ $emp['employer_name'] }}</strong></td>
                                <td>{{ $emp['designation'] }}</td>
                                <td>{{ $emp['nature_of_employment'] }}</td>
                                <td>{{ $emp['location'] }}</td>
                                <td>{{ $emp['from_date'] }} - {{ $emp['to_date'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <!-- Section 8: Uploaded & Verified Documents Summary -->
            <div class="section-title">{{ count($sheet['employments']) > 0 ? '8' : '7' }}. {{ __('Uploaded & Supporting Documents Registry') }}</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">{{ __('Document Type') }}</th>
                        <th style="width: 35%;">{{ __('File Name') }}</th>
                        <th style="width: 15%;">{{ __('Uploaded Date') }}</th>
                        <th style="width: 20%;">{{ __('Verification Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sheet['documents'] as $doc)
                        <tr>
                            <td><strong>{{ ucwords(str_replace('_', ' ', $doc['type'])) }}</strong></td>
                            <td style="font-family: monospace;">{{ $doc['file_name'] }}</td>
                            <td>{{ $doc['uploaded_at'] }}</td>
                            <td>
                                @if($doc['verified'])
                                    <span class="badge badge-success">{{ __('Verified') }}</span>
                                @else
                                    <span class="badge badge-warning">{{ __('Pending Verification') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="text-align: center; color: #64748b; padding: 6px;">{{ __('No uploaded supporting documents on record.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <!-- Signatures Section -->
            <table class="signature-table">
                <tr>
                    <td>
                        <div style="font-size: 7.5px; color: #475569; margin-bottom: 25px;">
                            <strong>{{ __('Candidate Declaration') }}:</strong>
                            {{ __('I hereby certify that all information submitted in this application/dossier is true, correct, and complete to the best of my knowledge.') }}
                        </div>
                        <div class="sign-line">{{ __('Signature of Candidate') }} &bull; {{ $sheet['name'] }}</div>
                    </td>
                    <td>
                        <div style="font-size: 7.5px; color: #475569; margin-bottom: 25px;">
                            <strong>{{ __('Institutional Verification') }}:</strong>
                            {{ __('Verified with original credentials and official institute records.') }}
                        </div>
                        <div class="sign-line">{{ __('Authorized Signatory / Verification Officer') }} &bull; {{ $metadata['institution_name'] }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Optional Document Annexures (When Include Supporting Documents is enabled) -->
        @if(!empty($includeDocuments) && count($sheet['documents']) > 0)
            @foreach($sheet['documents'] as $docIndex => $doc)
                <div class="annexure-page">
                    <div class="annexure-header">
                        <div class="inst-title" style="font-size: 11px;">{{ $metadata['institution_name'] }}</div>
                        <div style="font-size: 9px; font-weight: bold; color: #0f172a; margin-top: 2px;">
                            {{ __('DOCUMENT ANNEXURE') }} &bull; {{ strtoupper(str_replace('_', ' ', $doc['type'])) }}
                        </div>
                        <div style="font-size: 8px; color: #475569; margin-top: 2px;">
                            {{ __('Candidate') }}: <strong>{{ $sheet['name'] }}</strong> ({{ __('ID') }}: {{ $sheet['student_id'] }})
                            &nbsp;|&nbsp; {{ __('File') }}: {{ $doc['file_name'] }}
                            &nbsp;|&nbsp; {{ __('Status') }}:
                            <span class="badge {{ $doc['verified'] ? 'badge-success' : 'badge-warning' }}">{{ $doc['verified'] ? __('Verified') : __('Pending') }}</span>
                        </div>
                    </div>

                    <div class="doc-item-box">
                        <div class="doc-item-title">
                            {{ ucwords(str_replace('_', ' ', $doc['type'])) }} — {{ $doc['file_name'] }}
                        </div>

                        @if($doc['is_image'] && (!empty($doc['data_uri']) || !empty($doc['web_url'])))
                            <img src="{{ $doc['data_uri'] ?: $doc['web_url'] }}" alt="{{ $doc['type'] }}" class="doc-img"/>
                        @else
                            <div style="padding: 40px 20px; border: 1px dashed #cbd5e1; background-color: #f8fafc; margin: 15px 0;">
                                <div style="font-size: 11px; font-weight: bold; color: #1e3a8a; margin-bottom: 6px;">
                                    {{ __('Official Document on Record (Non-Image / PDF)') }}
                                </div>
                                <div style="font-size: 9px; color: #334155; margin-bottom: 4px;">
                                    <strong>{{ __('File Name') }}:</strong> {{ $doc['file_name'] }}
                                </div>
                                <div style="font-size: 8px; color: #64748b;">
                                    <strong>{{ __('Uploaded') }}:</strong> {{ $doc['uploaded_at'] }}
                                    &bull; <strong>{{ __('Verification') }}:</strong> {{ $doc['verified'] ? __('Verified Official Copy') : __('Pending Verification') }}
                                </div>
                                @if(!empty($doc['web_url']))
                                    <div style="margin-top: 10px; font-size: 8px;">
                                        <strong>{{ __('Document URL') }}:</strong> {{ $doc['web_url'] }}
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div class="doc-meta">
                            {{ __('Document ID') }}: #{{ $doc['id'] }} &bull; {{ __('Uploaded') }}: {{ $doc['uploaded_at'] }} &bull; {{ __('Verified') }}: {{ $doc['verified'] ? __('Yes (Official Copy)') : __('No') }}
                        </div>
                    </div>
                </div>
            @endforeach
        @endif
    @endforeach
</body>
</html>

