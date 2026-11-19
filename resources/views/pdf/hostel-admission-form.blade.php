<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ __('Hostel Admission & Accommodation Form') }} - {{ $resident->name }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 12mm 10mm 12mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #0f172a;
            margin: 0;
            padding: 0;
            font-size: 10.5px;
            line-height: 1.35;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }
        .institute-title {
            font-size: 15px;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
            line-height: 1.2;
        }
        .institute-sub {
            font-size: 9px;
            color: #475569;
            margin-top: 2px;
        }
        .form-badge {
            display: inline-block;
            background-color: #0f172a;
            color: #ffffff;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.8px;
            padding: 3px 12px;
            border-radius: 3px;
            margin-top: 6px;
            text-transform: uppercase;
        }
        .meta-bar {
            width: 100%;
            margin-bottom: 8px;
            font-size: 9.5px;
        }
        .meta-bar td {
            padding: 2px 0;
        }
        .section-header {
            background-color: #f1f5f9;
            border-left: 3px solid #0f172a;
            padding: 3px 8px;
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 8px;
            margin-bottom: 4px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .data-table td {
            padding: 4.5px 6px;
            border: 1px solid #cbd5e1;
            font-size: 10px;
            vertical-align: middle;
        }
        .data-table .label {
            width: 22%;
            font-weight: bold;
            background-color: #f8fafc;
            color: #334155;
        }
        .data-table .value {
            width: 28%;
            color: #0f172a;
        }
        .rules-box {
            border: 1px solid #cbd5e1;
            background-color: #fafaf9;
            padding: 6px 10px;
            font-size: 8.5px;
            line-height: 1.4;
            color: #334155;
            margin-top: 6px;
            margin-bottom: 10px;
            border-radius: 2px;
        }
        .rules-box ol {
            margin: 3px 0 0 16px;
            padding: 0;
        }
        .rules-box li {
            margin-bottom: 2px;
        }
        .signatures-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .signatures-table td {
            width: 33.33%;
            border: 1px solid #cbd5e1;
            padding: 8px;
            text-align: center;
            vertical-align: bottom;
            height: 55px;
            font-size: 9px;
        }
        .sig-title {
            font-weight: bold;
            border-top: 1px dashed #64748b;
            padding-top: 4px;
            margin-top: 30px;
            display: block;
        }
        .photo-box {
            width: 90px;
            height: 105px;
            border: 1px dashed #64748b;
            text-align: center;
            vertical-align: middle;
            font-size: 8.5px;
            color: #64748b;
            background-color: #f8fafc;
        }
        .office-seal-box {
            border: 1px dashed #cbd5e1;
            height: 50px;
            text-align: center;
            vertical-align: middle;
            color: #94a3b8;
            font-size: 8.5px;
        }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="header">
        <div class="institute-title">Satyendra Nath Tagore Civil Services Study Centre</div>
        <div class="institute-sub">Government of West Bengal &bull; Netaji Subhas Administrative Training Institute Campus, Salt Lake, Kolkata - 700106</div>
        <div class="form-badge">Hostel Admission &amp; Room Accommodation Application Form</div>
    </div>

    <!-- Meta Information Row -->
    <table class="meta-bar">
        <tr>
            <td style="width: 50%;">
                <strong>{{ __('Application / Form Ref:') }}</strong> {{ $formRef }}
            </td>
            <td style="width: 50%; text-align: right;">
                <strong>{{ __('Date of Generation:') }}</strong> {{ now()->format('d F Y, h:i A') }}
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
        <tr>
            <td style="vertical-align: top; width: 82%;">
                <!-- Section 1: Resident Personal Identity -->
                <div class="section-header" style="margin-top: 0;">1. Personal Identity &amp; Contact Details</div>
                <table class="data-table">
                    <tr>
                        <td class="label">{{ __('Full Name') }}</td>
                        <td class="value"><strong>{{ $resident->name }}</strong></td>
                        <td class="label">{{ __('Gender') }}</td>
                        <td class="value">{{ ucfirst($resident->gender ?? $resident->studentProfile?->gender ?? 'N/A') }}</td>
                    </tr>
                    <tr>
                        <td class="label">{{ __('Student / URN ID') }}</td>
                        <td class="value"><strong>{{ $resident->student_id ?? $resident->urn ?? $resident->studentProfile?->urn ?? 'N/A' }}</strong></td>
                        <td class="label">{{ __('Category') }}</td>
                        <td class="value">{{ $resident->studentProfile?->category ?? 'General' }}</td>
                    </tr>
                    <tr>
                        <td class="label">{{ __('Mobile Phone') }}</td>
                        <td class="value">{{ $resident->phone ?: 'N/A' }}</td>
                        <td class="label">{{ __('WhatsApp No.') }}</td>
                        <td class="value">{{ $resident->whatsapp_no ?: ($resident->phone ?: 'N/A') }}</td>
                    </tr>
                    <tr>
                        <td class="label">{{ __('Email Address') }}</td>
                        <td class="value" colspan="3">{{ $resident->email }}</td>
                    </tr>
                </table>
            </td>
            <td style="vertical-align: top; width: 18%; text-align: right; padding-left: 8px;">
                <div class="photo-box">
                    <br/><br/>
                    {{ __('Affix Passport') }}<br/>{{ __('Photo Here') }}<br/>(35mm x 45mm)
                </div>
            </td>
        </tr>
    </table>

    <!-- Section 2: Academic Enrollment Details -->
    <div class="section-header">2. Academic Program &amp; Institutional Enrolment Details</div>
    <table class="data-table">
        <tr>
            <td class="label">{{ __('Enrolment ID') }}</td>
            <td class="value"><strong>{{ $enrollment?->enrollment_no ?? 'N/A' }}</strong></td>
            <td class="label">{{ __('Enrolment Status') }}</td>
            <td class="value">{{ ucfirst($enrollment?->status ?? 'Enrolled Candidate') }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Course Name') }}</td>
            <td class="value" colspan="3"><strong>{{ $enrollment?->course?->name ?? 'Civil Services Coaching Program' }}</strong> ({{ $enrollment?->course?->code ?? 'N/A' }})</td>
        </tr>
        <tr>
            <td class="label">{{ __('Batch Name') }}</td>
            <td class="value"><strong>{{ $enrollment?->batch?->name ?? ($allocation?->previous_batch_name ?: 'General Roster') }}</strong></td>
            <td class="label">{{ __('Assigned Section') }}</td>
            <td class="value">{{ $enrollment?->section?->name ?? 'Section A' }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Guardian / Father') }}</td>
            <td class="value">{{ $resident->studentProfile?->father_name ?? 'N/A' }}</td>
            <td class="label">{{ __('Emergency Contact') }}</td>
            <td class="value">{{ $resident->studentProfile?->emergency_contact ?? ($resident->phone ?: 'N/A') }}</td>
        </tr>
    </table>

    <!-- Section 3: Hostel Accommodation Details -->
    <div class="section-header">3. Hostel Room &amp; Bed Allotment Information</div>
    <table class="data-table">
        <tr>
            <td class="label">{{ __('Hostel Building') }}</td>
            <td class="value"><strong>{{ $building?->name ?? 'Ganga Hostel / Mayurakhshi Hostel / Teesta Hostel' }}</strong> ({{ $building?->code ?? 'GH' }})</td>
            <td class="label">{{ __('Floor Level') }}</td>
            <td class="value">{{ $room?->floor?->name ?? 'Ground Floor / 1st Floor' }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Room Number') }}</td>
            <td class="value"><strong>{{ $room?->room_number ?? 'Pending Allotment' }}</strong></td>
            <td class="label">{{ __('Assigned Bed') }}</td>
            <td class="value"><strong>{{ $bed?->bed_number ?? 'Bed 1' }}</strong></td>
        </tr>
        <tr>
            <td class="label">{{ __('Room Sharing Type') }}</td>
            <td class="value">{{ $room ? ($room->isSharing() ? 'Sharing Room (' . $room->capacity . ' Beds)' : 'Single / Non-Sharing') : 'Double Sharing' }}</td>
            <td class="label">{{ __('Gender Designation') }}</td>
            <td class="value">{{ $room?->gender_label ?? 'Designated Wing' }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Check-In Date') }}</td>
            <td class="value"><strong>{{ $allocation?->start_date ? $allocation->start_date->format('d M Y') : now()->format('d M Y') }}</strong></td>
            <td class="label">{{ __('Stay Timeline Duration') }}</td>
            <td class="value">{{ $allocation?->duration_formatted ?? '6 Months (Semester Term)' }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Scheduled End Date') }}</td>
            <td class="value">{{ $allocation?->end_date ? $allocation->end_date->format('d M Y') : 'Open / Indefinite' }}</td>
            <td class="label">{{ __('Hostel Warden') }}</td>
            <td class="value">{{ $building?->warden_name ?? 'Prof. S. K. Banerjee' }} ({{ $building?->warden_contact ?? '+91 98301 23456' }})</td>
        </tr>
        <tr>
            <td class="label">{{ __('Hostel Manager') }}</td>
            <td class="value">{{ $building?->manager_name ?? 'Hostel Manager' }} ({{ $building?->manager_contact ?? '+91 98305 11223' }})</td>
            <td class="label">{{ __('Caretakers on Duty') }}</td>
            <td class="value">
                @if ($building && $building->caretakers->isNotEmpty())
                    {{ $building->caretakers->pluck('name')->join(', ') }}
                @else
                    Ramesh Chandra Das, Subal Naskar
                @endif
            </td>
        </tr>
    </table>

    <!-- Section 4: Rules & Code of Conduct Undertaking -->
    <div class="section-header">4. Resident Undertaking &amp; Code of Conduct Declaration</div>
    <div class="rules-box">
        <strong>Undertaking by the Candidate / Resident:</strong>
        <ol>
            <li>I hereby affirm that all particulars furnished above are true and complete. I will abide by all rules, guidelines, and disciplinary protocols of the SNTCSSC Hostel.</li>
            <li>I understand that quiet hours are observed strictly between <strong>10:00 PM and 6:00 AM</strong>. Creating disturbance, unauthorized gatherings, or playing loud audio is prohibited.</li>
            <li>No outside guests, unauthorized visitors, or non-residents are allowed inside residential rooms. Day visitors may meet only in the common reception lounge.</li>
            <li>Substance abuse, smoking, alcohol, narcotics, or any hazardous electric appliances (heaters/burners) are strictly forbidden and will result in instant expulsion and forfeiture.</li>
            <li>I will submit this signed physical copy to the hostel office upon checking in. If required, I will also upload the scanned soft copy through my student portal.</li>
        </ol>
    </div>

    <!-- Signatures Table -->
    <table class="signatures-table">
        <tr>
            <td>
                <span class="sig-title">{{ __('Signature of Resident Applicant') }}</span>
                Date: ________________________
            </td>
            <td>
                <span class="sig-title">{{ __('Signature of Parent / Guardian') }}</span>
                Contact: ______________________
            </td>
            <td>
                <span class="sig-title">{{ __('Hostel Caretaker Verification') }}</span>
                Key Handover &amp; Physical Check-in
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <span class="sig-title">{{ __('Hostel Manager Verification & Desk Filing') }}</span>
                Hardcopy Form Filing Rack / Shelf: ________________________
            </td>
            <td>
                <span class="sig-title">{{ __('Warden Approval & Institutional Seal') }}</span>
                Netaji Subhas ATI Complex, Salt Lake
            </td>
        </tr>
    </table>

</body>
</html>
