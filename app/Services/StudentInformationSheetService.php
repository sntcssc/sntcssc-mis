<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentInformationSheetService
{
    /**
     * Get institutions & sheet metadata settings.
     *
     * @return array<string, mixed>
     */
    public static function getMetadata(): array
    {
        return [
            'institution_name' => (string) Setting::get('student_id_card.institution_name', Setting::appName() ?: 'Satyendranath Tagore Civil Services Study Centre'),
            'institution_sub' => (string) Setting::get('student_id_card.institution_sub', 'Government of West Bengal'),
            'institution_address' => (string) Setting::get('system.general.address', 'Netaji Subhas Administrative Training Institute Campus, Block FC, Sector III, Salt Lake, Kolkata - 700106'),
            'generated_at' => now()->format('d M Y, h:i A'),
        ];
    }

    /**
     * Determine if a given file path/URL is an image.
     */
    public static function isImageFile(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        $cleanPath = parse_url($path, PHP_URL_PATH) ?? $path;
        $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true);
    }

    /**
     * Resolve an image file path to a base64 data URI for offline / DomPDF rendering.
     */
    public static function resolveImageDataUri(?string $storagePath, ?string $fallbackUrl = null): ?string
    {
        if ($storagePath) {
            $cleanStoragePath = preg_replace('/^\/?storage\//', '', $storagePath);
            if (Storage::disk('public')->exists($cleanStoragePath)) {
                try {
                    $mime = Storage::disk('public')->mimeType($cleanStoragePath) ?: 'image/jpeg';
                    $content = Storage::disk('public')->get($cleanStoragePath);

                    return 'data:'.$mime.';base64,'.base64_encode($content);
                } catch (\Throwable) {
                    // Return fallback URL if reading content fails
                }
            }
        }

        return $fallbackUrl;
    }

    /**
     * Prepare complete structured information payload for a single student.
     *
     * @return array<string, mixed>
     */
    public static function getStudentData(User $student, bool $includeDocuments = false): array
    {
        $student->loadMissing([
            'studentProfile',
            'studentAddresses',
            'studentEducations' => fn ($q) => $q->orderBy('passing_year', 'desc')->orderBy('id', 'desc'),
            'studentEmployments' => fn ($q) => $q->orderBy('from_date', 'desc')->orderBy('id', 'desc'),
            'studentOtherDetails',
            'upscAttempts' => fn ($q) => $q->orderBy('exam_year', 'desc')->orderBy('id', 'desc'),
            'studentDocuments' => fn ($q) => $q->orderBy('created_at', 'desc'),
            'enrollments.batch',
            'enrollments.course',
            'enrollments.section',
            'activeEnrollments.batch',
            'activeEnrollments.course',
            'activeEnrollments.section',
            'studentDisciplinaryActions' => fn ($q) => $q->active(),
        ]);

        $profile = $student->studentProfile;
        $addresses = $student->studentAddresses;
        $permAddress = $addresses->firstWhere('address_type', 'Permanent') ?? $addresses->first();
        $corrAddress = $addresses->firstWhere('address_type', 'Correspondence') ?? $addresses->firstWhere('address_type', 'Present');

        $dob = $student->dob ?? ($profile?->dob ? Carbon::parse($profile->dob) : null);
        $age = $dob ? $dob->age : null;

        // Photo handling: check user avatar first, fallback to student document photo
        $photoUrl = $student->avatarUrl();
        $avatarPath = $student->avatar;

        if (empty($photoUrl)) {
            $photoDoc = $student->studentDocuments->first(function ($doc) {
                $type = strtolower((string) ($doc->document_type ?? $doc->type ?? ''));

                return in_array($type, ['photo', 'photograph', 'passport photo', 'avatar', 'student photo'], true)
                    || str_contains($type, 'photo');
            });

            if ($photoDoc) {
                $avatarPath = $photoDoc->file_path;
                $photoUrl = $photoDoc->file_url ?: ($photoDoc->file_path ? Storage::disk('public')->url($photoDoc->file_path) : null);
            }
        }

        $photoDataUri = self::resolveImageDataUri($avatarPath, $photoUrl);

        // Documents preparation
        $documentsList = $student->studentDocuments->map(function ($doc) use ($includeDocuments) {
            $isImage = self::isImageFile($doc->file_name ?? $doc->file_path ?? $doc->file_url);
            $webUrl = $doc->file_url ?: ($doc->file_path ? Storage::disk('public')->url($doc->file_path) : null);
            $dataUri = null;
            if ($includeDocuments && $isImage && $doc->file_path) {
                $dataUri = self::resolveImageDataUri($doc->file_path, $webUrl);
            }

            return [
                'id' => $doc->id,
                'type' => $doc->document_type ?? $doc->type,
                'file_name' => $doc->file_name ?? basename($webUrl ?? 'document'),
                'file_path' => $doc->file_path,
                'file_url' => $doc->file_url,
                'web_url' => $webUrl,
                'is_image' => $isImage,
                'data_uri' => $dataUri,
                'verified' => (bool) $doc->verified,
                'verified_at' => $doc->verified_at ? $doc->verified_at->format('d M Y') : null,
                'uploaded_at' => $doc->uploaded_at ? $doc->uploaded_at->format('d M Y') : ($doc->created_at ? $doc->created_at->format('d M Y') : '—'),
            ];
        });

        // Primary enrollment
        $primaryEnrollment = $student->activeEnrollments->first() ?? $student->enrollments->first();

        return [
            'id' => $student->id,
            'student' => $student,
            'student_id' => $student->student_id ?: ($student->urn ?: 'N/A'),
            'urn' => $student->urn,
            'name' => $student->name,
            'initials' => $student->initials(),
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'email' => $student->email,
            'phone' => $student->phone,
            'whatsapp_no' => $student->whatsapp_no,
            'dob' => $dob ? $dob->format('d M Y') : '—',
            'age' => $age !== null ? $age.' Years' : '—',
            'gender' => ucfirst((string) ($student->gender ?? '—')),
            'tenth_roll' => $student->tenth_roll ?: '—',
            'id_type' => $student->id_type ?: 'Aadhaar Card',
            'id_number' => $student->id_number ?: '—',
            'status' => ucfirst((string) ($student->status ?? 'active')),
            'photo_url' => $photoUrl,
            'photo_data_uri' => $photoDataUri,
            'primary_course' => $primaryEnrollment?->course?->name ?? '—',
            'primary_batch' => $primaryEnrollment?->batch?->name ?? '—',
            'primary_roll' => $primaryEnrollment?->roll_no ?: '—',
            'profile' => [
                'father_name' => $profile?->father_name ?: '—',
                'mother_name' => $profile?->mother_name ?: '—',
                'category' => $profile?->category ?: 'UR',
                'subcaste' => $profile?->subcaste ?: '—',
                'creamy_layer' => $profile?->creamy_layer ?: '—',
                'cert_no' => $profile?->cert_no ?: '—',
                'issued_date' => $profile?->issued_date ? Carbon::parse($profile->issued_date)->format('d M Y') : '—',
                'issued_by' => $profile?->issued_by ?: '—',
                'is_pwbd' => (bool) $profile?->is_pwbd,
                'pwbd_type' => $profile?->pwbd_type ?: '—',
                'pwd_percentage' => $profile?->pwd_percentage ? $profile->pwd_percentage.'%' : '—',
                'pwbd_cert_no' => $profile?->pwbd_cert_no ?: '—',
                'pwbd_issued_date' => $profile?->pwbd_issued_date ? Carbon::parse($profile->pwbd_issued_date)->format('d M Y') : '—',
                'pwbd_issued_by' => $profile?->pwbd_issued_by ?: '—',
                'father_occupation' => $profile?->father_occupation ?: '—',
                'mother_occupation' => $profile?->mother_occupation ?: '—',
                'family_income' => $profile?->family_income ? '₹ '.number_format((float) $profile->family_income) : '—',
                'is_working_professional' => (bool) $profile?->is_working_professional,
                'occupation' => $profile?->occupation ?: ($profile?->is_working_professional ? 'Employed' : 'Student / Candidate'),
                'highest_edu' => $profile?->highest_edu ?: '—',
                'school_language' => $profile?->school_language ?: '—',
                'upsc_community' => $profile?->upsc_community ?: '—',
                'distance' => $profile?->distance ? $profile->distance.' km' : '—',
                'activities' => $profile?->activities ?: '—',
                'hobbies' => $profile?->hobbies ?: '—',
            ],
            'permanent_address' => [
                'address_line1' => $permAddress?->address_line1 ?: '',
                'address_line2' => $permAddress?->address_line2 ?: '',
                'post_office' => $permAddress?->post_office ?: '',
                'police_station' => $permAddress?->police_station ?: '',
                'subdistrict' => $permAddress?->subdistrict ?: '',
                'district' => $permAddress?->district ?: '',
                'state' => $permAddress?->state ?: 'West Bengal',
                'pin_code' => $permAddress?->pin_code ?: '',
                'formatted' => $permAddress?->formatted_address ?: '—',
            ],
            'correspondence_address' => [
                'address_line1' => $corrAddress?->address_line1 ?: ($permAddress?->address_line1 ?: ''),
                'address_line2' => $corrAddress?->address_line2 ?: ($permAddress?->address_line2 ?: ''),
                'post_office' => $corrAddress?->post_office ?: ($permAddress?->post_office ?: ''),
                'police_station' => $corrAddress?->police_station ?: ($permAddress?->police_station ?: ''),
                'subdistrict' => $corrAddress?->subdistrict ?: ($permAddress?->subdistrict ?: ''),
                'district' => $corrAddress?->district ?: ($permAddress?->district ?: ''),
                'state' => $corrAddress?->state ?: ($permAddress?->state ?: 'West Bengal'),
                'pin_code' => $corrAddress?->pin_code ?: ($permAddress?->pin_code ?: ''),
                'formatted' => $corrAddress?->formatted_address ?: ($permAddress?->formatted_address ?: 'Same as permanent address'),
            ],
            'educations' => $student->studentEducations->map(fn ($edu) => [
                'level' => $edu->level,
                'board_university' => $edu->board_university ?: '—',
                'institute' => $edu->institute ?: '—',
                'stream_subject' => $edu->stream_subject ?: '—',
                'passing_year' => $edu->passing_year ?: '—',
                'total_marks' => $edu->total_marks ?: '—',
                'marks_obtained' => $edu->marks_obtained ?: '—',
                'percentage' => $edu->percentage ? $edu->percentage.'%' : ($edu->cgpa ? 'CGPA: '.$edu->cgpa : '—'),
                'division' => $edu->division ?: '—',
                'completion_status' => $edu->completion_status ?: 'Completed',
                'is_highest' => (bool) $edu->is_highest,
            ]),
            'employments' => $student->studentEmployments->map(fn ($emp) => [
                'employer_name' => $emp->employer_name ?: '—',
                'designation' => $emp->designation ?: '—',
                'nature_of_employment' => $emp->nature_of_employment ?: 'Full-time',
                'location' => $emp->location ?: '—',
                'from_date' => $emp->from_date ? $emp->from_date->format('d M Y') : '—',
                'to_date' => $emp->is_current ? 'Present' : ($emp->to_date ? $emp->to_date->format('d M Y') : '—'),
                'is_current' => (bool) $emp->is_current,
                'description' => $emp->description ?: '—',
            ]),
            'other_details' => [
                'optional_subject' => $student->studentOtherDetails?->optional_subject ?: 'Not Assigned',
                'attempts_already_given' => $student->studentOtherDetails?->attempts_already_given ?? 0,
                'attempts_left' => $student->studentOtherDetails?->attempts_left !== null ? $student->studentOtherDetails->attempts_left : '—',
                'year_of_appearing' => $student->studentOtherDetails?->year_of_appearing ?: '—',
                'activities' => $student->studentOtherDetails?->activities ?: ($profile?->activities ?: '—'),
                'hobbies' => $student->studentOtherDetails?->hobbies ?: ($profile?->hobbies ?: '—'),
            ],
            'upsc_attempts' => $student->upscAttempts->map(fn ($att) => [
                'exam_year' => $att->exam_year,
                'roll_number' => $att->roll_number ?: '—',
                'prelims_cleared' => (bool) $att->prelims_cleared,
                'mains_cleared' => (bool) $att->mains_cleared,
                'interview_cleared' => (bool) $att->interview_cleared,
            ]),
            'enrollments' => $student->enrollments->map(fn ($enr) => [
                'enrollment_no' => $enr->enrollment_no,
                'application_no' => $enr->application_no ?: '—',
                'course' => $enr->course?->name ?? '—',
                'course_code' => $enr->course?->code ?? '—',
                'batch' => $enr->batch?->name ?? '—',
                'section' => $enr->section?->name ?? '—',
                'roll_no' => $enr->roll_no ?: '—',
                'enrolled_at' => $enr->enrolled_at ? $enr->enrolled_at->format('d M Y') : '—',
                'status' => ucfirst((string) $enr->status),
                'fee_status' => ucfirst((string) $enr->fee_status),
            ]),
            'documents' => $documentsList,
            'include_documents' => $includeDocuments,
            'active_sanction' => $student->studentDisciplinaryActions->first(),
        ];
    }

    /**
     * Prepare multiple student sheet payloads.
     *
     * @param  iterable<User|int>  $students
     * @return Collection<int, array<string, mixed>>
     */
    public static function getMultipleStudentsData(iterable $students, bool $includeDocuments = false): Collection
    {
        $result = collect();

        foreach ($students as $student) {
            if (is_array($student) && isset($student['id']) && isset($student['name'])) {
                $result->push($student);

                continue;
            }
            if (is_numeric($student)) {
                $student = User::find($student);
            }
            if ($student instanceof User) {
                $result->push(self::getStudentData($student, $includeDocuments));
            }
        }

        return $result;
    }

    /**
     * Generate official PDF containing information sheets for selected students.
     *
     * @param  iterable<User|int>  $students
     */
    public static function generatePdf(iterable $students, bool $includeDocuments = false): \Barryvdh\DomPDF\PDF
    {
        $metadata = self::getMetadata();
        $sheets = self::getMultipleStudentsData($students, $includeDocuments);

        $data = [
            'metadata' => $metadata,
            'sheets' => $sheets,
            'includeDocuments' => $includeDocuments,
        ];

        return Pdf::loadView('pdf.student-information-sheet', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true);
    }

    /**
     * Download official PDF containing information sheets for selected students.
     *
     * @param  iterable<User|int>  $students
     */
    public static function downloadPdf(iterable $students, bool $includeDocuments = false): StreamedResponse
    {
        $pdf = self::generatePdf($students, $includeDocuments);
        $count = is_countable($students) ? count($students) : 1;
        $first = is_iterable($students) ? (is_array($students) ? reset($students) : $students->first()) : null;
        $studentObj = is_numeric($first) ? User::find($first) : ($first instanceof User ? $first : null);

        if ($count === 1 && $studentObj) {
            $identifier = $studentObj->student_id ?: ($studentObj->urn ?: 'student-'.$studentObj->id);
            $filename = 'Student-Info-Sheet-'.Str::slug($identifier).'.pdf';
        } else {
            $filename = 'Students-Detailed-Info-Sheets-'.now()->format('Ymd-His').'.pdf';
        }

        AuditLogService::log(
            event: 'student_information_sheet_downloaded',
            description: $count === 1 && $studentObj
                ? "Downloaded PDF information sheet for {$studentObj->name} ({$studentObj->student_id})"
                : "Downloaded compiled batch PDF information sheets for {$count} students",
            auditable: $count === 1 ? $studentObj : null
        );

        return response()->streamDownload(
            function () use ($pdf) {
                echo $pdf->output();
            },
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]
        );
    }
}
