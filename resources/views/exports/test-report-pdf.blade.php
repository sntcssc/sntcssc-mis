<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title ?? 'Examination Report' }}</title>
    <style>
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 9px;
            color: #1e293b;
            margin: 15px;
        }
        .header {
            border-bottom: 2px solid #0284c7;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .inst-title {
            font-size: 14px;
            font-weight: bold;
            color: #0369a1;
            text-transform: uppercase;
            margin: 0;
        }
        .sub-title {
            font-size: 9px;
            color: #475569;
            margin: 2px 0 0 0;
        }
        .report-title {
            font-size: 12px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 6px;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        table.data-table th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: bold;
            font-size: 8px;
            text-transform: uppercase;
            padding: 5px 6px;
            border: 1px solid #cbd5e1;
            text-align: left;
        }
        table.data-table td {
            border: 1px solid #cbd5e1;
            padding: 4px 6px;
            font-size: 8.5px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .top-rank { background-color: #fef3c7; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <table width="100%">
            <tr>
                <td>
                    <h1 class="inst-title">Satyendranath Tagore Civil Services Study Centre</h1>
                    <p class="sub-title">Apex UPSC Civil Services Coaching Centre &bull; Govt. of West Bengal</p>
                    <div class="report-title">{{ $title }}</div>
                    <div style="font-size: 9px; color: #64748b; margin-top: 2px;">{{ $subtitle ?? '' }}</div>
                </td>
                <td align="right" valign="top">
                    <div style="font-size: 8.5px; color: #64748b;">Generated: {{ $generatedAt->format('d M Y, H:i') }} IST</div>
                    <div style="font-size: 8.5px; color: #64748b;">Total Candidates: {{ count($results) }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th width="5%" class="text-center">Rank</th>
                <th width="12%">Roll No</th>
                <th width="20%">Candidate Name</th>
                <th width="12%">Section</th>
                <th width="8%" class="text-center">Status</th>
                <th width="7%" class="text-center">Right</th>
                <th width="7%" class="text-center">Wrong</th>
                <th width="7%" class="text-center">Blank</th>
                <th width="10%" class="text-right">Marks</th>
                <th width="7%" class="text-right">%</th>
                <th width="8%" class="text-center">Result</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($results as $res)
                <tr class="{{ $res->rank && $res->rank <= 3 ? 'top-rank' : '' }}">
                    <td class="text-center font-bold">{{ $res->rank ? '#' . $res->rank : '—' }}</td>
                    <td class="font-bold">{{ $res->roll_number ?: 'N/A' }}</td>
                    <td>{{ $res->user?->name ?? 'N/A' }}</td>
                    <td>{{ $res->section?->name ?? 'Default' }}</td>
                    <td class="text-center">{{ strtoupper($res->attendance_status) }}</td>
                    <td class="text-center">{{ $res->right_answers }}</td>
                    <td class="text-center">{{ $res->wrong_answers }}</td>
                    <td class="text-center">{{ $res->blank_answers }}</td>
                    <td class="text-right font-bold">{{ $res->marks_obtained }}</td>
                    <td class="text-right">{{ $res->percentage }}%</td>
                    <td class="text-center font-bold" style="color: {{ $res->is_passed ? '#16a34a' : '#dc2626' }};">
                        {{ $res->is_passed ? 'PASS' : 'FAIL' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="text-center" style="padding: 20px;">No examination records found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 25px; border-top: 1px solid #cbd5e1; padding-top: 8px;">
        <table width="100%">
            <tr>
                <td style="font-size: 8px; color: #64748b;">
                    Confidential &bull; Strictly for internal academic monitoring & coaching assessment at SNTCSSC. &bull; Computer-generated document, does not require physical signature.
                </td>
                <td align="right" style="font-size: 8px; color: #64748b;">
                    Page 1 of 1
                </td>
            </tr>
        </table>
    </div>
</body>
</html>

