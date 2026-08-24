<?php

namespace Database\Seeders;

use App\Models\ContactDepartment;
use App\Models\ContactSubject;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContactMasterSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed departments and subjects for contact submissions.
     */
    public function run(): void
    {
        $departments = [
            [
                'name' => 'Admissions & Counseling',
                'code' => 'ADMISSIONS',
                'email' => 'admissions@sntcssc.in',
                'description' => 'Inquiries regarding UPSC CSE batches, eligibility criteria, screening tests, and admission selection lists.',
                'sort_order' => 1,
                'subjects' => [
                    ['name' => 'UPSC CSE Foundation Batch Admission', 'code' => 'UPSC_FOUNDATION'],
                    ['name' => 'WBCS Integrated Course Enrollment', 'code' => 'WBCS_ENROLL'],
                    ['name' => 'Scholarship & Fee Concession Eligibility', 'code' => 'SCHOLARSHIP'],
                    ['name' => 'Hostel & Library Facility Admission', 'code' => 'HOSTEL_LIB'],
                ],
            ],
            [
                'name' => 'Academic & Faculty Guidance',
                'code' => 'ACADEMIC',
                'email' => 'academics@sntcssc.in',
                'description' => 'Lecture schedules, faculty mentoring, optional subject selection, and study material distribution.',
                'sort_order' => 2,
                'subjects' => [
                    ['name' => 'Classroom Schedule & Timetable Query', 'code' => 'TIMETABLE'],
                    ['name' => 'Faculty Mentorship & Answer Evaluation', 'code' => 'MENTORSHIP'],
                    ['name' => 'Optional Subject Strategy Session', 'code' => 'OPTIONAL_SUBJECT'],
                    ['name' => 'Study Material & Book Bank Request', 'code' => 'STUDY_MATERIAL'],
                ],
            ],
            [
                'name' => 'Examination & Test Series',
                'code' => 'EXAMS',
                'email' => 'exams@sntcssc.in',
                'description' => 'Prelims mock tests, Mains descriptive answer booklet evaluation, rank lists, and test scorecards.',
                'sort_order' => 3,
                'subjects' => [
                    ['name' => 'Prelims All-India Mock Test Series', 'code' => 'PRELIMS_MOCK'],
                    ['name' => 'Mains Answer Booklet Evaluation Status', 'code' => 'MAINS_EVAL'],
                    ['name' => 'Simulated Personality Test / Mock Interview', 'code' => 'MOCK_INTERVIEW'],
                ],
            ],
            [
                'name' => 'Fee & Accounts Department',
                'code' => 'ACCOUNTS',
                'email' => 'accounts@sntcssc.in',
                'description' => 'Fee payment receipts, installment schedules, online gateway issues, and refund requests.',
                'sort_order' => 4,
                'subjects' => [
                    ['name' => 'Online Fee Payment / Transaction Issue', 'code' => 'PAYMENT_ISSUE'],
                    ['name' => 'Fee Receipt & Installment Acknowledgement', 'code' => 'FEE_RECEIPT'],
                    ['name' => 'Course Withdrawal & Refund Request', 'code' => 'REFUND_REQUEST'],
                ],
            ],
            [
                'name' => 'Technical Support & MIS Helpdesk',
                'code' => 'TECH_SUPPORT',
                'email' => 'support@sntcssc.in',
                'description' => 'Student portal login, OTP verification, password reset, mobile app, and digital dashboard assistance.',
                'sort_order' => 5,
                'subjects' => [
                    ['name' => 'Student Portal Login & OTP Issue', 'code' => 'LOGIN_OTP'],
                    ['name' => 'Biometric Passkey / 2FA Reset', 'code' => 'PASSKEY_2FA'],
                    ['name' => 'Profile Data Correction Request', 'code' => 'PROFILE_CORRECTION'],
                    ['name' => 'General Technical Assistance', 'code' => 'GENERAL_TECH'],
                ],
            ],
            [
                'name' => 'General Administration & Public Relations',
                'code' => 'GENERAL_ADMIN',
                'email' => 'info@sntcssc.in',
                'description' => 'Institutional visits, official correspondence, media queries, and general public inquiries.',
                'sort_order' => 6,
                'subjects' => [
                    ['name' => 'Campus Visit & Seminar Booking', 'code' => 'CAMPUS_VISIT'],
                    ['name' => 'Official Collaboration & RTI Query', 'code' => 'COLLAB_RTI'],
                    ['name' => 'Other General Inquiry', 'code' => 'OTHER_INQUIRY'],
                ],
            ],
        ];

        foreach ($departments as $deptData) {
            DB::transaction(function () use ($deptData) {
                $subjects = $deptData['subjects'] ?? [];
                unset($deptData['subjects']);

                $department = ContactDepartment::withTrashed()->firstOrNew(['code' => $deptData['code']]);
                $department->fill($deptData);
                $department->is_active = true;
                $department->save();

                if ($department->trashed()) {
                    $department->restore();
                }

                foreach ($subjects as $idx => $subjData) {
                    $subject = ContactSubject::withTrashed()->firstOrNew([
                        'department_id' => $department->id,
                        'name' => $subjData['name'],
                    ]);

                    $subject->code = $subjData['code'] ?? null;
                    $subject->sort_order = $idx + 1;
                    $subject->is_active = true;
                    $subject->save();

                    if ($subject->trashed()) {
                        $subject->restore();
                    }
                }
            });
        }
    }
}
