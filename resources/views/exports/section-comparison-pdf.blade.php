<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title ?? 'Section Comparison Report' }}</title>
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
            margin-top: 10px;
        }
        table.data-table th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: bold;
            font-size: 8px;
            text-transform: uppercase;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            text-align: left;
        }
        table.data-table td {
            border: 1px solid #cbd5e1;
            padding: 5px 8px;
            font-size: 8.5px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <table width="100%">
            <tr>
                <td>
                    <h1 class="inst-title">Satyendranath Tagore Civil Services Study Centre</h1>
                    <p class="sub-title">Apex UPSC Civil Services Coaching Centre &bull; Govt. of West Bengal</p>
                    <div class="report-title">Batch &amp; Section Comparative Examination Analytics</div>
                    <div style="font-size: 9px; color: #64748b; margin-top: 2px;">{{ $subtitle ?? 'Academic Performance Benchmark by Classroom Sections' }}</div>
                </td>
                <td align="right" valign="top">
                    <div style="font-size: 8px; color: #64748b;">Generated: {{ $generatedAt->format('d M Y, H:i') }} IST</div>
                    <div style="font-size: 8px; color: #64748b;">Ref: SNTCSSC-SEC-COMP</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th width="24%">Section Name</th>
                <th width="16%">Classroom Code</th>
                <th width="14%" class="text-center">Enrolled Students</th>
                <th width="12%" class="text-center">Tests Held</th>
                <th width="12%" class="text-right">Average Score</th>
                <th width="10%" class="text-right">Average %</th>
                <th width="12%" class="text-right">Highest Score</th>
            </tr>
        </thead>
        <tbody>
            @forelse($sections as $sec)
                <tr>
                    <td class="font-bold">{{ $sec['section']->name }}</td>
                    <td style="font-family: monospace;">{{ $sec['section']->code }}</td>
                    <td class="text-center font-bold">{{ $sec['student_count'] }}</td>
                    <td class="text-center font-bold">{{ $sec['tests_count'] }}</td>
                    <td class="text-right font-bold">{{ $sec['avg_marks'] }} M</td>
                    <td class="text-right font-bold" style="color: #0284c7;">{{ $sec['avg_percentage'] }}%</td>
                    <td class="text-right font-bold" style="color: #d97706;">{{ $sec['highest_marks'] }} M</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center" style="padding: 15px;">No section comparative records found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 25px; border-top: 1px solid #cbd5e1; padding-top: 8px;">
        <table width="100%">
            <tr>
                <td style="font-size: 8px; color: #64748b;">
                    Confidential Academic Analytics &bull; Satyendranath Tagore Civil Services Study Centre &bull; Computer-generated document, does not require physical signature.
                </td>
                <td align="right" style="font-size: 8px; color: #64748b;">
                    Page 1 of 1
                </td>
            </tr>
        </table>
    </div>
</body>
</html>

