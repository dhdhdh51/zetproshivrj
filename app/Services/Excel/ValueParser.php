<?php

declare(strict_types=1);

namespace App\Services\Excel;

/**
 * Cleans the messy values that come out of bank spreadsheets.
 *
 * Amounts arrive as "1,23,456.78", "(2500)" for credits, "12,000 Cr", "-" or
 * blank. Dates arrive in at least six layouts plus Excel serial numbers. Getting
 * this wrong silently corrupts recovery figures, so each parser returns null on
 * anything it does not understand and the importer reports that row instead of
 * guessing.
 */
final class ValueParser
{
    /**
     * @return array{0: float|null, 1: string} value + error message ('' when fine)
     */
    public static function amount(mixed $raw): array
    {
        if ($raw === null) {
            return [null, ''];
        }

        $value = trim((string) $raw);

        if ($value === '' || in_array($value, ['-', '--', 'NA', 'N/A', 'NIL', 'nil', 'null'], true)) {
            return [null, ''];
        }

        $negative = false;

        // Accounting notation: (1,234.00) means negative.
        if (preg_match('/^\((.*)\)$/', $value, $m) === 1) {
            $negative = true;
            $value = $m[1];
        }

        // Trailing Dr/Cr markers.
        if (preg_match('/\b(cr|credit)\b/i', $value) === 1) {
            $negative = true;
        }

        $cleaned = preg_replace('/(?i)\b(dr|cr|debit|credit|inr|rs\.?)\b/', '', $value) ?? $value;
        $cleaned = str_replace([',', ' ', "\u{00A0}", '₹'], '', $cleaned);

        if ($cleaned === '' || $cleaned === '.') {
            return [null, ''];
        }

        if (preg_match('/^-?\d*\.?\d+$/', $cleaned) !== 1) {
            return [null, sprintf('"%s" is not a valid amount.', mb_substr((string) $raw, 0, 40))];
        }

        $amount = (float) $cleaned;

        if ($negative && $amount > 0) {
            $amount = -$amount;
        }

        return [round($amount, 2), ''];
    }

    /**
     * @return array{0: string|null, 1: string} Y-m-d + error message
     */
    public static function date(mixed $raw): array
    {
        if ($raw === null) {
            return [null, ''];
        }

        $value = trim((string) $raw);

        if ($value === '' || in_array(strtoupper($value), ['-', '--', 'NA', 'N/A', 'NIL', 'NULL', '0'], true)) {
            return [null, ''];
        }

        // Already normalised by the xlsx reader (Y-m-d or Y-m-d H:i:s).
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1) {
            return self::build((int) $m[1], (int) $m[2], (int) $m[3], $raw);
        }

        // Bare Excel serial number that carried no date style.
        if (preg_match('/^\d{5}(\.\d+)?$/', $value) === 1) {
            $converted = XlsxReader::excelSerialToDate((float) $value);

            if ($converted !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $converted, $m) === 1) {
                return self::build((int) $m[1], (int) $m[2], (int) $m[3], $raw);
            }
        }

        // d/m/Y, d-m-Y, d.m.Y with 2 or 4 digit years. Indian sheets are always
        // day-first, so that ordering is assumed deliberately.
        if (preg_match('#^(\d{1,2})[/\-. ](\d{1,2})[/\-. ](\d{2,4})#', $value, $m) === 1) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];

            if ($year < 100) {
                $year += $year >= 70 ? 1900 : 2000;
            }

            // Tolerate an unambiguous US-style value such as 12/25/2024.
            if ($month > 12 && $day <= 12) {
                [$day, $month] = [$month, $day];
            }

            return self::build($year, $month, $day, $raw);
        }

        // 25-Mar-2024 / 25 March 2024 / Mar-2024
        $timestamp = strtotime($value);

        if ($timestamp !== false) {
            $year = (int) date('Y', $timestamp);

            if ($year >= 1900 && $year <= 2100) {
                return [date('Y-m-d', $timestamp), ''];
            }
        }

        return [null, sprintf('"%s" is not a valid date.', mb_substr((string) $raw, 0, 40))];
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private static function build(int $year, int $month, int $day, mixed $raw): array
    {
        if (!checkdate($month, $day, $year) || $year < 1900 || $year > 2100) {
            return [null, sprintf('"%s" is not a valid date.', mb_substr((string) $raw, 0, 40))];
        }

        return [sprintf('%04d-%02d-%02d', $year, $month, $day), ''];
    }

    /**
     * Indian mobile numbers: 10 digits starting 6–9, tolerating +91 / 0 prefixes
     * and separators. Anything else is kept as a warning rather than dropped,
     * because a wrong-looking number is still the only contact the field officer
     * has.
     *
     * @return array{0: string|null, 1: string}
     */
    public static function mobile(mixed $raw): array
    {
        if ($raw === null) {
            return [null, ''];
        }

        $value = trim((string) $raw);

        if ($value === '' || in_array(strtoupper($value), ['-', 'NA', 'N/A', 'NIL', '0'], true)) {
            return [null, ''];
        }

        // Excel often turns long numbers into 9.19999E+11.
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $value) === 1) {
            $value = number_format((float) $value, 0, '.', '');
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        if ($digits === '') {
            return [null, sprintf('"%s" is not a usable mobile number.', mb_substr($value, 0, 30))];
        }

        // Drop country / trunk prefixes.
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10 && preg_match('/^[6-9]/', $digits) === 1) {
            return [$digits, ''];
        }

        return [
            mb_substr($digits, 0, 20),
            sprintf('Mobile "%s" does not look like a 10 digit number.', mb_substr($value, 0, 30)),
        ];
    }

    public static function text(mixed $raw, int $maxLength = 255): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', (string) $raw) ?? '');

        if ($value === '' || in_array(strtoupper($value), ['-', 'NA', 'N/A', 'NULL'], true)) {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    /**
     * Account numbers must survive Excel's habit of turning long digit strings
     * into floats ("31234567890.0") or scientific notation.
     */
    public static function accountNumber(mixed $raw): ?string
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $value) === 1) {
            $value = number_format((float) $value, 0, '.', '');
        }

        if (preg_match('/^(\d+)\.0+$/', $value, $m) === 1) {
            $value = $m[1];
        }

        $value = preg_replace('/\s+/', '', $value) ?? $value;

        return mb_substr($value, 0, 60);
    }
}
