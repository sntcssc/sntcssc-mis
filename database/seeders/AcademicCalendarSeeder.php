<?php

namespace Database\Seeders;

use App\Models\AcademicCalendar;
use App\Models\AcademicCalendarActivity;
use App\Models\AcademicCalendarVersion;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AcademicCalendarSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Super Administrator', 'Administrator']))->first()
            ?? User::first();

        // 1. Current Academic Calendar 2026-2027
        $calendar2627 = AcademicCalendar::updateOrCreate(
            ['academic_year' => '2026-2027'],
            [
                'calendar_code' => 'AC-2026-27',
                'title' => 'Annual Institutional Academic Calendar (2026-2027)',
                'theme_or_motto' => 'Excellence in Civil Services Guidance & Administrative Leadership',
                'start_date' => Carbon::parse('2026-04-01'),
                'end_date' => Carbon::parse('2027-03-31'),
                'total_working_days' => 248,
                'total_instructional_days' => 195,
                'description' => 'Comprehensive master schedule outlining foundational coursework, test series, guest lectures, state holiday recesses, and UPSC Mock Interview panels for the academic year 2026-2027.',
                'status' => 'published',
                'is_current' => true,
                'created_by' => $admin?->id,
                'updated_by' => $admin?->id,
            ]
        );

        // Generate sample PDF files in storage for version control downloads
        $folder = 'academic_calendars/'.Str::slug($calendar2627->calendar_code);
        Storage::disk('public')->makeDirectory($folder);

        // Helper to generate placeholder PDF
        $generatePlaceholderPdf = function (string $filename, string $title, string $version, string $notes) use ($folder) {
            $path = $folder.'/'.$filename;
            if (! Storage::disk('public')->exists($path)) {
                $html = "
                <div style='font-family: sans-serif; padding: 40px; border: 2px solid #333;'>
                    <h1 style='color: #1e3a8a; text-align: center; margin-bottom: 5px;'>Satyendranath Tagore Civil Services Study Centre</h1>
                    <h3 style='color: #475569; text-align: center; margin-top: 0;'>Government of West Bengal</h3>
                    <hr style='border: 1px solid #cbd5e1; margin: 20px 0;'/>
                    <h2 style='color: #0f172a;'>{$title}</h2>
                    <p><strong>Version:</strong> {$version}</p>
                    <p><strong>Release Date:</strong> ".now()->format('d M Y')."</p>
                    <p><strong>Approved By:</strong> Academic Advisory Council</p>
                    <div style='background: #f8fafc; border-left: 4px solid #3b82f6; padding: 15px; margin: 20px 0;'>
                        <h4 style='margin: 0 0 10px 0;'>Revision Notes:</h4>
                        <p style='margin: 0;'>{$notes}</p>
                    </div>
                    <p style='color: #64748b; font-size: 12px; margin-top: 40px;'>This is an official certified copy of the Academic Calendar preserved in the institutional document repository.</p>
                </div>";
                $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
                Storage::disk('public')->put($path, $pdf->output());
            }

            return $path;
        };

        // Create Version 1.0 (Inaugural)
        $pathV1 = $generatePlaceholderPdf(
            'academic_calendar_v1.0.pdf',
            'Annual Academic Calendar 2026-2027 (Initial Approval)',
            'v1.0',
            'Official approved schedule adopted during the annual council assembly.'
        );
        $v1 = AcademicCalendarVersion::updateOrCreate(
            ['academic_calendar_id' => $calendar2627->id, 'version_code' => 'v1.0'],
            [
                'revision_number' => 1,
                'version_title' => 'Initial Approved Annual Calendar',
                'file_path' => $pathV1,
                'file_name' => 'SNTCSSC_Academic_Calendar_2026_27_v1.0.pdf',
                'file_size' => Storage::disk('public')->size($pathV1),
                'file_mime' => 'application/pdf',
                'change_summary' => 'Initial publication of the Academic Calendar for session 2026-2027.',
                'approved_by' => 'Academic Advisory Council',
                'approval_date' => Carbon::parse('2026-03-25'),
                'effective_from' => Carbon::parse('2026-04-01'),
                'is_current' => false,
                'download_count' => 18,
                'uploaded_by' => $admin?->id,
            ]
        );

        // Create Version 1.1 (Mid-Term Monsoon & Puja Revision)
        $pathV11 = $generatePlaceholderPdf(
            'academic_calendar_v1.1.pdf',
            'Annual Academic Calendar 2026-2027 (Puja & Monsoon Revision)',
            'v1.1',
            'Adjustment of Durga Puja vacation dates and rescheduling of Prelims Mock Test 3.'
        );
        $v11 = AcademicCalendarVersion::updateOrCreate(
            ['academic_calendar_id' => $calendar2627->id, 'version_code' => 'v1.1'],
            [
                'revision_number' => 2,
                'version_title' => 'Mid-Session Vacation & Test Rescheduling',
                'file_path' => $pathV11,
                'file_name' => 'SNTCSSC_Academic_Calendar_2026_27_v1.1.pdf',
                'file_size' => Storage::disk('public')->size($pathV11),
                'file_mime' => 'application/pdf',
                'change_summary' => 'Adjusted Durga Puja recess timeline to synchronize with state government gazette and shifted Prelims Test 3 by one week.',
                'approved_by' => 'Director & Academic Board',
                'approval_date' => Carbon::parse('2026-08-14'),
                'effective_from' => Carbon::parse('2026-08-16'),
                'is_current' => false,
                'download_count' => 42,
                'uploaded_by' => $admin?->id,
            ]
        );

        // Create Version 1.2 (Current Active Version)
        $pathV12 = $generatePlaceholderPdf(
            'academic_calendar_v1.2.pdf',
            'Annual Academic Calendar 2026-2027 (Final Revised Schedule)',
            'v1.2',
            'Inclusion of specialized UPSC Mock Interview cycles and Winter Orientation bootcamps.'
        );
        $v12 = AcademicCalendarVersion::updateOrCreate(
            ['academic_calendar_id' => $calendar2627->id, 'version_code' => 'v1.2'],
            [
                'revision_number' => 3,
                'version_title' => 'Current Approved Revised Calendar (Interview & Winter Schedule)',
                'file_path' => $pathV12,
                'file_name' => 'SNTCSSC_Academic_Calendar_2026_27_v1.2_Revised.pdf',
                'file_size' => Storage::disk('public')->size($pathV12),
                'file_mime' => 'application/pdf',
                'change_summary' => 'Incorporated national IAS/IPS mentor workshops, UPSC Mock Interview Phase 1 & 2 timelines, and updated classroom seatings.',
                'approved_by' => 'Apex Governing Body',
                'approval_date' => Carbon::parse('2026-10-20'),
                'effective_from' => Carbon::parse('2026-10-25'),
                'is_current' => true,
                'download_count' => 87,
                'uploaded_by' => $admin?->id,
            ]
        );

        $calendar2627->update(['current_version_id' => $v12->id]);

        // Seed 12-Month Activities for 2026-2027
        $sampleActivities = [
            // April 2026
            [
                'title' => 'Academic Session Commencement & Induction',
                'category' => 'academic',
                'start_date' => '2026-04-01',
                'end_date' => '2026-04-03',
                'target_audience' => 'students',
                'department' => 'Academics',
                'venue_mode' => 'hybrid',
                'venue_or_link' => 'Main Auditorium & Zoom Live Stream',
                'status' => 'completed',
                'description' => 'Inauguration of new academic batches with address by eminent civil servants and syllabus orientation.',
            ],
            [
                'title' => 'Bengali New Year (Poila Boishakh) Holiday',
                'category' => 'holiday',
                'start_date' => '2026-04-15',
                'end_date' => null,
                'target_audience' => 'all',
                'department' => 'Administration',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Institute Closed',
                'status' => 'completed',
                'description' => 'State institutional holiday on the occasion of Noboborsho.',
            ],
            // May 2026
            [
                'title' => 'UPSC Prelims Mock Assessment Drive - Series 1',
                'category' => 'examination',
                'start_date' => '2026-05-10',
                'end_date' => '2026-05-10',
                'target_audience' => 'students',
                'department' => 'Examination Cell',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Exam Halls A, B & C',
                'status' => 'completed',
                'description' => 'General Studies Paper I & CSAT Paper II full-length simulated simulation.',
            ],
            [
                'title' => 'Rabindra Jayanti Observance & Cultural Meet',
                'category' => 'sports_cultural',
                'start_date' => '2026-05-09',
                'end_date' => null,
                'target_audience' => 'all',
                'department' => 'Student Affairs',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Seminar Hall 1',
                'status' => 'completed',
                'description' => 'Commemoration of the birth anniversary of Kabiguru Rabindranath Tagore.',
            ],
            // June 2026
            [
                'title' => 'Summer Intensive Answer Writing Bootcamp',
                'category' => 'academic',
                'start_date' => '2026-06-08',
                'end_date' => '2026-06-19',
                'target_audience' => 'students',
                'department' => 'Academics',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Classrooms 101-105',
                'status' => 'completed',
                'description' => 'Daily answer writing and structured evaluation under senior mentor guidance.',
            ],
            // July 2026
            [
                'title' => 'Mid-Year Academic Progress Review Meeting',
                'category' => 'administrative',
                'start_date' => '2026-07-15',
                'end_date' => null,
                'target_audience' => 'faculty',
                'department' => 'Administration',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Board Room',
                'status' => 'completed',
                'description' => 'Council review of syllabus coverage, attendance statistics, and student performance.',
            ],
            // August 2026
            [
                'title' => 'Independence Day Flag Hoisting & Patriotic Discourse',
                'category' => 'holiday',
                'start_date' => '2026-08-15',
                'end_date' => null,
                'target_audience' => 'all',
                'department' => 'Administration',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Campus Grounds',
                'status' => 'completed',
                'description' => 'National Celebration with ceremonial flag hoisting, cultural presentations, and student address.',
            ],
            // September 2026
            [
                'title' => 'Teachers Day Special Seminar & Faculty Recognition',
                'category' => 'co_curricular',
                'start_date' => '2026-09-05',
                'end_date' => null,
                'target_audience' => 'all',
                'department' => 'Academics',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Auditorium',
                'status' => 'completed',
                'description' => 'Felicitating faculty and guest professors followed by a pedagogy workshop.',
            ],
            // October 2026
            [
                'title' => 'Durga Puja & Festive Autumn Recess',
                'category' => 'holiday',
                'start_date' => '2026-10-18',
                'end_date' => '2026-10-28',
                'target_audience' => 'all',
                'department' => 'Administration',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Campus Closed',
                'status' => 'completed',
                'description' => 'Annual autumn recess for students and staff on the occasion of Durga Puja and Vijaya Dashami.',
            ],
            // November 2026
            [
                'title' => 'UPSC Mock Interview Cycle - Phase I',
                'category' => 'examination',
                'start_date' => '2026-11-10',
                'end_date' => '2026-11-14',
                'target_audience' => 'aspirants',
                'department' => 'Examination Cell',
                'venue_mode' => 'hybrid',
                'venue_or_link' => 'Executive Boardroom & Online Panel Room',
                'status' => 'ongoing',
                'description' => 'Mock Personality Tests conducted by retired bureaucrats, diplomats, and senior academicians.',
            ],
            [
                'title' => 'National Colloquium on Public Policy & Administrative Reforms',
                'category' => 'co_curricular',
                'start_date' => '2026-11-25',
                'end_date' => '2026-11-26',
                'target_audience' => 'all',
                'department' => 'Academics',
                'venue_mode' => 'hybrid',
                'venue_or_link' => 'Main Conference Hall',
                'status' => 'planned',
                'description' => 'National academic symposium featuring keynote addresses by distinguished administrative officers.',
            ],
            // December 2026
            [
                'title' => 'Winter Vacation & Library Self-Study Period',
                'category' => 'holiday',
                'start_date' => '2026-12-25',
                'end_date' => '2027-01-01',
                'target_audience' => 'all',
                'department' => 'Administration',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Library reading room remains accessible 9 AM - 6 PM',
                'status' => 'planned',
                'description' => 'Year-end recess with special reading hall access for prelims aspirants.',
            ],
            // January 2027
            [
                'title' => 'Republic Day Ceremonial Celebration',
                'category' => 'holiday',
                'start_date' => '2027-01-26',
                'end_date' => null,
                'target_audience' => 'all',
                'department' => 'Administration',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Campus Plaza',
                'status' => 'planned',
                'description' => '78th Republic Day commemoration and felicitation of student achievers.',
            ],
            // February 2027
            [
                'title' => 'UPSC Prelims Final Sprint Assessment - Mock Series 5',
                'category' => 'examination',
                'start_date' => '2027-02-14',
                'end_date' => '2027-02-14',
                'target_audience' => 'students',
                'department' => 'Examination Cell',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Exam Halls A, B & C',
                'status' => 'planned',
                'description' => 'Comprehensive state-level benchmark test simulating actual UPSC exam day conditions.',
            ],
            // March 2027
            [
                'title' => 'Annual Convocation, Alumni Conclave & Valedictory Session',
                'category' => 'academic',
                'start_date' => '2027-03-20',
                'end_date' => '2027-03-21',
                'target_audience' => 'all',
                'department' => 'Student Affairs',
                'venue_mode' => 'offline',
                'venue_or_link' => 'Grand Auditorium',
                'status' => 'planned',
                'description' => 'Concluding valedictory assembly, alumni fellowship meet, and student send-off.',
            ],
        ];

        foreach ($sampleActivities as $index => $act) {
            $startDate = Carbon::parse($act['start_date']);
            $endDate = ! empty($act['end_date']) ? Carbon::parse($act['end_date']) : null;
            $code = 'ACT-'.$startDate->format('Y').'-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);

            AcademicCalendarActivity::updateOrCreate(
                [
                    'activity_code' => $code,
                ],
                [
                    'academic_calendar_id' => $calendar2627->id,
                    'academic_calendar_version_id' => $v12->id,
                    'title' => $act['title'],
                    'start_date' => $startDate,
                    'category' => $act['category'],
                    'end_date' => $endDate?->toDateString(),
                    'month' => (int) $startDate->format('n'),
                    'year' => (int) $startDate->format('Y'),
                    'target_audience' => $act['target_audience'],
                    'department' => $act['department'],
                    'venue_mode' => $act['venue_mode'],
                    'venue_or_link' => $act['venue_or_link'],
                    'is_tentative' => false,
                    'status' => $act['status'],
                    'description' => $act['description'],
                    'display_order' => $index + 1,
                    'created_by' => $admin?->id,
                    'updated_by' => $admin?->id,
                ]
            );
        }

        // 2. Previous Academic Calendar 2025-2026 (Archived with historical versions)
        $calendar2526 = AcademicCalendar::updateOrCreate(
            ['academic_year' => '2025-2026'],
            [
                'calendar_code' => 'AC-2025-26',
                'title' => 'Annual Institutional Academic Calendar (2025-2026)',
                'theme_or_motto' => 'Building Nation Builders & Civil Servants',
                'start_date' => Carbon::parse('2025-04-01'),
                'end_date' => Carbon::parse('2026-03-31'),
                'total_working_days' => 245,
                'total_instructional_days' => 192,
                'description' => 'Official calendar for academic session 2025-2026, preserved for historical reference and institutional accreditation.',
                'status' => 'archived',
                'is_current' => false,
                'created_by' => $admin?->id,
                'updated_by' => $admin?->id,
            ]
        );

        $folder25 = 'academic_calendars/'.Str::slug($calendar2526->calendar_code);
        Storage::disk('public')->makeDirectory($folder25);

        $pathPrevV1 = $generatePlaceholderPdf(
            'academic_calendar_2025_v1.0.pdf',
            'Annual Academic Calendar 2025-2026 (Original)',
            'v1.0',
            'Original approved schedule for 2025-2026.'
        );
        AcademicCalendarVersion::updateOrCreate(
            ['academic_calendar_id' => $calendar2526->id, 'version_code' => 'v1.0'],
            [
                'revision_number' => 1,
                'version_title' => 'Approved Annual Calendar 2025-2026',
                'file_path' => $pathPrevV1,
                'file_name' => 'SNTCSSC_Academic_Calendar_2025_26_v1.0.pdf',
                'file_size' => Storage::disk('public')->size($pathPrevV1),
                'file_mime' => 'application/pdf',
                'change_summary' => 'Inaugural publication for academic session 2025-2026.',
                'approved_by' => 'Academic Council',
                'approval_date' => Carbon::parse('2025-03-28'),
                'effective_from' => Carbon::parse('2025-04-01'),
                'is_current' => true,
                'download_count' => 134,
                'uploaded_by' => $admin?->id,
            ]
        );
    }
}
