<?php

declare(strict_types=1);

namespace App\Services\Excel;

use RuntimeException;

/**
 * One interface over .xlsx and .csv uploads.
 *
 * The bank's sheets arrive in both shapes, and CSV exports frequently carry a
 * UTF-8 BOM, semicolon delimiters or Windows line endings, all of which are
 * handled here so the rest of the import pipeline sees clean rows.
 */
final class SpreadsheetReader
{
    public const XLSX = 'xlsx';
    public const CSV = 'csv';

    private string $format;
    private ?XlsxReader $xlsx = null;
    private string $delimiter = ',';

    public function __construct(private string $path, ?string $originalName = null)
    {
        if (!is_file($path)) {
            throw new RuntimeException('Uploaded file not found.');
        }

        $extension = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        $this->format = match ($extension) {
            'xlsx', 'xlsm' => self::XLSX,
            'csv', 'txt' => self::CSV,
            'xls' => throw new RuntimeException(
                'The legacy .xls format is not supported. Open the file in Excel and use '
                . '"Save As" to create an .xlsx or .csv file, then upload it again.'
            ),
            default => throw new RuntimeException('Unsupported file type ".' . $extension . '". Upload .xlsx or .csv.'),
        };

        if ($this->format === self::XLSX) {
            $this->xlsx = new XlsxReader($path);
        } else {
            $this->delimiter = self::sniffDelimiter($path);
        }
    }

    public static function isSupported(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['xlsx', 'xlsm', 'csv', 'txt'], true);
    }

    public function format(): string
    {
        return $this->format;
    }

    /** @return array<int, string> */
    public function sheetNames(): array
    {
        return $this->xlsx?->sheetNames() ?? ['Sheet1'];
    }

    /**
     * @return \Generator<int, array<int, string>>
     */
    public function rows(?string $sheet = null, int $limit = 0, int $skip = 0): \Generator
    {
        if ($this->format === self::XLSX && $this->xlsx !== null) {
            yield from $this->xlsx->rows($sheet, $limit, $skip);

            return;
        }

        yield from $this->csvRows($limit, $skip);
    }

    /**
     * @return \Generator<int, array<int, string>>
     */
    private function csvRows(int $limit, int $skip): \Generator
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The CSV file could not be opened.');
        }

        // Strip a UTF-8 BOM if present, otherwise the first header gets mangled.
        $bom = fread($handle, 3);

        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $rowNumber = 0;
        $emitted = 0;

        while (($row = fgetcsv($handle, 0, $this->delimiter)) !== false) {
            $rowNumber++;

            if ($rowNumber <= $skip) {
                continue;
            }

            if ($row === [null] || $row === false) {
                continue;
            }

            $values = [];
            $blank = true;

            foreach (array_values($row) as $index => $value) {
                $value = trim((string) $value);

                // Convert stray Windows-1252 bytes so names do not break UTF-8.
                if ($value !== '' && !mb_check_encoding($value, 'UTF-8')) {
                    $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                }

                $values[$index] = $value;

                if ($value !== '') {
                    $blank = false;
                }
            }

            if ($blank) {
                continue;
            }

            yield $rowNumber => $values;

            $emitted++;

            if ($limit > 0 && $emitted >= $limit) {
                break;
            }
        }

        fclose($handle);
    }

    /**
     * Read the header row and return it as a clean list of column captions.
     *
     * @return array<int, string> column index => header text
     */
    public function headers(?string $sheet = null, int $headerRow = 1): array
    {
        foreach ($this->rows($sheet, 1, max(0, $headerRow - 1)) as $values) {
            $headers = [];

            foreach ($values as $index => $value) {
                $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
                // Unnamed columns still need a stable label for the mapping UI.
                $headers[$index] = $value !== '' ? $value : '(column ' . XlsxReader::columnLetter($index) . ')';
            }

            // Drop trailing empty columns.
            while ($headers !== [] && str_starts_with((string) end($headers), '(column ')) {
                array_pop($headers);
            }

            return $headers;
        }

        return [];
    }

    /**
     * First N data rows as associative arrays keyed by header caption.
     *
     * @return array{headers: array<int, string>, rows: array<int, array{row:int, values:array<int,string>}>}
     */
    public function preview(?string $sheet = null, int $headerRow = 1, int $limit = 20): array
    {
        $headers = $this->headers($sheet, $headerRow);
        $rows = [];

        foreach ($this->rows($sheet, $limit, $headerRow) as $rowNumber => $values) {
            $rows[] = ['row' => $rowNumber, 'values' => $values];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    public function countRows(?string $sheet = null, int $headerRow = 1): int
    {
        $count = 0;

        foreach ($this->rows($sheet, 0, $headerRow) as $ignored) {
            $count++;
        }

        return $count;
    }

    private static function sniffDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ',';
        }

        $sample = (string) fread($handle, 8192);
        fclose($handle);

        $candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];

        foreach (array_keys($candidates) as $delimiter) {
            $candidates[$delimiter] = substr_count($sample, $delimiter);
        }

        arsort($candidates);
        $best = array_key_first($candidates);

        return $candidates[$best] > 0 ? (string) $best : ',';
    }
}
