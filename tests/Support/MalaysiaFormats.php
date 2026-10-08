<?php

namespace Shahirul22\LaravelPiiSanitizer\Tests\Support;

/**
 * Independent oracles for the Malaysia generators. Deliberately holds its own
 * copies of the tables and never imports package constants, so a structural
 * assertion is not circular.
 */
final class MalaysiaFormats
{
    public const STATES = [
        'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis',
        'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu', 'W.P. Kuala Lumpur',
        'W.P. Labuan', 'W.P. Putrajaya',
    ];

    public const BANK_LENGTHS = [
        'Maybank' => 12, 'CIMB' => 14, 'Public Bank' => 10, 'RHB' => 14,
        'Hong Leong' => 11, 'AmBank' => 13, 'Bank Islam' => 14, 'BSN' => 16,
    ];

    public static function nricCentury(string $value): int
    {
        return (int) substr(str_replace('-', '', $value), 0, 2) >= 40 ? 1900 : 2000;
    }

    public static function nricIsValid(string $value, bool $hyphen = false, ?string $gender = null): bool
    {
        $pattern = $hyphen ? '/^\d{6}-\d{2}-\d{4}$/' : '/^\d{12}$/';

        if (preg_match($pattern, $value) !== 1) {
            return false;
        }

        $digits = str_replace('-', '', $value);
        $year = self::nricCentury($digits) + (int) substr($digits, 0, 2);
        $month = (int) substr($digits, 2, 2);
        $day = (int) substr($digits, 4, 2);

        if (! checkdate($month, $day, $year)) {
            return false;
        }

        $ymd = sprintf('%04d%02d%02d', $year, $month, $day);

        if ($ymd < '19400101' || $ymd > '20101231') {
            return false;
        }

        if (preg_match('/^(0[1-9]|1[0-6]|2[1-9]|[34]\d|5\d)$/', substr($digits, 6, 2)) !== 1) {
            return false;
        }

        $last = (int) substr($digits, -1);

        return match ($gender) {
            'male' => $last % 2 === 1,
            'female' => $last % 2 === 0,
            default => true,
        };
    }

    public static function sstIsValid(string $value): bool
    {
        if (preg_match('/^(W10|B16)-(\d{2})(\d{2})-\d{8}$/', $value, $m) !== 1) {
            return false;
        }

        $yymm = (int) ($m[2].$m[3]);
        $month = (int) $m[3];

        return $yymm >= 1809 && $yymm <= 2612 && $month >= 1 && $month <= 12;
    }

    public static function bankAccountIsValid(string $value, ?string $bank = null): bool
    {
        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return false;
        }

        if ($bank !== null) {
            return strlen($value) === self::BANK_LENGTHS[$bank];
        }

        return in_array(strlen($value), self::BANK_LENGTHS, true);
    }
}
