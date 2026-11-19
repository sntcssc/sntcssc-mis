<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Marksheet - {{ $student->name }} - {{ $test->code }}</title>
    <style>
        @page {
            margin: 20px 25px;
        }
        body {
            font-family: DejaVu Sans, 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1e293b;
            line-height: 1.4;
            background-color: #ffffff;
        }
        .outer-border {
            border: 2px solid #0369a1;
            padding: 18px 20px;
            position: relative;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0284c7;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }
        .inst-name {
            font-size: 16px;
            font-weight: bold;
            color: #0369a1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }
        .inst-sub {
            font-size: 9.5px;
            color: #475569;
            margin: 2px 0 0 0;
            text-transform: uppercase;
        }
        .doc-title {
            display: inline-block;
            background-color: #0284c7;
            color: #ffffff;
            font-size: 12px;
            font-weight: bold;
            padding: 4px 18px;
            border-radius: 4px;
            margin-top: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .grid-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .grid-table td {
            padding: 4px 6px;
            vertical-align: top;
        }
        .label {
            font-size: 9px;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
        }
        .val {
            font-size: 11px;
            font-weight: 600;
            color: #0f172a;
        }
        .section-heading {
            font-size: 11px;
            font-weight: bold;
            color: #0369a1;
            text-transform: uppercase;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 3px;
            margin: 10px 0 6px 0;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0 14px 0;
        }
        table.data-table th {
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            text-align: left;
        }
        table.data-table td {
            border: 1px solid #cbd5e1;
            padding: 6px;
            font-size: 10px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .score-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 14px;
        }
        .score-pill {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 13px;
            font-weight: bold;
            padding: 4px 10px;
            border-radius: 4px;
        }
        .rank-badge {
            display: inline-block;
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #f59e0b;
            font-size: 12px;
            font-weight: bold;
            padding: 4px 10px;
            border-radius: 4px;
        }
        .footer-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        .footer-table td {
            vertical-align: middle;
            padding: 0 8px;
        }
        .watermark {
            position: absolute;
            top: 45%;
            left: 20%;
            font-size: 55px;
            color: rgba(3, 105, 161, 0.05);
            transform: rotate(-30deg);
            font-weight: bold;
            pointer-events: none;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="outer-border">
        <div class="watermark">SNTCSSC</div>

        <!-- Header -->
        <div class="header">
            <h1 class="inst-name">Satyendra Nath Tagore Civil Services Study Centre</h1>
            <p class="inst-sub">Government of West Bengal | Apex Centre for Civil Services Examination Guidance</p>
            <p class="inst-sub">Salt Lake Campus, Block FC, Sector III, Salt Lake City, Kolkata - 700106</p>
            <div class="doc-title">Official Examination Performance Marksheet</div>
        </div>

        <!-- Student & Exam Details -->
        <table class="grid-table">
            <tr>
                <td width="50%">
                    <div class="label">Candidate Name</div>
                    <div class="val" style="font-size: 13px; color: #0284c7;">{{ $student->name }}</div>
                </td>
                <td width="25%">
                    <div class="label">Roll Number</div>
                    <div class="val">{{ $result->roll_number ?: ($student->student_id ?: 'N/A') }}</div>
                </td>
                <td width="25%">
                    <div class="label">Student ID</div>
                    <div class="val">{{ $student->student_id ?: 'SNTCSSC-' . $student->id }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="label">Enrolled Course</div>
                    <div class="val">{{ $test->course?->name ?? 'UPSC Civil Services' }} ({{ $test->course?->code }})</div>
                </td>
                <td>
                    <div class="label">Academic Batch</div>
                    <div class="val">{{ $test->batch?->name ?? 'General Batch' }}</div>
                </td>
                <td>
                    <div class="label">Section / Classroom</div>
                    <div class="val">{{ $result->section?->name ?? ($test->section?->name ?? 'All Sections') }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="label">Test Title & Code</div>
                    <div class="val">{{ $test->title }} ({{ $test->code }})</div>
                </td>
                <td>
                    <div class="label">Test Stage & Type</div>
                    <div class="val">{{ strtoupper($test->test_stage) }} &bull; {{ strtoupper($test->test_type) }}</div>
                </td>
                <td>
                    <div class="label">Exam Date & Duration</div>
                    <div class="val">{{ $test->test_date->format('d M Y') }} ({{ $test->duration_minutes }} Mins)</div>
                </td>
            </tr>
        </table>

        <!-- Marks Overview Box -->
        <div class="score-box">
            <table width="100%">
                <tr>
                    <td width="25%" align="center">
                        <div class="label">Marks Obtained</div>
                        <div class="score-pill">{{ $result->marks_obtained }} / {{ $result->total_marks }}</div>
                    </td>
                    <td width="25%" align="center">
                        <div class="label">Percentage</div>
                        <div style="font-size: 16px; font-weight: bold; color: #0f172a; margin-top: 4px;">{{ $result->percentage }}%</div>
                    </td>
                    <td width="25%" align="center">
                        <div class="label">Merit Rank</div>
                        <div class="rank-badge">
                            @if ($result->rank)
                                Rank #{{ $result->rank }}
                            @else
                                &mdash;
                            @endif
                        </div>
                    </td>
                    <td width="25%" align="center">
                        <div class="label">Percentile</div>
                        <div style="font-size: 15px; font-weight: bold; color: #475569; margin-top: 4px;">
                            {{ $result->percentile !== null ? $result->percentile . '%' : 'N/A' }}
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Detailed Breakdown -->
        <div class="section-heading">Detailed Evaluation Breakdown</div>

        @if ($test->isMcq())
            <table class="data-table">
                <thead>
                    <tr>
                        <th width="15%">Total Questions</th>
                        <th width="15%" class="text-center">Attempted</th>
                        <th width="14%" class="text-center">Correct (✓)</th>
                        <th width="14%" class="text-center">Incorrect (✗)</th>
                        <th width="14%" class="text-center">Unattempted</th>
                        <th width="14%" class="text-right">Positive Marks</th>
                        <th width="14%" class="text-right">Negative Penalty</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="font-bold">{{ $result->total_questions }}</td>
                        <td class="text-center font-bold">{{ $result->attempted }}</td>
                        <td class="text-center font-bold" style="color: #16a34a;">{{ $result->right_answers }}</td>
                        <td class="text-center font-bold" style="color: #dc2626;">{{ $result->wrong_answers }}</td>
                        <td class="text-center font-bold" style="color: #64748b;">{{ $result->blank_answers }}</td>
                        <td class="text-right font-bold" style="color: #16a34a;">+{{ $result->positive_marks }}</td>
                        <td class="text-right font-bold" style="color: #dc2626;">-{{ $result->negative_marks }}</td>
                    </tr>
                </tbody>
            </table>
        @endif

        <!-- Subject Breakdown if test has multiple subjects -->
        @if ($test->subjects->isNotEmpty())
            <div class="section-heading">Subject-Wise Distribution</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th width="8%">#</th>
                        <th>Subject Name</th>
                        <th width="18%" class="text-center">Questions Count</th>
                        <th width="18%" class="text-center">Marking Scheme</th>
                        <th width="18%" class="text-right">Maximum Marks</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($test->subjects as $idx => $subj)
                        <tr>
                            <td>{{ $idx + 1 }}</td>
                            <td class="font-bold">{{ $subj->subject_name }} ({{ $subj->subject_code ?? 'GEN' }})</td>
                            <td class="text-center">{{ $subj->questions_count }} Qs</td>
                            <td class="text-center">+{{ $subj->marks_per_question }} / -{{ $subj->negative_marking }}</td>
                            <td class="text-right font-bold">{{ $subj->max_marks }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <!-- Performance Feedback & Remarks -->
        <table class="data-table">
            <tr>
                <td width="25%" style="background-color: #f8fafc; font-weight: bold;">Result Classification</td>
                <td>
                    @if ($result->is_passed)
                        <span style="color: #16a34a; font-weight: bold;">PASSED / QUALIFIED (Above Benchmark)</span>
                    @else
                        <span style="color: #dc2626; font-weight: bold;">ATTENTION REQUIRED (Below Qualifying Cutoff)</span>
                    @endif
                    @if ($result->is_top_ten)
                        <span style="background-color: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 3px; font-weight: bold; margin-left: 8px;">★ TOP 10 RANKER</span>
                    @endif
                </td>
            </tr>
            @if ($result->remarks)
                <tr>
                    <td style="background-color: #f8fafc; font-weight: bold;">Faculty Observations</td>
                    <td>{{ $result->remarks }}</td>
                </tr>
            @endif
            @if ($result->feedback)
                <tr>
                    <td style="background-color: #f8fafc; font-weight: bold;">Academic Guidance</td>
                    <td>{{ $result->feedback }}</td>
                </tr>
            @endif
        </table>

        <!-- Digital Verification & Official Footer -->
        <table class="footer-table">
            <tr>
                <td width="22%" align="center">
                    @if (!empty($qrDataUri))
                        <img src="{{ $qrDataUri }}" width="68" height="68" alt="QR Code"/>
                        <div style="font-size: 7.5px; color: #64748b; margin-top: 2px;">Scan to Verify Digitally</div>
                    @else
                        <div style="border: 1px dashed #cbd5e1; height: 55px; width: 55px; line-height: 55px; font-size: 8px; color: #94a3b8; text-align: center;">Official Seal</div>
                    @endif
                </td>
                <td width="78%" style="padding-left: 15px;">
                    <div style="font-size: 9.5px; font-weight: bold; color: #0369a1; text-transform: uppercase; letter-spacing: 0.5px;">
                        Satyendranath Tagore Civil Services Study Centre
                    </div>
                    <div style="font-size: 8.5px; color: #475569; margin-top: 1px;">
                        Government of West Bengal &bull; NSATI Campus, Salt Lake, Kolkata
                    </div>
                    <div style="font-size: 8.5px; color: #64748b; margin-top: 3px;">
                        Issued on: <strong>{{ $generatedAt->format('d/m/Y H:i') }} IST</strong> &bull; Verification Hash: <code style="font-family: monospace; color: #0f172a;">{{ substr(md5($result->uuid . $test->code), 0, 16) }}</code>
                    </div>
                    <div style="margin-top: 6px; padding-top: 4px; border-top: 1px solid #cbd5e1; font-size: 8.5px; color: #0f172a; font-weight: bold;">
                        * This is a computer-generated document and does not require a physical signature.
                    </div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>

