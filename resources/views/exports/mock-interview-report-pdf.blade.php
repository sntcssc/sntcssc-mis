<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 8mm 8mm 12mm 8mm;
        }
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 8px;
            color: #1e293b;
            line-height: 1.25;
            margin: 0;
            padding: 0;
        }
        .footer {
            position: fixed;
            bottom: -7mm;
            left: 0;
            right: 0;
            height: 6mm;
            border-top: 1px solid #cbd5e1;
            padding-top: 2px;
        }
        .footer table {
            width: 100%;
            border-collapse: collapse;
        }
        .header {
            border-bottom: 2px solid #0284c7;
            padding-bottom: 6px;
            margin-bottom: 6px;
        }
        .header table {
            width: 100%;
            border-collapse: collapse;
        }
        .inst-title {
            font-size: 13px;
            font-weight: bold;
            color: #0369a1;
            text-transform: uppercase;
            margin: 0;
            letter-spacing: 0.3px;
        }
        .sub-title {
            font-size: 8px;
            color: #475569;
            margin: 2px 0 0 0;
        }
        .report-title {
            font-size: 11px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: 0.2px;
        }
        .report-subtitle {
            font-size: 7.5px;
            color: #64748b;
            margin-top: 1px;
        }
        .meta-text {
            font-size: 7.5px;
            color: #64748b;
            text-align: right;
            line-height: 1.35;
        }
        .filter-summary {
            background-color: #f1f5f9;
            border-left: 3px solid #0284c7;
            padding: 3px 6px;
            font-size: 7.5px;
            color: #334155;
            margin-bottom: 6px;
        }
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .stat-card {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 4px;
            text-align: center;
        }
        .stat-label {
            font-size: 6.5px;
            color: #64748b;
            font-weight: bold;
            text-transform: uppercase;
        }
        .stat-value {
            font-size: 11px;
            font-weight: bold;
            color: #0f172a;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 4px;
        }
        table.data-table th {
            background-color: #0369a1;
            color: #ffffff;
            font-weight: bold;
            font-size: 7.5px;
            text-transform: uppercase;
            padding: 4px 4px;
            border: 1px solid #0284c7;
            text-align: left;
            letter-spacing: 0.2px;
        }
        table.data-table td {
            border: 1px solid #cbd5e1;
            padding: 3.5px 4px;
            font-size: 7.5px;
            vertical-align: top;
            word-wrap: break-word;
            overflow: hidden;
        }
        table.data-table tr:nth-child(even) td {
            background-color: #f8fafc;
        }
        table.data-table tr {
            page-break-inside: avoid;
        }
        .badge {
            display: inline-block;
            padding: 1px 3px;
            border-radius: 2px;
            font-size: 6.5px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-online { background-color: #ede9fe; color: #6d28d9; }
        .badge-offline { background-color: #e0f2fe; color: #0369a1; }
        .badge-completed { background-color: #dcfce7; color: #15803d; }
        .badge-scheduled { background-color: #fef3c7; color: #b45309; }
        .badge-chair { background-color: #fef3c7; color: #92400e; font-weight: bold; }
        .badge-member { background-color: #f1f5f9; color: #475569; }
    </style>
</head>
<body>
    <!-- Fixed Footer (MUST BE DECLARED FIRST FOR DOMPDF) -->
    <div class="footer">
        <table>
            <tr>
                <td style="border: none; text-align: left; padding: 0; color: #64748b; font-size: 7px;">
                    Satyendranath Tagore Civil Services Study Centre (SNTCSSC) &bull; UPSC Mock Interview Confidential Record
                </td>
                <td style="border: none; text-align: right; padding: 0; color: #64748b; font-size: 7px;">
                    Generated on {{ $generatedAt->format('d/m/Y h:i A') }} IST
                </td>
            </tr>
        </table>
    </div>

    <!-- Report Header Banner -->
    <div class="header">
        <table>
            <tr>
                <td style="width: 68%; border: none; padding: 0;">
                    <h1 class="inst-title">Satyendranath Tagore Civil Services Study Centre</h1>
                    <p class="sub-title">Apex UPSC Civil Services Coaching Centre &bull; Government of West Bengal</p>
                    <div class="report-title">{{ $title }}</div>
                    @if (! empty($subtitle))
                        <div class="report-subtitle">{{ $subtitle }}</div>
                    @endif
                </td>
                <td style="width: 32%; border: none; padding: 0;" class="meta-text" valign="top">
                    <strong>Report Generated:</strong> {{ $generatedAt->format('d M Y, h:i A') }} IST<br>
                    <strong>Generated By:</strong> {{ auth()->user()->name ?? 'Administrator' }}<br>
                    <strong>Academic Cycle:</strong> {{ $academicYear ?? 'All Cycles' }}<br>
                    <strong>Total Records:</strong> {{ count($rows) }}
                </td>
            </tr>
        </table>
    </div>

    <!-- Filter Parameters Bar -->
    @if (! empty($filtersSummary))
        <div class="filter-summary">
            <strong>Applied Scope & Filters:</strong> {{ $filtersSummary }}
        </div>
    @endif

    <!-- Key Performance Indicators Row -->
    @if (! empty($stats))
        <table class="stats-table">
            <tr>
                @foreach ($stats as $label => $value)
                    <td style="width: {{ 100 / count($stats) }}%; padding: 0 2px; border: none;">
                        <div class="stat-card">
                            <div class="stat-label">{{ $label }}</div>
                            <div class="stat-value">{{ $value }}</div>
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @php
        $colWidths = match ($reportType ?? 'student_wise') {
            'board_member_wise' => [
                '#' => '22px',
                'Panelist Name' => '80px',
                'Designation & Org' => '105px',
                'Specialization' => '75px',
                'Role in Session' => '58px',
                'Session Code' => '55px',
                'Session Date' => '50px',
                'Venue / Room' => '70px',
                'Total Candidates' => '40px',
                'Evaluated' => '35px',
                'Avg Score (/275)' => '45px',
                'Highest Score' => '40px',
                'Lowest Score' => '40px',
            ],
            'year_wise' => [
                '#' => '20px',
                'Academic Cycle' => '42px',
                'Session Code' => '52px',
                'Session Title' => '90px',
                'Date & Hours' => '85px',
                'Board Chairperson' => '85px',
                'Panel Size' => '38px',
                'Enrolled Candidates' => '42px',
                'In-Person (Offline)' => '42px',
                'Remote (Online)' => '42px',
                'Completed Interviews' => '42px',
                'Evaluated' => '38px',
                'Avg Score (/275)' => '45px',
                'Recordings Available' => '42px',
                'Session Status' => '48px',
            ],
            default => [ // student_wise
                '#' => '20px',
                'Student ID' => '60px',
                'Candidate Name' => '80px',
                'UPSC Optional' => '70px',
                'Category' => '45px',
                'Session Code' => '55px',
                'Session Date' => '50px',
                'Slot & Order' => '75px',
                'Mode' => '52px',
                'Status' => '46px',
                'Score (/275)' => '45px',
                'Panel Feedback' => '', // auto
                'Recording Status' => '46px',
            ],
        };
    @endphp

    <!-- Tabular Data -->
    <table class="data-table">
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th style="{{ !empty($colWidths[$heading]) ? 'width: '.$colWidths[$heading].';' : '' }}">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $colIndex => $cell)
                        <td>
                            @if (is_string($cell) && str_starts_with($cell, 'BADGE:'))
                                @php
                                    $parts = explode(':', $cell, 3);
                                    $bType = $parts[1] ?? 'default';
                                    $bText = $parts[2] ?? $cell;
                                @endphp
                                <span class="badge badge-{{ $bType }}">{{ $bText }}</span>
                            @else
                                {{ $cell }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headings) }}" style="text-align: center; padding: 12px; color: #64748b; font-style: italic;">
                        No records found matching the specified report criteria and filter filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
