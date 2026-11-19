<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StudentIdCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StudentIdCardApiController extends Controller
{
    /**
     * Get Student ID Card configuration and availability settings.
     */
    public function settings(): JsonResponse
    {
        $settings = StudentIdCardService::getSettings();

        return response()->json([
            'success' => true,
            'data' => [
                'enable_student_download' => $settings['enable_student_download'],
                'institution_name' => $settings['institution_name'],
                'institution_sub' => $settings['institution_sub'],
                'card_title' => $settings['card_title'],
                'validity_period' => $settings['validity_period'],
                'authorized_signatory_title' => $settings['authorized_signatory_title'],
                'show_blood_group' => $settings['show_blood_group'],
                'show_emergency_contact' => $settings['show_emergency_contact'],
                'show_barcode' => $settings['show_barcode'],
                'show_qr_code' => $settings['show_qr_code'],
                'show_optional_subject' => $settings['show_optional_subject'],
                'theme_color' => $settings['theme_color'],
            ],
        ]);
    }

    /**
     * Get digital Student ID Card payload for rendering in mobile app or web portal.
     */
    public function payload(Request $request, ?string $identifier = null): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        if ($identifier) {
            // Find student by ID, UUID, URN, or Student ID
            $student = User::role('Student')
                ->where(function ($q) use ($identifier) {
                    $q->where('id', $identifier)
                        ->orWhere('uuid', $identifier)
                        ->orWhere('student_id', $identifier)
                        ->orWhere('urn', $identifier)
                        ->orWhere('email', $identifier);
                })
                ->first();

            if (! $student) {
                return response()->json([
                    'success' => false,
                    'message' => "Student with identifier '{$identifier}' was not found.",
                ], 404);
            }

            // If caller is another student, forbid viewing someone else's ID card
            if ($currentUser && $currentUser->hasRole('Student') && $currentUser->id !== $student->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view another student\'s identity card.',
                ], 403);
            }
        } else {
            $student = $currentUser;
            if (! $student) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }
        }

        $payload = StudentIdCardService::getCardPayload($student);

        return response()->json([
            'success' => true,
            'data' => [
                'student' => [
                    'id' => $payload['student']->id,
                    'uuid' => $payload['student']->uuid,
                    'name' => $payload['student']->name,
                    'student_id' => $payload['student']->student_id,
                    'urn' => $payload['student']->urn,
                    'email' => $payload['student']->email,
                    'phone' => $payload['student']->phone,
                    'avatar_url' => $payload['photo_url'],
                    'blood_group' => $payload['blood_group'],
                    'emergency_contact' => $payload['emergency_contact'],
                    'address' => $payload['address'],
                ],
                'academic' => [
                    'course' => $payload['course'],
                    'batch' => $payload['batch'],
                    'section' => $payload['section'],
                    'optional_subject' => $payload['optional_subject'],
                    'validity_period' => $payload['settings']['validity_period'],
                ],
                'verification' => [
                    'code_text' => $payload['student_id'],
                    'barcode_data_uri' => $payload['barcode_uri'],
                    'qr_data_uri' => $payload['qr_uri'],
                ],
                'settings' => [
                    'institution_name' => $payload['settings']['institution_name'],
                    'institution_sub' => $payload['settings']['institution_sub'],
                    'card_title' => $payload['settings']['card_title'],
                    'theme_color' => $payload['settings']['theme_color'],
                    'authorized_signatory_title' => $payload['settings']['authorized_signatory_title'],
                    'terms_and_conditions' => $payload['terms'],
                    'enable_student_download' => $payload['settings']['enable_student_download'],
                ],
                'is_download_allowed' => StudentIdCardService::isDownloadEnabledForStudents() || ($currentUser && ! $currentUser->hasRole('Student')),
            ],
        ]);
    }

    /**
     * Download Student ID Card PDF via API.
     */
    public function download(Request $request, ?string $identifier = null): Response
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        if ($identifier) {
            $student = User::role('Student')
                ->where(function ($q) use ($identifier) {
                    $q->where('id', $identifier)
                        ->orWhere('uuid', $identifier)
                        ->orWhere('student_id', $identifier)
                        ->orWhere('urn', $identifier)
                        ->orWhere('email', $identifier);
                })
                ->firstOrFail();

            if ($currentUser && $currentUser->hasRole('Student') && $currentUser->id !== $student->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to download another student\'s identity card.',
                ], 403);
            }
        } else {
            $student = $currentUser;
            if (! $student) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated user.',
                ], 401);
            }
        }

        $isStudentUser = $currentUser && $currentUser->hasRole('Student');
        if ($isStudentUser && ! StudentIdCardService::isDownloadEnabledForStudents()) {
            return response()->json([
                'success' => false,
                'message' => 'Student ID Card downloads are currently disabled by the administration.',
            ], 403);
        }

        return StudentIdCardService::downloadCardPdf($student);
    }
}
