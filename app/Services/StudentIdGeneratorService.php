<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StudentIdGeneratorService
{
    /**
     * Generate or retrieve a Student ID for a user.
     *
     * Format: [Year (4)][Identifier (1)][Sequence (4)] => 9 digits
     * Example: 2026 + 1 (CC Section A) + 0001 = 202610001
     * Example: 2027 + 2 (CC Section B) + 0001 = 202720001
     * Example: 2026 + 3 (PCC) + 0001 = 202630001
     *
     * @param  User  $user  The student user
     * @param  Course|null  $course  Course being enrolled in
     * @param  Section|null  $section  Section assigned (optional)
     * @param  string|null  $academicYear  Academic year (defaults to current year)
     * @param  bool  $forceNew  Force new ID generation even if user has an existing student_id
     * @return string The 9-digit unique student ID
     */
    public static function generateForStudent(
        User $user,
        ?Course $course = null,
        ?Section $section = null,
        ?string $academicYear = null,
        bool $forceNew = false
    ): string {
        // If not forcing new ID and user already has one, return existing
        if (! $forceNew && ! empty($user->student_id)) {
            return $user->student_id;
        }

        return static::generateNewId($user, $course, $section, $academicYear, true);
    }

    /**
     * Generate a course-and-batch specific 9-digit Student ID for an enrollment.
     *
     * In multi-course or multi-year scenarios (e.g. admitted in PCC in 2026 -> 202630001,
     * then admitted in Composite Course Section A in 2027 -> 202710001), this method
     * generates the appropriate 9-digit cohort student ID for that enrollment and
     * synchronizes the user's active student_id.
     *
     * @param  User  $user  The student user
     * @param  Course|null  $course  Course being enrolled in
     * @param  Section|null  $section  Section assigned (optional)
     * @param  string|null  $academicYear  Academic year (defaults to current year)
     * @param  bool  $syncToUser  Whether to update users.student_id as active ID
     * @return string 9-digit student ID
     */
    public static function generateForEnrollment(
        User $user,
        ?Course $course = null,
        ?Section $section = null,
        ?string $academicYear = null,
        bool $syncToUser = true
    ): string {
        return static::generateNewId($user, $course, $section, $academicYear, $syncToUser);
    }

    /**
     * Internal implementation to generate a fresh 9-digit unique Student ID.
     */
    protected static function generateNewId(
        User $user,
        ?Course $course,
        ?Section $section,
        ?string $academicYear,
        bool $syncToUser = true
    ): string {
        // Determine 4-digit academic year
        $year = static::resolveYear($academicYear);

        // Determine identifier digit(s) (1 for CC Sec A, 2 for CC Sec B, 3 for PCC)
        $identifier = static::resolveIdentifier($course, $section);

        // Prefix is Year + Identifier (e.g. 20271 or 20263)
        $prefix = $year.$identifier;

        // Sequence length (default 4 digits, ensuring 4 + 1 + 4 = 9 digits)
        $seqLength = static::getSequenceLength();

        return DB::transaction(function () use ($user, $prefix, $seqLength, $syncToUser) {
            // Find highest existing sequence in users table
            $latestInUsers = User::where('student_id', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByRaw('LENGTH(student_id) DESC, student_id DESC')
                ->value('student_id');

            // Find highest existing sequence in enrollments table
            $latestInEnrollments = Enrollment::where('student_id', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByRaw('LENGTH(student_id) DESC, student_id DESC')
                ->value('student_id');

            $nextSequence = 1;
            foreach ([$latestInUsers, $latestInEnrollments] as $latestId) {
                if ($latestId && strlen($latestId) > strlen($prefix)) {
                    $seqStr = substr($latestId, strlen($prefix));
                    if (is_numeric($seqStr)) {
                        $nextSequence = max($nextSequence, ((int) $seqStr) + 1);
                    }
                }
            }

            // Ensure unique ID with collision check against both users and enrollments
            do {
                $candidateId = $prefix.str_pad((string) $nextSequence, $seqLength, '0', STR_PAD_LEFT);
                if (strlen($candidateId) > 10) {
                    $candidateId = substr($candidateId, 0, 10);
                }

                $existsInUsers = User::where('student_id', $candidateId)->where('id', '!=', $user->id)->exists();
                $existsInEnrollments = Enrollment::where('student_id', $candidateId)
                    ->where('user_id', '!=', $user->id)
                    ->exists();

                $exists = $existsInUsers || $existsInEnrollments;
                if ($exists) {
                    $nextSequence++;
                }
            } while ($exists);

            if ($syncToUser) {
                $user->student_id = $candidateId;
                $user->saveQuietly();
            }

            return $candidateId;
        });
    }

    /**
     * Resolve the 4-digit academic year.
     */
    protected static function resolveYear(?string $academicYear): string
    {
        if (! empty($academicYear)) {
            // Handle formats like "2026" or "2026-2027"
            if (preg_match('/^(\d{4})/', $academicYear, $matches)) {
                return $matches[1];
            }
        }

        return date('Y');
    }

    /**
     * Resolve the course/section identifier digit.
     *
     * Rules:
     * 1. If section provided and has numeric code or identifier (e.g. '1' for Section A, '2' for Section B), use it.
     * 2. If course has identifier_code set, use it.
     * 3. If course is Composite Course but no section assigned, fallback to configured or '0'.
     * 4. Otherwise use configured fallback (default '0').
     */
    public static function resolveIdentifier(?Course $course, ?Section $section): string
    {
        $config = Setting::get('student_id_config', []);

        // 1. If section is assigned and has a mapping or valid code
        if ($section) {
            if (is_array($config) && ! empty($section->name) && isset($config['section_identifiers'][$section->name])) {
                return (string) $config['section_identifiers'][$section->name];
            }
            if (is_array($config) && ! empty($section->code) && isset($config['section_identifiers'][$section->code])) {
                return (string) $config['section_identifiers'][$section->code];
            }
            if (! empty($section->code)) {
                $cleaned = static::sanitizeIdentifier($section->code);
                if ($cleaned !== '') {
                    return $cleaned;
                }
            }
        }

        // 2. If course has an identifier code configured
        if ($course && ! empty($course->identifier_code)) {
            $cleaned = static::sanitizeIdentifier($course->identifier_code);
            if ($cleaned !== '') {
                return $cleaned;
            }
        }

        // 3. Check dynamic settings mapping in database
        if (is_array($config) && $course && isset($config['course_identifiers'][$course->code])) {
            return (string) $config['course_identifiers'][$course->code];
        }

        // 4. Default fallback when no section is involved (e.g. '0')
        $defaultFallback = $config['default_fallback_identifier'] ?? '0';

        return (string) $defaultFallback;
    }

    /**
     * Preview sample student ID based on parameters and current settings.
     */
    public static function preview(string $year = '2026', string $identifier = '1', int $sequence = 1, ?int $seqLength = null): string
    {
        $seqLen = $seqLength ?? static::getSequenceLength();
        $id = $year.$identifier.str_pad((string) $sequence, $seqLen, '0', STR_PAD_LEFT);

        return strlen($id) > 10 ? substr($id, 0, 10) : $id;
    }

    /**
     * Sanitize identifier string to numeric/digit.
     * Maps 'A' -> '1', 'B' -> '2', 'C' -> '3', etc.
     */
    public static function sanitizeIdentifier(string $code): string
    {
        $code = trim($code);
        if (is_numeric($code)) {
            return substr($code, 0, 2);
        }

        $upper = strtoupper($code);
        if (strlen($upper) === 1 && $upper >= 'A' && $upper <= 'Z') {
            return (string) (ord($upper) - ord('A') + 1);
        }

        // Filter digits only
        $digits = preg_replace('/\D/', '', $code);

        return ! empty($digits) ? substr($digits, 0, 2) : '0';
    }

    /**
     * Get sequence digit length from setting (default 4 digits, max 5).
     */
    public static function getSequenceLength(): int
    {
        $config = Setting::get('student_id_config', []);
        $length = isset($config['sequence_length']) ? (int) $config['sequence_length'] : 4;

        return max(3, min(5, $length));
    }
}
