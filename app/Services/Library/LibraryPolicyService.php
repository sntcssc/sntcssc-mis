<?php

namespace App\Services\Library;

use App\Models\BookIssue;
use App\Models\Setting;
use Illuminate\Support\Carbon;

class LibraryPolicyService
{
    /**
     * Check if overdue fine system is enabled.
     */
    public static function isFinesEnabled(): bool
    {
        return (bool) Setting::get('library.fines_enabled', true);
    }

    /**
     * Get fine amount per day overdue.
     */
    public static function getFinePerDay(): float
    {
        return (float) Setting::get('library.fine_per_day', 5.00);
    }

    /**
     * Default book borrowing timeline / duration in days (default 15 days).
     */
    public static function getDefaultLoanDays(): int
    {
        return (int) Setting::get('library.default_loan_days', 15);
    }

    /**
     * Maximum books allowed per member at any time.
     */
    public static function getMaxBooksAllowed(): int
    {
        return (int) Setting::get('library.max_books_allowed', 3);
    }

    /**
     * Maximum allowed renewals per issued book.
     */
    public static function getMaxRenewals(): int
    {
        return (int) Setting::get('library.max_renewals', 2);
    }

    /**
     * Check if librarian/admin approval is required for student loan renewals.
     */
    public static function isRenewalApprovalRequired(): bool
    {
        return (bool) Setting::get('library.require_renewal_approval', true);
    }

    /**
     * Check if security deposit is strictly required before issuing books.
     */
    public static function isSecurityDepositRequired(): bool
    {
        return (bool) Setting::get('library.require_security_deposit', true);
    }

    /**
     * Check if staff/librarian deposit bypass is globally permitted.
     */
    public static function isDepositBypassAllowed(): bool
    {
        return (bool) Setting::get('library.allow_deposit_bypass', true);
    }

    /**
     * Reservation hold validity in days.
     */
    public static function getReservationExpiryDays(): int
    {
        return (int) Setting::get('library.reservation_expiry_days', 3);
    }

    /**
     * Days before due date to dispatch return reminders.
     */
    public static function getReminderDaysBefore(): int
    {
        return (int) Setting::get('library.reminder_days_before', 2);
    }

    /**
     * Get configured reminder communication channels.
     *
     * @return array<string>
     */
    public static function getReminderChannels(): array
    {
        $channels = Setting::get('library.reminder_channels', ['database', 'email', 'sms']);
        if (is_string($channels)) {
            $decoded = json_decode($channels, true);

            return is_array($decoded) ? $decoded : ['database', 'email', 'sms'];
        }

        return is_array($channels) ? $channels : ['database', 'email', 'sms'];
    }

    /**
     * Calculate due date for a new issue given loan days.
     */
    public static function calculateDueDate(?int $loanDays = null, mixed $startDate = null): Carbon
    {
        $days = $loanDays ?? self::getDefaultLoanDays();
        $base = $startDate ? Carbon::parse($startDate) : Carbon::now();

        return $base->addDays($days);
    }

    /**
     * Calculate overdue days and fine for a given book issue.
     *
     * @return array{overdue_days: int, is_overdue: bool, fine_rate: float, fine_amount: float}
     */
    public static function assessIssueFine(BookIssue $issue): array
    {
        $overdueDays = $issue->getOverdueDays();
        $finesEnabled = self::isFinesEnabled();
        $rate = self::getFinePerDay();
        $fineAmount = ($finesEnabled && $overdueDays > 0) ? round($overdueDays * $rate, 2) : 0.00;

        return [
            'overdue_days' => $overdueDays,
            'is_overdue' => $overdueDays > 0,
            'fine_rate' => $rate,
            'fine_amount' => $fineAmount,
        ];
    }

    /**
     * Check if in-house library reading room facility is enabled.
     */
    public static function isInhouseReadingEnabled(): bool
    {
        return (bool) Setting::get('library.inhouse_reading_enabled', true);
    }

    /**
     * Get in-house library reading room official opening time (e.g. "11:00").
     */
    public static function getInhouseReadingStartTime(): string
    {
        return (string) Setting::get('library.inhouse_reading_start_time', '11:00');
    }

    /**
     * Get in-house library reading room official closing time (e.g. "17:00").
     */
    public static function getInhouseReadingEndTime(): string
    {
        return (string) Setting::get('library.inhouse_reading_end_time', '17:00');
    }

    /**
     * Maximum books or journals allowed per in-house reading session.
     */
    public static function getInhouseReadingMaxItems(): int
    {
        return (int) Setting::get('library.inhouse_reading_max_items', 5);
    }

    /**
     * Check if current time is within official reading room hours.
     */
    public static function isWithinInhouseReadingHours(): bool
    {
        $now = Carbon::now();
        $startTime = Carbon::createFromTimeString(self::getInhouseReadingStartTime());
        $endTime = Carbon::createFromTimeString(self::getInhouseReadingEndTime());

        return $now->between($startTime, $endTime);
    }

    /**
     * Get human-readable formatted reading room hours.
     */
    public static function getInhouseReadingHoursFormatted(): string
    {
        try {
            $start = Carbon::createFromTimeString(self::getInhouseReadingStartTime())->format('h:i A');
            $end = Carbon::createFromTimeString(self::getInhouseReadingEndTime())->format('h:i A');

            return "{$start} - {$end}";
        } catch (\Throwable $e) {
            return '11:00 AM - 05:00 PM';
        }
    }
}
