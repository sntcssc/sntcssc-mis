<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class Format
{
    /**
     * Format date using application-configured date standard.
     */
    public static function date(mixed $date, ?string $format = null): string
    {
        if (blank($date)) {
            return '—';
        }

        $carbon = $date instanceof CarbonInterface ? $date : Carbon::parse($date);
        $format ??= (string) Setting::get('localization.date_format', 'd M Y');

        return $carbon->format($format);
    }

    /**
     * Format time using application-configured time standard.
     */
    public static function time(mixed $time, ?string $format = null): string
    {
        if (blank($time)) {
            return '—';
        }

        $carbon = $time instanceof CarbonInterface ? $time : Carbon::parse($time);
        $format ??= (string) Setting::get('localization.time_format', 'h:i A');

        return $carbon->format($format);
    }

    /**
     * Format combined date and time.
     */
    public static function dateTime(mixed $dateTime, ?string $dateFormat = null, ?string $timeFormat = null): string
    {
        if (blank($dateTime)) {
            return '—';
        }

        $carbon = $dateTime instanceof CarbonInterface ? $dateTime : Carbon::parse($dateTime);
        $dateFormat ??= (string) Setting::get('localization.date_format', 'd M Y');
        $timeFormat ??= (string) Setting::get('localization.time_format', 'h:i A');

        return $carbon->format("{$dateFormat}, {$timeFormat}");
    }

    /**
     * Format number according to configured number notation (Indian vs International).
     */
    public static function number(float|int|string|null $number, int $decimals = 0): string
    {
        if (blank($number) || ! is_numeric($number)) {
            return '0';
        }

        $num = (float) $number;
        $notation = (string) Setting::get('localization.number_format', 'indian');

        if ($notation === 'indian') {
            return static::formatIndianNumber($num, $decimals);
        }

        return number_format($num, $decimals);
    }

    /**
     * Format currency amount with configured symbol and notation.
     */
    public static function currency(float|int|string|null $amount, int $decimals = 0): string
    {
        $symbol = (string) Setting::get('localization.currency_symbol', '₹');
        $formatted = static::number($amount, $decimals);

        return "{$symbol}{$formatted}";
    }

    /**
     * Format number according to Indian Numbering System (e.g. 12,34,567).
     */
    protected static function formatIndianNumber(float $number, int $decimals = 0): string
    {
        $negative = $number < 0;
        $abs = abs($number);

        $parts = explode('.', number_format($abs, $decimals, '.', ''));
        $integerPart = $parts[0];
        $decimalPart = $parts[1] ?? '';

        if (strlen($integerPart) > 3) {
            $lastThree = substr($integerPart, -3);
            $remaining = substr($integerPart, 0, -3);
            $formattedRemaining = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining);
            $integerPart = $formattedRemaining.','.$lastThree;
        }

        $result = $integerPart.($decimalPart !== '' && $decimals > 0 ? '.'.$decimalPart : '');

        return $negative ? '-'.$result : $result;
    }
}
