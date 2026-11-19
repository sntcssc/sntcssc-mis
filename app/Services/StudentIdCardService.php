<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Services\Library\BarcodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class StudentIdCardService
{
    /**
     * Default card terms and conditions.
     */
    public const DEFAULT_TERMS = [
        'This identity card is the property of Satyendranath Tagore Civil Services Study Centre (SNTCSSC) and must be returned upon completion or withdrawal from the course.',
        'This card is non-transferable and must be carried at all times while attending classes, study sessions, library, or writing examinations on campus.',
        'Loss of this card must be immediately reported to the administrative office. A duplicate card may be issued upon payment of the prescribed replacement fee.',
        'Unauthorized duplication, tampering, or fraudulent use of this identity card is strictly prohibited and subject to disciplinary action.',
    ];

    /**
     * Get Student ID Card configuration settings with fallbacks.
     *
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        return [
            'enable_student_download' => (bool) Setting::get('student_id_card.enable_student_download', true),
            'card_title' => (string) (Setting::get('student_id_card.card_title') ?: Setting::get('student_id_card.title', 'STUDENT IDENTITY CARD')),
            'institution_name' => (string) Setting::get('student_id_card.institution_name', Setting::appName() ?: 'Satyendranath Tagore Civil Services Study Centre'),
            'institution_sub' => (string) Setting::get('student_id_card.institution_sub', 'Government of West Bengal'),
            'validity_period' => (string) Setting::get('student_id_card.validity_period', 'Academic Session '.now()->format('Y').'-'.(now()->year + 1)),
            'authorized_signatory_title' => (string) Setting::get('student_id_card.authorized_signatory_title', 'Course Director / Authorized Signatory'),
            'show_blood_group' => (bool) Setting::get('student_id_card.show_blood_group', true),
            'show_emergency_contact' => (bool) Setting::get('student_id_card.show_emergency_contact', true),
            'show_barcode' => (bool) Setting::get('student_id_card.show_barcode', true),
            'show_qr_code' => (bool) Setting::get('student_id_card.show_qr_code', true),
            'show_optional_subject' => (bool) Setting::get('student_id_card.show_optional_subject', true),
            'terms_and_conditions' => (string) Setting::get('student_id_card.terms_and_conditions', implode("\n", self::DEFAULT_TERMS)),
            'theme_color' => (string) Setting::get('student_id_card.theme_color', '#1e3a8a'),
        ];
    }

    /**
     * Check if student ID card downloads are enabled for student users.
     */
    public static function isDownloadEnabledForStudents(): bool
    {
        return (bool) Setting::get('student_id_card.enable_student_download', true);
    }

    /**
     * Prepare full data payload for rendering and printing Student ID Card.
     *
     * @return array<string, mixed>
     */
    public static function getCardPayload(User $student): array
    {
        $student->loadMissing([
            'studentProfile',
            'studentAddresses',
            'studentOtherDetails',
            'activeEnrollments.batch',
            'activeEnrollments.course',
            'activeEnrollments.section',
        ]);

        $settings = self::getSettings();
        $primaryEnrollment = $student->activeEnrollments->first() ?? $student->enrollments()->latest('id')->first();

        $codeText = $student->student_id ?: ($student->urn ?: sprintf('STU-%05d', $student->id));
        $barcodeDataUri = BarcodeService::generateBarcodeDataUri($codeText, 38, 2, false);

        // QR Code payload
        $qrPayload = json_encode([
            'type' => 'STUDENT_ID_CARD',
            'id' => $student->id,
            'student_id' => $student->student_id,
            'name' => $student->name,
            'course' => $primaryEnrollment?->course?->name,
            'batch' => $primaryEnrollment?->batch?->name,
            'validity' => $settings['validity_period'],
            'issued_by' => $settings['institution_name'],
        ], JSON_UNESCAPED_SLASHES);

        $qrDataUri = BarcodeService::generateQrCodeDataUri($qrPayload ?: $codeText, 4);

        // Photo resolution
        $photoUrl = $student->avatarUrl();
        $bloodGroup = $student->studentProfile?->category_remark ?? null; // or dynamic fields if configured
        if (! $bloodGroup && ! empty($student->studentOtherDetails?->dynamic_fields['blood_group'])) {
            $bloodGroup = $student->studentOtherDetails->dynamic_fields['blood_group'];
        }

        $emergencyPhone = $student->whatsapp_no ?: ($student->studentAddresses->first()?->contact_person_phone ?? null);

        // Explode terms
        $terms = array_filter(array_map('trim', explode("\n", $settings['terms_and_conditions'])));

        return [
            'settings' => $settings,
            'student' => $student,
            'student_id' => $student->student_id ?: ($student->urn ?: 'N/A'),
            'urn' => $student->urn,
            'name' => $student->name,
            'email' => $student->email,
            'phone' => $student->phone,
            'dob' => $student->dob ? $student->dob->format('d M Y') : ($student->studentProfile?->dob ? Carbon::parse($student->studentProfile->dob)->format('d M Y') : '—'),
            'gender' => ucfirst((string) $student->gender),
            'course' => $primaryEnrollment?->course?->name ?? 'UPSC Civil Services Coaching',
            'batch' => $primaryEnrollment?->batch?->name ?? 'Civil Services Batch',
            'section' => $primaryEnrollment?->section?->name ?? 'Main Section',
            'roll_no' => $primaryEnrollment?->roll_no ?: '—',
            'optional_subject' => $student->studentOtherDetails?->optional_subject ?: 'Not Selected',
            'blood_group' => $bloodGroup ?: 'O+ (Self)',
            'emergency_contact' => $emergencyPhone ?: $student->phone,
            'address' => $student->studentAddresses->first()?->full_address ?? 'Kolkata, West Bengal',
            'photo_url' => $photoUrl,
            'barcode_uri' => $barcodeDataUri,
            'qr_uri' => $qrDataUri,
            'terms' => ! empty($terms) ? $terms : self::DEFAULT_TERMS,
        ];
    }

    /**
     * Download printable official PDF Student ID Card.
     */
    public static function downloadCardPdf(User $student): Response
    {
        $payload = self::getCardPayload($student);

        $pdf = Pdf::loadView('pdf.student-id-card', [
            'cards' => [$payload],
            'single' => true,
        ])->setPaper('a4', 'portrait');

        $filename = 'Student-ID-Card-'.($student->student_id ?: $student->id).'.pdf';

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

    /**
     * Download printable PDF with multiple student ID cards.
     *
     * @param  Collection<int, User>  $students
     */
    public static function downloadBulkCardsPdf(Collection $students): Response
    {
        $cards = $students->map(fn (User $student) => self::getCardPayload($student))->all();

        $pdf = Pdf::loadView('pdf.student-id-card', [
            'cards' => $cards,
            'single' => false,
        ])->setPaper('a4', 'portrait');

        $filename = 'Bulk-Student-ID-Cards-'.now()->format('Ymd-His').'.pdf';

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
