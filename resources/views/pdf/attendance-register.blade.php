<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1e293b;
            margin: 0;
            padding: 15px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .header h1 {
            margin: 0 0 4px 0;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0f172a;
        }
        .header p {
            margin: 2px 0;
            font-size: 10px;
            color: #64748b;
        }
        .summary-boxes {
            margin-bottom: 12px;
            display: table;
            width: 100%;
        }
        .summary-cell {
            display: table-cell;
            width: 25%;
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 4px;
            font-size: 9px;
        }
        .summary-cell strong {
            display: block;
            font-size: 13px;
            color: #0f172a;
            margin-top: 2px;
        }
        table.records-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        table.records-table th, table.records-table td {
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            text-align: left;
        }
        table.records-table th {
            background-color: #f1f5f9;
            color: #334155;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }
        table.records-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 8px;
            text-transform: uppercase;
        }
        .badge-present { background: #dcfce7; color: #166534; }
        .badge-absent { background: #fee2e2; color: #991b1b; }
        .badge-late { background: #fef3c7; color: #92400e; }
        .badge-half_day { background: #f3e8ff; color: #6b21a8; }
        .badge-on_leave { background: #e0f2fe; color: #075985; }
        .badge-holiday { background: #ffe4e6; color: #9f1239; }
        .badge-weekly_off { background: #f1f5f9; color: #475569; }
        .footer {
            margin-top: 15px;
            border-top: 1px solid #e2e8f0;
            padding-top: 6px;
            font-size: 8px;
            color: #94a3b8;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Satyendranath Tagore Civil Services Study Centre (SNTCSSC)</h1>
        <p>{{ $title }} | Period: {{ $dateRange }}</p>
    </div>

    <table class="summary-boxes">
        <tr>
            <td class="summary-cell">
                Total Records
                <strong>{{ $totalRecords }}</strong>
            </td>
            <td class="summary-cell" style="border-left: 3px solid #16a34a;">
                Present / Attended
                <strong style="color: #16a34a;">{{ $presentCount }}</strong>
            </td>
            <td class="summary-cell" style="border-left: 3px solid #dc2626;">
                Absent
                <strong style="color: #dc2626;">{{ $absentCount }}</strong>
            </td>
            <td class="summary-cell" style="border-left: 3px solid #0284c7;">
                On Approved Leave
                <strong style="color: #0284c7;">{{ $leaveCount }}</strong>
            </td>
        </tr>
    </table>

    <table class="records-table">
        <thead>
            <tr>
                <th style="width: 12%;">Attendance No</th>
                <th style="width: 10%;">Date</th>
                <th style="width: 8%;">Type</th>
                <th style="width: 20%;">Name & Identifier</th>
                <th style="width: 15%;">Course / Department</th>
                <th style="width: 10%;">Status</th>
                <th style="width: 8%;">Check-In</th>
                <th style="width: 8%;">Check-Out</th>
                <th style="width: 9%;">Hours</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($attendances as $att)
                <tr>
                    <td style="font-family: monospace;">{{ $att->attendance_no }}</td>
                    <td>{{ $att->date ? $att->date->format('d-m-Y') : 'N/A' }}</td>
                    <td>{{ ucfirst($att->user_type) }}</td>
                    <td>
                        <strong>{{ $att->user?->name ?? 'N/A' }}</strong><br>
                        <span style="color: #64748b; font-size: 8px;">{{ $att->user?->student_id ?? $att->user?->employee_id ?? $att->user?->email }}</span>
                    </td>
                    <td>{{ $att->course?->name ?? $att->user?->staff_department ?? 'N/A' }}</td>
                    <td>
                        <span class="badge badge-{{ $att->status }}">{{ $att->statusLabel() }}</span>
                    </td>
                    <td>{{ $att->check_in_time ?? '-' }}</td>
                    <td>{{ $att->check_out_time ?? '-' }}</td>
                    <td>{{ $att->working_hours > 0 ? $att->working_hours . ' hrs' : '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" style="text-align: center; padding: 15px; color: #94a3b8;">
                        No attendance records found for the selected criteria.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Generated on {{ $generatedAt }} | SNT CSSC Management Information System (MIS)
    </div>
</body>
</html>
