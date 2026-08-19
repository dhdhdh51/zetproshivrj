<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Core\Database;
use App\Services\Export\CsvWriter;
use App\Services\Export\XlsxWriter;

/**
 * Builds the downloadable demo sheet for the Excel import screen.
 *
 * Generated rather than kept as a static file in the repository, for two reasons.
 * The header row comes from SystemFields, so it cannot fall behind the columns the
 * importer actually reads. And the branch and BC columns are filled from this
 * installation's own records, so the file imports cleanly instead of stopping on
 * "Branch X is not set up in LRMS" — a sample that cannot be imported teaches the
 * wrong thing about the format.
 *
 * A service rather than controller code so the test suite can run the real demo
 * file through the real importer. A sample nobody has imported is a guess.
 */
final class SampleSheet
{
    public const ACCOUNT_PREFIX = 'SAMPLE-';

    /** Column headings, in the importer's canonical order. */
    public static function headers(): array
    {
        return array_map(
            static fn (array $field): string => $field['label'],
            SystemFields::all()
        );
    }

    /**
     * The demo rows, with branch and BC columns taken from live records.
     *
     * @return array<int, array<int, string>>
     */
    public static function rows(): array
    {
        // Interpolated as an int rather than bound: MySQL rejects a bound LIMIT
        // once prepared statements are not emulated.
        $limit = (int) SystemFields::SAMPLE_ROWS;

        $branches = Database::select(
            "SELECT code, name FROM branches WHERE status = 'active' ORDER BY id LIMIT " . $limit
        );

        $bcCodes = Database::select(
            "SELECT s.bc_code
               FROM bc_supervisors s
               JOIN users u ON u.id = s.user_id
              WHERE s.status = 'active' AND u.status = 'active'
              ORDER BY s.id
              LIMIT " . $limit
        );

        $keys = SystemFields::keys();
        $rows = [];

        for ($index = 0; $index < SystemFields::SAMPLE_ROWS; $index++) {
            $row = [];

            // Cycle through whatever exists rather than running out of branches.
            $branch = $branches === [] ? null : $branches[$index % count($branches)];
            $bcCode = $bcCodes === [] ? null : $bcCodes[$index % count($bcCodes)]['bc_code'];

            foreach ($keys as $key) {
                $row[] = match ($key) {
                    'account_number' => self::ACCOUNT_PREFIX . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'branch_code' => (string) ($branch['code'] ?? ''),
                    'branch_name' => (string) ($branch['name'] ?? ''),
                    'bc_code' => (string) ($bcCode ?? ''),
                    default => SystemFields::sample($key, $index),
                };
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Writes the demo sheet and returns its path.
     *
     * @param string $format 'xlsx' or 'csv'
     */
    public static function write(string $format = 'xlsx'): string
    {
        $format = strtolower($format) === 'csv' ? 'csv' : 'xlsx';

        // storage/generated is where every other generated export goes, and it is
        // already gitignored and already created by the installer. A new directory
        // here would be one more thing to create and one more thing to exclude.
        $directory = storage_path('generated');

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory . '/lrms-sample-' . bin2hex(random_bytes(6)) . '.' . $format;

        if ($format === 'csv') {
            $writer = new CsvWriter($path);
            $writer->headers(self::headers());
            $writer->rows(self::rows());
            $writer->close();

            return $path;
        }

        // No title rows: the importer reads column names from row 1, so a
        // decorative banner above them would make this file unimportable.
        $writer = new XlsxWriter('Loan accounts');
        $writer->headers(self::headers());
        $writer->rows(self::rows());
        $writer->save($path);

        return $path;
    }

    public static function contentType(string $format): string
    {
        return strtolower($format) === 'csv'
            ? 'text/csv'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
}
