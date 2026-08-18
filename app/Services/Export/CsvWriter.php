<?php

declare(strict_types=1);

namespace App\Services\Export;

use RuntimeException;

/**
 * CSV export. Writes a UTF-8 BOM so Excel on Windows opens Hindi/Devanagari
 * borrower names correctly instead of showing mojibake, and forces long numeric
 * strings (account numbers) to stay text.
 */
final class CsvWriter
{
    /** @var resource */
    private $handle;

    private int $rows = 0;

    public function __construct(private string $path)
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The export directory could not be created.');
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('The export file could not be created.');
        }

        $this->handle = $handle;
        fwrite($this->handle, "\xEF\xBB\xBF");
    }

    /** @param array<int, string> $headers */
    public function headers(array $headers): void
    {
        $this->write($headers);
    }

    /** @param array<int, mixed> $row */
    public function row(array $row): void
    {
        $values = [];

        foreach ($row as $value) {
            $values[] = self::cell($value);
        }

        $this->write($values);
        $this->rows++;
    }

    /** @param array<int, array<int, mixed>> $rows */
    public function rows(array $rows): void
    {
        foreach ($rows as $row) {
            $this->row($row);
        }
    }

    /**
     * PHP 8.4 requires the $escape argument explicitly; passing an empty string
     * selects RFC 4180 behaviour (quotes are doubled, backslashes are literal),
     * which is what Excel expects.
     *
     * @param array<int, string> $values
     */
    private function write(array $values): void
    {
        fputcsv($this->handle, $values, ',', '"', '');
    }

    private static function cell(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if ($value === true) {
            return 'Yes';
        }

        $value = (string) $value;

        // Stop spreadsheets turning a 16 digit account number into 3.1E+15, and
        // neutralise formula injection (=cmd, +, -, @ prefixes).
        if (preg_match('/^[=+\-@]/', $value) === 1) {
            return "'" . $value;
        }

        if (preg_match('/^\d{12,}$/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    public function rowCount(): int
    {
        return $this->rows;
    }

    public function close(): string
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        return $this->path;
    }
}
