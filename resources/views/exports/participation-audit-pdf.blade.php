<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title ?? 'Participation Audit Report' }}</title>
    <style>
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 8.5px;
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
        .kpi-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .kpi-box {
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            text-align: center;
            background-color: #f1f5f9;
        }
        .kpi-num {
            font-size: 12px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 2px;
        }
        .kpi-lbl {
            font-size: 7.5px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: bold;
        }
        .section-header {
            font-size: 10px;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            margin: 10px 0 4px 0;
            padding-bottom: 2px;
            border-bottom: 1px solid #94a3b8;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            margin-bottom: 10px;
        }
        table.data-table th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: bold;
            font-size: 7.5px;
            text-transform: uppercase;
            padding: 4px 5px;
            border: 1px solid #cbd5e1;
            text-align: left;
        }
        table.data-table td {
            border: 1px solid #cbd5e1;
            padding: 3px 5px;
            font-size: 8px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .badge-champion { background-color: #dcfce7; color: #166534; font-weight: bold; padding: 1px 4px; border-radius: 3px; }
        .badge-absent { background-color: #fee2e2; color: #991b1b; font-weight: bold; padding: 1px 4px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="header">
        <table width="100%">
            <tr>
                <td>
                    <h1 class="inst-title">Satyendranath Tagore Civil Services Study Centre</h1>
                    <p class="sub-title">Apex UPSC Civil Services Coaching Centre &bull; Govt. of West Bengal</p>
                    <div class="report-title">UPSC Mock Examination Attendance &amp; Turnout Audit Report</div>
                </td>
                <td align="right" valign="top">
                    <div style="font-size: 8px; color: #64748b;">Generated: {{ $generatedAt->format('d M Y, H:i') }} IST</div>
                    <div style="font-size: 8px; color: #64748b;">Ref: SNTCSSC-AUDIT-PART</div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Summary KPIs -->
    <table class="kpi-grid">
        <tr>
            <td class="kpi-box" width="20%">
                <div class="kpi-lbl">Tests Conducted</div>
                <div class="kpi-num">{{ $data['total_tests_conducted'] }}</div>
            </td>
            <td class="kpi-box" width="20%">
                <div class="kpi-lbl">Active Students</div>
                <div class="kpi-num">{{ $data['total_students'] }}</div>
            </td>
            <td class="kpi-box" width="20%">
                <div class="kpi-lbl">Overall Turnout Rate</div>
                <div class="kpi-num">{{ $data['overall_attendance_rate'] }}%</div>
            </td>
            <td class="kpi-box" width="20%">
                <div class="kpi-lbl">100% Champions</div>
                <div class="kpi-num" style="color: #16a34a;">{{ $data['participated_all_count'] }}</div>
            </td>
            <td class="kpi-box" width="20%">
                <div class="kpi-lbl">Chronic Absentees (0%)</div>
                <div class="kpi-num" style="color: #dc2626;">{{ $data['participated_none_count'] }}</div>
            </td>
        </tr>
    </table>

    <!-- 100% Attendance Champions -->
    <div class="section-header">1. 100% Attendance Champions (Attended All Scheduled Examinations)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th width="12%">Roll No</th>
                <th width="24%">Candidate Name</th>
                <th width="20%">Course</th>
                <th width="16%">Batch</th>
                <th width="14%" class="text-center">Tests Attended</th>
                <th width="14%" class="text-right">Average Score</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['participated_all'] as $item)
                <tr>
                    <td class="font-bold">{{ $item['enrollment']->roll_no ?: $item['user']->student_id }}</td>
                    <td class="font-bold">{{ $item['user']->name }}</td>
                    <td>{{ $item['enrollment']->course?->name }}</td>
                    <td>{{ $item['enrollment']->batch?->name }}</td>
                    <td class="text-center"><span class="badge-champion">{{ $item['attended_tests'] }} / {{ $item['available_tests'] }} (100%)</span></td>
                    <td class="text-right font-bold">{{ $item['avg_marks'] }} M</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center" style="padding: 8px;">No 100% attendance candidates found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Chronic Absentees (0% Participation) -->
    <div class="section-header">2. Chronic Absentees / Academic Default List (0% Turnout)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th width="12%">Roll No</th>
                <th width="24%">Candidate Name</th>
                <th width="20%">Course &amp; Batch</th>
                <th width="14%" class="text-center">Available Tests</th>
                <th width="14%" class="text-center">Attendance %</th>
                <th width="16%">Contact Info</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['participated_none'] as $item)
                <tr>
                    <td class="font-bold" style="color: #dc2626;">{{ $item['enrollment']->roll_no ?: $item['user']->student_id }}</td>
                    <td class="font-bold">{{ $item['user']->name }}</td>
                    <td>{{ $item['enrollment']->course?->code }} - {{ $item['enrollment']->batch?->name }}</td>
                    <td class="text-center">{{ $item['available_tests'] }} tests</td>
                    <td class="text-center"><span class="badge-absent">0.0%</span></td>
                    <td>{{ $item['user']->phone ?: $item['user']->email }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center" style="padding: 8px;">No chronic absentees! All enrolled students have participated.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 25px; border-top: 1px solid #cbd5e1; padding-top: 8px;">
        <table width="100%">
            <tr>
                <td style="font-size: 8px; color: #64748b;">
                    Confidential Academic Audit &bull; Satyendranath Tagore Civil Services Study Centre &bull; Computer-generated document, does not require physical signature.
                </td>
                <td align="right" style="font-size: 8px; color: #64748b;">
                    Page 1 of 1
                </td>
            </tr>
        </table>
    </div>
</body>
</html>

