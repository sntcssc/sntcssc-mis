<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ $session->session_code }} - Mock Interview Timetable</title>
    <style>
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 9px;
            color: #1f2937;
            margin: 0;
            padding: 12px;
        }
        .header {
            border-bottom: 2px solid #0284c7;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .header table {
            width: 100%;
        }
        .institute-title {
            font-size: 15px;
            font-weight: bold;
            color: #0369a1;
            margin: 0;
            text-transform: uppercase;
        }
        .sub-title {
            font-size: 10px;
            color: #4b5563;
            margin: 2px 0 0 0;
        }
        .report-title {
            font-size: 13px;
            font-weight: bold;
            color: #111827;
            margin-top: 6px;
        }
        .meta-text {
            font-size: 8.5px;
            color: #6b7280;
            text-align: right;
        }
        .board-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 8px;
            margin-bottom: 12px;
        }
        .board-box h4 {
            margin: 0 0 6px 0;
            font-size: 10px;
            color: #0369a1;
            text-transform: uppercase;
        }
        .board-grid {
            width: 100%;
            border-collapse: collapse;
        }
        .board-grid td {
            padding: 3px 6px;
            font-size: 8.5px;
            vertical-align: top;
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
            text-align: left;
            padding: 5px 6px;
            border: 1px solid #cbd5e1;
            font-size: 8px;
            text-transform: uppercase;
        }
        table.data-table td {
            padding: 5px 6px;
            border: 1px solid #e2e8f0;
            font-size: 8.5px;
            vertical-align: middle;
        }
        table.data-table tr:nth-child(even) td {
            background-color: #fafafa;
        }
        .badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-online { background-color: #ede9fe; color: #6d28d9; border: 1px solid #ddd6fe; }
        .badge-offline { background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .badge-scheduled { background-color: #eff6ff; color: #1d4ed8; }
        .badge-completed { background-color: #ecfdf5; color: #047857; }
        .footer {
            margin-top: 25px;
            width: 100%;
        }
        .signatures {
            width: 100%;
            margin-top: 35px;
        }
        .sig-col {
            text-align: center;
            font-size: 8.5px;
            color: #475569;
            border-top: 1px dashed #94a3b8;
            padding-top: 5px;
            width: 25%;
        }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td style="width: 70%;">
                    <h1 class="institute-title">{{ \App\Models\Setting::appName() ?: 'SATYENDRANATH TAGORE CIVIL SERVICES STUDY CENTRE' }}</h1>
                    <p class="sub-title">Government of West Bengal Initiative for Civil Services Aspirants</p>
                    <div class="report-title">UPSC CSE Personality Test / Mock Interview Timetable</div>
                </td>
                <td style="width: 30%;" class="meta-text">
                    <strong>Session Code:</strong> {{ $session->session_code }}<br>
                    <strong>Academic Cycle:</strong> {{ $session->academic_year }}<br>
                    <strong>Session Date:</strong> {{ $session->formatted_date }}<br>
                    <strong>Board Hours:</strong> {{ $session->formatted_time_range }}<br>
                    <strong>Generated:</strong> {{ now()->format('d M Y, h:i A') }}
                </td>
            </tr>
        </table>
    </div>

    <div class="board-box">
        <h4>Interview Board Composition (5-Member Board)</h4>
        <table class="board-grid">
            <tr>
                <td style="width: 45%; border-right: 1px solid #e2e8f0;">
                    <strong>Board Chairperson:</strong><br>
                    @php $chair = $session->chairperson(); @endphp
                    @if ($chair)
                        <span style="font-size: 9.5px; font-weight: bold; color: #0f172a;">{{ $chair->name }}</span><br>
                        <span style="color: #64748b;">{{ $chair->designation }} {{ $chair->organization ? "• {$chair->organization}" : '' }}</span>
                    @else
                        <span style="color: #94a3b8; font-style: italic;">Not assigned</span>
                    @endif
                </td>
                <td style="width: 55%; padding-left: 10px;">
                    <strong>Board Members:</strong><br>
                    @php $members = $session->regularBoardMembers(); @endphp
                    @forelse ($members as $m)
                        <span style="font-weight: 600;">{{ $loop->iteration }}. {{ $m->boardMember->name }}</span>
                        <span style="color: #64748b;">({{ $m->boardMember->designation ?: 'Expert' }})</span>{{ ! $loop->last ? '; ' : '' }}
                    @empty
                        <span style="color: #94a3b8; font-style: italic;">No members added</span>
                    @endforelse
                </td>
            </tr>
            <tr>
                <td colspan="2" style="padding-top: 6px; border-top: 1px dashed #e2e8f0; font-size: 8px; color: #475569;">
                    <strong>Venue / Location:</strong> {{ $session->venue_address }} {{ $session->room_number ? "[Room: {$session->room_number}]" : '' }} |
                    <strong>Reporting Time:</strong> {{ $session->formatted_reporting_time ?: '30 mins before session' }} |
                    <strong>Total Aspirants:</strong> {{ $session->candidates->count() }} Candidates
                </td>
            </tr>
        </table>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 4%; text-align: center;">Seq</th>
                <th style="width: 10%;">Student ID</th>
                <th style="width: 22%;">Candidate Name</th>
                <th style="width: 14%;">Attending Mode</th>
                <th style="width: 15%;">Scheduled Slot</th>
                <th style="width: 20%;">Venue / Meeting Details</th>
                <th style="width: 8%;">Status</th>
                <th style="width: 7%; text-align: center;">Marks / Sign</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($session->candidates as $candidate)
                <tr>
                    <td style="text-align: center; font-weight: bold;">#{{ $candidate->sequence_number }}</td>
                    <td style="font-family: monospace; font-size: 8px;">{{ $candidate->user->student_id ?: 'N/A' }}</td>
                    <td>
                        <strong>{{ $candidate->user->name }}</strong>
                        @if ($candidate->batch)
                            <br><span style="font-size: 7.5px; color: #64748b;">{{ $candidate->batch->name }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($candidate->isOnline())
                            <span class="badge badge-online">Online</span>
                            <span style="font-size: 7.5px; color: #64748b; display: block;">{{ $candidate->platform ?: 'Video' }}</span>
                        @else
                            <span class="badge badge-offline">Offline</span>
                            <span style="font-size: 7.5px; color: #64748b; display: block;">In-Person</span>
                        @endif
                    </td>
                    <td>
                        <strong>{{ $candidate->formatted_slot_time }}</strong>
                    </td>
                    <td style="font-size: 7.5px;">
                        @if ($candidate->isOnline())
                            {{ $candidate->meeting_link ? \Illuminate\Support\Str::limit($candidate->meeting_link, 32) : 'Link Pending' }}
                            @if ($candidate->meeting_password)
                                <br>Pwd: {{ $candidate->meeting_password }}
                            @endif
                        @else
                            {{ $candidate->room_number ?: ($session->room_number ?: 'Board Room') }}
                        @endif
                    </td>
                    <td>
                        <span class="badge badge-{{ $candidate->status }}">{{ ucfirst($candidate->status) }}</span>
                    </td>
                    <td style="text-align: center; border-left: 1px solid #cbd5e1;">
                        {{ $candidate->interview_score !== null ? number_format($candidate->interview_score, 1) : '____' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="text-align: center; padding: 20px; color: #94a3b8;">
                        No candidates assigned to this interview session.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td class="sig-col">
                <strong>{{ $chair ? $chair->name : 'Board Chairperson' }}</strong><br>
                Chairperson Signature
            </td>
            <td class="sig-col">
                <strong>Board Member 1</strong><br>
                Member Signature
            </td>
            <td class="sig-col">
                <strong>Board Member 2</strong><br>
                Member Signature
            </td>
            <td class="sig-col">
                <strong>Mock Interview Coordinator</strong><br>
                Administrative Signature
            </td>
        </tr>
    </table>
</body>
</html>

