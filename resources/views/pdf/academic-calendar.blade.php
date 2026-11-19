<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ $title ?? 'Academic Calendar' }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm 10mm 12mm 10mm;
        }
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 8.5px;
            color: #1e293b;
            line-height: 1.35;
            margin: 0;
            padding: 0;
        }
        .header {
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .header table {
            width: 100%;
            border-collapse: collapse;
        }
        .institute-title {
            font-size: 14px;
            font-weight: bold;
            color: #1e3a8a;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sub-title {
            font-size: 9px;
            color: #475569;
            margin: 2px 0 0 0;
        }
        .report-title {
            font-size: 13px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 5px;
        }
        .meta-box {
            text-align: right;
            font-size: 8px;
            color: #64748b;
        }
        .meta-box strong {
            color: #1e293b;
        }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-academic { background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
        .badge-examination { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-holiday { background-color: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-co_curricular { background-color: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .badge-administrative { background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-sports_cultural { background-color: #fce7f3; color: #be185d; border: 1px solid #fbcfe8; }
        .badge-version { background-color: #1e3a8a; color: #ffffff; padding: 3px 8px; border-radius: 9999px; }

        .summary-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 12px;
        }
        .summary-card table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary-card td {
            font-size: 8.5px;
            padding: 3px 6px;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        table.data-table th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: bold;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            text-align: left;
        }
        table.data-table td {
            border: 1px solid #e2e8f0;
            padding: 5px 6px;
            font-size: 8px;
            vertical-align: middle;
        }
        table.data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .month-header {
            background-color: #e0e7ff !important;
            color: #1e3a8a !important;
            font-weight: bold;
            font-size: 9px !important;
            padding: 6px 8px !important;
            text-align: left !important;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            border-top: 1px solid #cbd5e1;
            padding-top: 4px;
            font-size: 7.5px;
            color: #64748b;
        }
        .footer table {
            width: 100%;
        }
        .page-number:after {
            content: counter(page);
        }
    </style>
</head>
<body>
    <!-- Document Header -->
    <div class="header">
        <table>
            <tr>
                <td style="width: 70%;">
                    <div class="institute-title">Satyendranath Tagore Civil Services Study Centre</div>
                    <div class="sub-title">Government of West Bengal &bull; Center for Excellence in Administrative Leadership</div>
                    <div class="report-title">{{ $calendar->title }} ({{ $calendar->academic_year }})</div>
                    @if($calendar->theme_or_motto)
                        <div style="font-size: 8px; color: #64748b; font-style: italic; margin-top: 2px;">
                            Theme: "{{ $calendar->theme_or_motto }}"
                        </div>
                    @endif
                </td>
                <td class="meta-box" style="width: 30%;">
                    <div><strong>Code:</strong> {{ $calendar->calendar_code }}</div>
                    <div><strong>Operational Session:</strong> {{ $calendar->formatted_period }}</div>
                    <div><strong>Document Version:</strong> <span class="badge badge-version">{{ $calendar->currentVersion?->version_code ?? 'v1.0' }}</span></div>
                    <div><strong>Generated:</strong> {{ $generatedAt->format('d M Y, h:i A') }}</div>
                    <div><strong>Status:</strong> {{ strtoupper($calendar->status) }}</div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Calendar Metrics & Parameters -->
    <div class="summary-card">
        <table>
            <tr>
                <td style="width: 25%;"><strong>Total Working Days:</strong> {{ $calendar->total_working_days }} days</td>
                <td style="width: 25%;"><strong>Total Instructional Days:</strong> {{ $calendar->total_instructional_days }} days</td>
                <td style="width: 25%;"><strong>Total Scheduled Activities:</strong> {{ $activities->count() }} events</td>
                <td style="width: 25%;">
                    <strong>Active Revision:</strong> 
                    {{ $calendar->currentVersion?->version_title ?? 'Inaugural' }} 
                    ({{ $calendar->currentVersion?->approved_by ?? 'Academic Council' }})
                </td>
            </tr>
            @if($calendar->description)
            <tr>
                <td colspan="4" style="color: #475569; font-size: 8px; padding-top: 4px; border-top: 1px dashed #cbd5e1;">
                    <strong>Overview:</strong> {{ $calendar->description }}
                </td>
            </tr>
            @endif
        </table>
    </div>

    <!-- Month-Wise Activities Schedule -->
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 8%;">Code</th>
                <th style="width: 22%;">Activity / Event Title</th>
                <th style="width: 12%;">Category</th>
                <th style="width: 12%;">Date(s)</th>
                <th style="width: 10%;">Audience</th>
                <th style="width: 12%;">Department</th>
                <th style="width: 12%;">Mode / Venue</th>
                <th style="width: 8%;">Status</th>
                <th style="width: 4%; text-align: center;">Tent.</th>
            </tr>
        </thead>
        <tbody>
            @php
                $grouped = $activities->groupBy(fn($item) => $item->month_name . ' ' . $item->year);
            @endphp

            @forelse($grouped as $monthYear => $items)
                <tr>
                    <td colspan="9" class="month-header">
                        &bull; {{ strtoupper($monthYear) }} ({{ $items->count() }} Planned {{ Str::plural('Activity', $items->count()) }})
                    </td>
                </tr>
                @foreach($items as $act)
                    <tr>
                        <td style="font-family: monospace; font-size: 7.5px;">{{ $act->activity_code }}</td>
                        <td>
                            <strong>{{ $act->title }}</strong>
                            @if($act->description)
                                <div style="font-size: 7px; color: #64748b; margin-top: 1px;">{{ Str::limit($act->description, 90) }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-{{ $act->category }}">
                                {{ $act->getCategoryLabel() }}
                            </span>
                        </td>
                        <td>{{ $act->formatted_date_range }}</td>
                        <td>{{ ucfirst($act->target_audience) }}</td>
                        <td>{{ $act->department ?? '-' }}</td>
                        <td>
                            <div>{{ ucfirst($act->venue_mode) }}</div>
                            @if($act->venue_or_link)
                                <div style="font-size: 7px; color: #64748b;">{{ $act->venue_or_link }}</div>
                            @endif
                        </td>
                        <td>
                            <span style="font-weight: 600; text-transform: capitalize;">{{ $act->status }}</span>
                        </td>
                        <td style="text-align: center;">{{ $act->is_tentative ? 'Yes' : 'No' }}</td>
                    </tr>
                @endforeach
            @empty
                <tr>
                    <td colspan="9" style="text-align: center; padding: 20px; color: #94a3b8;">
                        No activities or institutional events recorded for this academic year yet.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Version Control Audit History -->
    @if($calendar->versions->isNotEmpty())
    <div style="margin-top: 10px;">
        <div style="font-size: 8.5px; font-weight: bold; color: #1e3a8a; margin-bottom: 4px; text-transform: uppercase;">
            Preserved Document Version Control History:
        </div>
        <table class="data-table" style="font-size: 7.5px; margin-bottom: 25px;">
            <thead>
                <tr>
                    <th style="width: 8%;">Version</th>
                    <th style="width: 25%;">Revision Title</th>
                    <th style="width: 35%;">Changelog / Revision Summary</th>
                    <th style="width: 12%;">Approved By</th>
                    <th style="width: 10%;">Effective Date</th>
                    <th style="width: 10%;">Preserved File</th>
                </tr>
            </thead>
            <tbody>
                @foreach($calendar->versions as $ver)
                    <tr>
                        <td>
                            <strong>{{ $ver->version_code }}</strong>
                            @if($ver->is_current)
                                <span style="color: #16a34a; font-weight: bold;">(Active)</span>
                            @endif
                        </td>
                        <td>{{ $ver->version_title }}</td>
                        <td>{{ $ver->change_summary ?? 'Standard periodic revision.' }}</td>
                        <td>{{ $ver->approved_by ?? '-' }}</td>
                        <td>{{ $ver->effective_from ? $ver->effective_from->format('d M Y') : '-' }}</td>
                        <td>{{ $ver->file_name }} ({{ $ver->human_file_size }})</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <!-- Official Document Footer -->
    <div class="footer">
        <table>
            <tr>
                <td style="width: 60%;">
                    Official Certified Document &bull; Satyendranath Tagore Civil Services Study Centre (SNTCSSC) &bull; Preserved under Version Control
                </td>
                <td style="width: 40%; text-align: right;">
                    Page <span class="page-number"></span> &bull; Security Hash: {{ substr(md5($calendar->calendar_code . $calendar->updated_at), 0, 12) }}
                </td>
            </tr>
        </table>
    </div>
</body>
</html>

