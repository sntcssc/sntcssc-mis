<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title ?? 'Student Performance Dossier' }}</title>
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
        .student-card {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            margin-bottom: 12px;
        }
        .student-table {
            width: 100%;
            border-collapse: collapse;
        }
        .student-table td {
            padding: 2px 4px;
            font-size: 8.5px;
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
            font-size: 8px;
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
                    <div class="report-title">Candidate Academic Examination Dossier &amp; Progress Transcript</div>
                </td>
                <td align="right" valign="top">
                    <div style="font-size: 8px; color: #64748b;">Generated: {{ $generatedAt->format('d M Y, H:i') }} IST</div>
                    <div style="font-size: 8px; color: #64748b;">Verification Ref: SNTCSSC-MIS-DOS</div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Student Details -->
    <div class="student-card">
        <table class="student-table">
            <tr>
                <td width="15%"><strong>Candidate Name:</strong></td>
                <td width="35%"><strong>{{ $student->name }}</strong></td>
                <td width="15%"><strong>Roll / Student ID:</strong></td>
                <td width="35%">{{ $student->student_id ?: ($enrollment?->roll_no ?: 'N/A') }}</td>
            </tr>
            <tr>
                <td><strong>Course:</strong></td>
                <td>{{ $enrollment?->course?->name ?? 'UPSC Civil Services' }}</td>
                <td><strong>Batch &amp; Section:</strong></td>
                <td>{{ $enrollment?->batch?->name ?? 'General' }} @if($enrollment?->section) (Sec {{ $enrollment->section->name }}) @endif</td>
            </tr>
            <tr>
                <td><strong>Email:</strong></td>
                <td>{{ $student->email }}</td>
                <td><strong>Contact Phone:</strong></td>
                <td>{{ $student->phone ?: 'N/A' }}</td>
            </tr>
        </table>
    </div>

    <!-- Summary KPIs -->
    <table class="kpi-grid">
        <tr>
            <td class="kpi-box" width="20%">
                <div class="kpi-lbl">Tests Turnout</div>
                <div class="kpi-num">{{ $dossier['attended_count'] }} / {{ $dossier['total_tests_conducted'] }} ({{ $dossier['participation_rate'] }}%)</div>
            </td>
            <td class="kpi-box" width="20%">
                <div class="kpi-lbl">Average Score</div>
                <div class="kpi-num">{{ $dossier['avg_marks'] }} M</div>
            </td>
            <td class="kpi-box" width="20%">
                <div class="kpi-lbl">Average % Score</div>
                <div class="kpi-num">{{ $dossier['avg_percentage'] }}%</div>
            </td>
            <td class="kpi-box" width="20%">
                <div class="kpi-lbl">Best Merit Rank</div>
                <div class="kpi-num">{{ $dossier['best_rank'] ? '#' . $dossier['best_rank'] : 'N/A' }}</div>
            </td>
            <td class="kpi-box" width="20%">
                <div class="kpi-lbl">Top 10 Finishes</div>
                <div class="kpi-num">{{ $dossier['top_ten_count'] }} Times</div>
            </td>
        </tr>
    </table>

    <!-- All Attended Examinations -->
    <table class="data-table">
        <thead>
            <tr>
                <th width="10%">Date</th>
                <th width="12%">Exam Code</th>
                <th width="24%">Examination Title</th>
                <th width="10%">Stage &amp; Type</th>
                <th width="8%" class="text-center">Status</th>
                <th width="10%" class="text-right">Marks</th>
                <th width="8%" class="text-right">% Score</th>
                <th width="8%" class="text-center">Rank</th>
                <th width="10%" class="text-center">Result</th>
            </tr>
        </thead>
        <tbody>
            @forelse($dossier['results'] as $res)
                <tr class="{{ $res->is_top_ten ? 'top-rank' : '' }}">
                    <td>{{ $res->test?->test_date?->format('d/m/Y') }}</td>
                    <td class="font-bold">{{ $res->test?->code }}</td>
                    <td>{{ $res->test?->title }}</td>
                    <td>{{ strtoupper($res->test?->test_stage ?? '') }} ({{ strtoupper($res->test?->test_type ?? '') }})</td>
                    <td class="text-center">{{ strtoupper($res->attendance_status) }}</td>
                    <td class="text-right font-bold">{{ $res->marks_obtained }} / {{ $res->total_marks }}</td>
                    <td class="text-right">{{ $res->percentage }}%</td>
                    <td class="text-center font-bold">{{ $res->rank ? '#' . $res->rank : '—' }}</td>
                    <td class="text-center font-bold" style="color: {{ $res->is_passed ? '#16a34a' : '#dc2626' }};">
                        {{ $res->is_passed ? 'PASS' : 'REMEDIAL' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center" style="padding: 15px;">No examination records logged for this candidate.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 25px; border-top: 1px solid #cbd5e1; padding-top: 8px;">
        <table width="100%">
            <tr>
                <td style="font-size: 8px; color: #64748b;">
                    Official Academic Dossier &bull; Satyendranath Tagore Civil Services Study Centre (SNTCSSC) &bull; Computer-generated document, does not require physical signature.
                </td>
                <td align="right" style="font-size: 8px; color: #64748b;">
                    Page 1 of 1
                </td>
            </tr>
        </table>
    </div>
</body>
</html>

