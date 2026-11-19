<?php

namespace App\Helpers;

use App\Models\Course;

class CourseData
{
    /**
     * Get list of all official Course Categories.
     *
     * @return array<string, string> Key => Human-readable Label
     */
    public static function getCategories(): array
    {
        return [
            'Classroom' => 'Classroom Programme',
            'Crash Course' => 'Crash Course',
            'Guidance' => 'Guidance & Mentorship',
            'Interview' => 'Interview Training',
            'Test Series' => 'Test Series',
        ];
    }

    /**
     * Get array of category keys for validation or filtering.
     *
     * @return array<string>
     */
    public static function getCategoryKeys(): array
    {
        return array_keys(static::getCategories());
    }

    /**
     * Get human-readable label for a course category key.
     */
    public static function getCategoryLabel(?string $key): string
    {
        if (empty($key)) {
            return '';
        }

        return static::getCategories()[$key] ?? $key;
    }

    /**
     * Get supported course fee types / billing schedules.
     *
     * @return array<string, string>
     */
    public static function getFeeTypes(): array
    {
        return [
            'monthly' => 'Monthly Fee',
            'one_time' => 'One-time Fee',
            'free' => 'Free of Cost / No Fee',
        ];
    }

    /**
     * Get supported standard duration units.
     *
     * @return array<string, string>
     */
    public static function getDurationUnits(): array
    {
        return [
            'days' => 'Days',
            'weeks' => 'Weeks',
            'months' => 'Months',
            'years' => 'Years',
            'custom' => 'Custom Duration',
        ];
    }

    /**
     * Format duration into standard string representation.
     */
    public static function formatDuration(int $value, string $unit): string
    {
        $unitLower = strtolower(trim($unit));

        return match ($unitLower) {
            'days', 'day' => $value.' '.($value === 1 ? 'Day' : 'Days'),
            'weeks', 'week' => $value.' '.($value === 1 ? 'Week' : 'Weeks'),
            'months', 'month' => $value.' '.($value === 1 ? 'Month' : 'Months'),
            'years', 'year' => $value.' '.($value === 1 ? 'Year' : 'Years'),
            default => (string) $value,
        };
    }

    /**
     * Check if a social/student category qualifies as a reserved category
     * (SC, ST, OBC, OBC-A, OBC-B, EWS, PWD).
     */
    public static function isReservedCategory(?string $category): bool
    {
        if (empty($category)) {
            return false;
        }

        $normalized = strtoupper(trim($category));

        if (in_array($normalized, ['UR', 'GENERAL', 'GEN', 'OPEN'], true)) {
            return false;
        }

        return in_array($normalized, [
            'SC',
            'ST',
            'OBC',
            'OBC-A',
            'OBC-B',
            'EWS',
            'PWD',
            'PH',
            'RESERVED',
        ], true);
    }

    /**
     * Centralized default fee matrix and programme specifications.
     * Easily updated or extended for future course additions.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getDefaultFeeStructures(): array
    {
        return [
            'CC' => [
                'name' => 'Composite Course (CC)',
                'duration' => '10 Months',
                'fee_type' => 'monthly',
                'fee_general' => 1000.00,
                'fee_reserved' => 500.00,
                'capacity' => 200,
                'category_fees' => [
                    'UR' => 1000.00,
                    'GENERAL' => 1000.00,
                    'SC' => 500.00,
                    'ST' => 500.00,
                    'OBC' => 500.00,
                    'OBC-A' => 500.00,
                    'OBC-B' => 500.00,
                    'EWS' => 500.00,
                    'PWD' => 500.00,
                ],
            ],
            'PCC' => [
                'name' => 'Prelims Crash Course (PCC)',
                'duration' => '3 Months',
                'fee_type' => 'one_time',
                'fee_general' => 1500.00,
                'fee_reserved' => 750.00,
                'capacity' => null, // null indicates unlimited capacity
                'category_fees' => [
                    'UR' => 1500.00,
                    'GENERAL' => 1500.00,
                    'SC' => 750.00,
                    'ST' => 750.00,
                    'OBC' => 750.00,
                    'OBC-A' => 750.00,
                    'OBC-B' => 750.00,
                    'EWS' => 750.00,
                    'PWD' => 750.00,
                ],
            ],
            'MGP' => [
                'name' => 'Mains Guidance Programme (MGP)',
                'duration' => '16 Weeks',
                'fee_type' => 'one_time',
                'fee_general' => 12000.00,
                'fee_reserved' => 6000.00,
                'capacity' => null,
            ],
            'ITP' => [
                'name' => 'Interview Training and Mock Interview Programme (ITP)',
                'duration' => '4 Weeks',
                'fee_type' => 'one_time',
                'fee_general' => 10000.00,
                'fee_reserved' => 5000.00,
                'capacity' => null,
            ],
            'WTS' => [
                'name' => 'Weekend Test Series (WTS)',
                'duration' => '24 Weeks',
                'fee_type' => 'one_time',
                'fee_general' => 3000.00,
                'fee_reserved' => 1500.00,
                'capacity' => null,
            ],
        ];
    }

    /**
     * Calculate effective course fee for a given candidate social category.
     *
     * @param  Course|string  $course  Course model or course short code
     * @param  string|null  $category  Student category (e.g. 'UR', 'SC', 'ST', 'OBC', 'EWS')
     */
    public static function calculateFee(Course|string $course, ?string $category = 'UR'): float
    {
        $categoryKey = strtoupper(trim($category ?? 'UR'));
        $isReserved = static::isReservedCategory($categoryKey);

        if ($course instanceof Course) {
            // Immediate check for free course
            if ($course->isFree() || $course->fee_type === 'free') {
                return 0.00;
            }

            // 1. Check custom JSON fee_structure on model if defined
            if (is_array($course->fee_structure) && ! empty($course->fee_structure)) {
                if (isset($course->fee_structure[$categoryKey])) {
                    return (float) $course->fee_structure[$categoryKey];
                }
                if ($isReserved && isset($course->fee_structure['reserved'])) {
                    return (float) $course->fee_structure['reserved'];
                }
                if (! $isReserved && isset($course->fee_structure['general'])) {
                    return (float) $course->fee_structure['general'];
                }
            }

            // 2. Check model's dedicated fee_reserved column
            if ($isReserved && $course->fee_reserved !== null && (float) $course->fee_reserved > 0) {
                return (float) $course->fee_reserved;
            }

            // 3. If reserved category and fee_reserved is 0.00 (exempted)
            if ($isReserved && $course->fee_reserved !== null && (float) $course->fee_reserved === 0.0) {
                return 0.00;
            }

            // 4. Return standard course fee if set
            if (! $isReserved && (float) $course->fee > 0) {
                return (float) $course->fee;
            }

            $courseCode = strtoupper($course->code ?? '');
        } else {
            $courseCode = strtoupper(trim($course));
        }

        // 5. Fallback to centralized matrix
        $defaults = static::getDefaultFeeStructures()[$courseCode] ?? null;
        if ($defaults) {
            if (($defaults['fee_type'] ?? '') === 'free') {
                return 0.00;
            }
            if (isset($defaults['category_fees'][$categoryKey])) {
                return (float) $defaults['category_fees'][$categoryKey];
            }
            if ($isReserved && isset($defaults['fee_reserved'])) {
                return (float) $defaults['fee_reserved'];
            }
            if (! $isReserved && isset($defaults['fee_general'])) {
                return (float) $defaults['fee_general'];
            }
        }

        return $course instanceof Course ? (float) $course->fee : 0.00;
    }

    /**
     * Get a human-readable fee summary for a course and candidate category.
     */
    public static function getFeeDescription(Course $course, ?string $category = 'UR'): string
    {
        if ($course->isFree() || $course->fee_type === 'free') {
            return '₹0.00 ('.__('Free of Cost / No Fee').')';
        }

        $fee = static::calculateFee($course, $category);
        $type = $course->fee_type === 'monthly' ? __('monthly') : __('one-time');
        $catLabel = static::isReservedCategory($category) ? __('Reserved (:cat)', ['cat' => $category]) : __('UR / General');

        return '₹'.number_format($fee, 2).' ('.$type.' · '.$catLabel.')';
    }
}
