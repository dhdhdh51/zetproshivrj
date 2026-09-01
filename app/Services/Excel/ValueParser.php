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
    /**
     * Gender as printed in section 2 of the field visit verification report.
     * Sheets write this as Male/Female, M/F, or occasionally 1/2.
     *
     * @return array{0: ?string, 1: string} value + warning
     */
    public static function gender(mixed $raw): array
    {
        $value = self::text($raw, 40);

        if ($value === null) {
            return [null, ''];
        }

        $normalised = strtolower($value);

        return match ($normalised) {
            'm', 'male', '1' => ['male', ''],
            'f', 'female', '2' => ['female', ''],
            'o', 'other', 'others', 'transgender', '3' => ['other', ''],
            default => [null, sprintf('"%s" is not a recognised gender; left blank.', str_excerpt($value, 30))],
        };
    }

    /**
     * Asset classification (section 3). Accepts the many spellings of the SMA
     * buckets: "SMA-1", "SMA 1", "sma1", and NPA sub-grades such as "D1", which
     * are all NPA for this purpose.
     *
     * @return array{0: ?string, 1: string} value + warning
     */
    public static function assetClassification(mixed $raw): array
    {
        $value = self::text($raw, 60);

        if ($value === null) {
            return [null, ''];
        }

        // Strip separators so "SMA - 2", "SMA_2" and "sma2" all compare equal.
        $compact = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));

        if (in_array($compact, ['standard', 'std', 'stdasset', 'performing'], true)) {
            return ['standard', ''];
        }

        if (preg_match('/^sma0?$/', $compact) === 1 || $compact === 'sma00') {
            return ['sma_0', ''];
        }

        if (preg_match('/^sma1$/', $compact) === 1) {
            return ['sma_1', ''];
        }

        if (preg_match('/^sma2$/', $compact) === 1) {
            return ['sma_2', ''];
        }

        // NPA and its sub-grades: substandard, doubtful (D1/D2/D3), loss.
        if (
            in_array($compact, ['npa', 'nonperforming', 'substandard', 'ss', 'loss', 'doubtful'], true)
            || preg_match('/^d[123]$/', $compact) === 1
        ) {
            return ['npa', ''];
        }

        return [null, sprintf('"%s" is not a recognised asset classification; left blank.', str_excerpt($value, 30))];
    }

    /**
     * PAN. Stored only when it looks like a real PAN (AAAAA9999A), because a
     * malformed one on a compliance report is worse than a blank.
     *
     * @return array{0: ?string, 1: string} value + warning
     */
    public static function pan(mixed $raw): array
    {
        $value = self::text($raw, 20);

        if ($value === null) {
            return [null, ''];
        }

        $candidate = strtoupper((string) preg_replace('/\s+/', '', $value));

        if (preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $candidate) === 1) {
            return [$candidate, ''];
        }

        return [null, sprintf('PAN "%s" is not in the AAAAA9999A format; left blank.', str_excerpt($value, 20))];
    }

    /**
     * The last four digits of an Aadhaar number.
     *
     * A full 12 digit number is reduced to its last four on the way in: LRMS
     * only ever prints XXXX-XXXX-nnnn, so storing the rest would be holding
     * identity data the system has no use for.
     *
     * @return array{0: ?string, 1: string} value + warning
     */
    public static function aadhaarLast4(mixed $raw): array
    {
        $value = self::text($raw, 40);

        if ($value === null) {
            return [null, ''];
        }

        $digits = (string) preg_replace('/\D/', '', $value);

        if ($digits === '') {
            return [null, sprintf('Aadhaar "%s" contains no digits; left blank.', str_excerpt($value, 20))];
        }

        if (strlen($digits) < 4) {
            return [null, 'Aadhaar value has fewer than four digits; left blank.'];
        }

        return [substr($digits, -4), ''];
    }

    /**
     * A whole number, used for counts such as "Days Remaining".
     *
     * @return array{0: ?int, 1: string} value + warning
     */
    public static function integer(mixed $raw): array
    {
        $value = self::text($raw, 40);

        if ($value === null) {
            return [null, ''];
        }

        $candidate = str_replace([',', ' '], '', $value);

        if (preg_match('/^-?\d+$/', $candidate) !== 1) {
            return [null, sprintf('"%s" is not a whole number; left blank.', str_excerpt($value, 20))];
        }

        return [(int) $candidate, ''];
    }

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
