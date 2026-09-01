<?php

declare(strict_types=1);

namespace App\Services\Excel;

use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/**
 * Streaming .xlsx reader built only on ZipArchive + XMLReader.
 *
 * LRMS deliberately has no Composer requirement, so this replaces
 * PhpSpreadsheet for the one thing the import needs: reading a sheet of loan
 * accounts row by row without loading the whole file into memory.
 *
 * Supported: shared strings, inline strings, numbers, booleans, dates (serial
 * numbers resolved through the style table), sparse rows and skipped columns.
 * Not supported: the legacy binary .xls format (the user is asked to re-save as
 * .xlsx or .csv), formulas are read as their cached values.
 */
final class XlsxReader
{
    private ZipArchive $zip;

    /** @var array<int, string> */
    private array $sharedStrings = [];

    /** @var array<int, bool> cellXf index => is a date format */
    private array $dateStyles = [];

    /** @var array<int, array{name:string, path:string}> */
    private array $sheets = [];

    private bool $loaded = false;

    public function __construct(private string $path)
    {
        if (!is_file($path)) {
            throw new RuntimeException('Spreadsheet not found: ' . basename($path));
        }

        $this->zip = new ZipArchive();

        if ($this->zip->open($path) !== true) {
            throw new RuntimeException(
                'This file could not be opened as an .xlsx workbook. If it is an older .xls file, '
                . 'please re-save it as .xlsx or .csv and upload again.'
            );
        }
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    /* ------------------------------------------------------------------ */
    /* Workbook metadata                                                  */
    /* ------------------------------------------------------------------ */

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->loadSheets();
        $this->loadSharedStrings();
        $this->loadStyles();
    }

    private function loadSheets(): void
    {
        $workbook = $this->xml('xl/workbook.xml');

        if ($workbook === null) {
            throw new RuntimeException('The workbook is missing its xl/workbook.xml entry and cannot be read.');
        }

        // Sheet name => relationship id
        $relationships = [];
        $rels = $this->xml('xl/_rels/workbook.xml.rels');

        if ($rels !== null) {
            foreach ($rels->Relationship as $relationship) {
                $id = (string) $relationship['Id'];
                $target = (string) $relationship['Target'];
                $target = ltrim(str_replace('/xl/', '', $target), '/');

                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . $target;
                }

                $relationships[$id] = $target;
            }
        }

        $index = 0;

        foreach ($workbook->sheets->sheet as $sheet) {
            $index++;
            $name = (string) $sheet['name'];

            $rid = '';
            foreach ($sheet->attributes('r', true) ?? [] as $key => $value) {
                if ($key === 'id') {
                    $rid = (string) $value;
                }
            }

            $path = $relationships[$rid] ?? sprintf('xl/worksheets/sheet%d.xml', $index);

            if ($this->zip->locateName($path) === false) {
                $path = sprintf('xl/worksheets/sheet%d.xml', $index);
            }

            $this->sheets[] = ['name' => $name, 'path' => $path];
        }

        if ($this->sheets === []) {
            throw new RuntimeException('The workbook contains no worksheets.');
        }
    }

    private function loadSharedStrings(): void
    {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }

        $stream = $this->zip->getStream('xl/sharedStrings.xml');

        if ($stream === false) {
            return;
        }

        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        $reader = new XMLReader();

        if (!$reader->XML($contents)) {
            return;
        }

        $index = 0;

        // Advance to the first <si>, then walk sibling to sibling. Using
        // read() again after next() would skip every other element.
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
                break;
            }
        }

        while ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
            $this->sharedStrings[$index] = $this->plainText($reader->readOuterXml());
            $index++;

            if (!$reader->next('si')) {
                break;
            }
        }

        $reader->close();
    }

    /**
     * Rich text runs are split across <r><t> elements; concatenate them and drop
     * phonetic hints so the value matches what the user sees in Excel.
     */
    private function plainText(string $xml): string
    {
        $element = @simplexml_load_string($xml);

        if ($element === false) {
            return '';
        }

        // Remove phonetic runs.
        $text = '';

        if (isset($element->t)) {
            $text .= (string) $element->t;
        }

        foreach ($element->r ?? [] as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /**
     * Build the map of cell-format indexes that represent dates, so serial
     * numbers can be converted back into readable dates.
     */
    private function loadStyles(): void
    {
        $styles = $this->xml('xl/styles.xml');

        if ($styles === null) {
            return;
        }

        // Built-in numeric formats that are dates or times.
        $builtinDates = array_merge(range(14, 22), range(27, 36), range(45, 47), range(50, 58));
        $customDates = [];

        foreach ($styles->numFmts->numFmt ?? [] as $numFmt) {
            $id = (int) $numFmt['numFmtId'];
            $code = (string) $numFmt['formatCode'];

            // Strip quoted literals and colour tokens before sniffing.
            $probe = preg_replace('/"[^"]*"|\[[^\]]*\]/', '', $code) ?? $code;

            if (preg_match('/[dmyhs]/i', $probe) === 1) {
                $customDates[] = $id;
            }
        }

        $index = 0;

        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            $numFmtId = (int) $xf['numFmtId'];
            $this->dateStyles[$index] = in_array($numFmtId, $builtinDates, true)
                || in_array($numFmtId, $customDates, true);
            $index++;
        }
    }

    private function xml(string $entry): ?SimpleXMLElement
    {
        if ($this->zip->locateName($entry) === false) {
            return null;
        }

        $contents = $this->zip->getFromName($entry);

        if ($contents === false || $contents === '') {
            return null;
        }

        $element = @simplexml_load_string($contents);

        return $element === false ? null : $element;
    }

    /* ------------------------------------------------------------------ */
    /* Public API                                                         */
    /* ------------------------------------------------------------------ */

    /** @return array<int, string> */
    public function sheetNames(): array
    {
        $this->load();

        return array_map(static fn (array $sheet): string => $sheet['name'], $this->sheets);
    }

    /**
     * Read rows from a sheet.
     *
     * @param string|null $sheetName Defaults to the first sheet.
     * @param int         $limit     0 = all rows.
     * @param int         $skip      Number of leading rows to skip.
     * @return \Generator<int, array<int, string>> row number (1 based) => values by column index
     */
    public function rows(?string $sheetName = null, int $limit = 0, int $skip = 0): \Generator
    {
        $this->load();

        $sheet = $this->sheets[0];

        if ($sheetName !== null && $sheetName !== '') {
            foreach ($this->sheets as $candidate) {
                if ($candidate['name'] === $sheetName) {
                    $sheet = $candidate;
                    break;
                }
            }
        }

        $stream = $this->zip->getStream($sheet['path']);

        if ($stream === false) {
            throw new RuntimeException('Worksheet "' . $sheet['name'] . '" could not be read.');
        }

        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        $reader = new XMLReader();

        if (!$reader->XML($contents)) {
            throw new RuntimeException('Worksheet "' . $sheet['name'] . '" contains invalid XML.');
        }

        $emitted = 0;
        $seen = 0;

        // Position on the first <row>, then move sibling to sibling with
        // next('row'). Calling read() after next() would consume the following
        // row's children and silently drop every second row.
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
                break;
            }
        }

        while ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
            $rowNumber = (int) ($reader->getAttribute('r') ?: ($seen + 1));
            $xml = $reader->readOuterXml();
            $seen++;

            $skipThis = $seen <= $skip;
            $values = $skipThis ? [] : $this->parseRow($xml);

            // Spreadsheets are full of blank rows; they are not data.
            if (!$skipThis && !$this->isBlank($values)) {
                yield $rowNumber => $values;
                $emitted++;

                if ($limit > 0 && $emitted >= $limit) {
                    break;
                }
            }

            if (!$reader->next('row')) {
                break;
            }
        }

        $reader->close();
    }

    /**
     * @param array<int, string> $values
     */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string> zero based column index => value
     */
    private function parseRow(string $xml): array
    {
        $row = @simplexml_load_string($xml);

        if ($row === false) {
            return [];
        }

        $values = [];
        $fallbackIndex = 0;

        foreach ($row->c as $cell) {
            $reference = (string) $cell['r'];
            $index = $reference === '' ? $fallbackIndex : self::columnIndex($reference);
            $fallbackIndex = $index + 1;

            $values[$index] = $this->cellValue($cell);
        }

        if ($values === []) {
            return [];
        }

        // Fill gaps so every row has a contiguous set of indexes.
        $max = max(array_keys($values));

        for ($i = 0; $i <= $max; $i++) {
            $values[$i] ??= '';
        }

        ksort($values);

        return $values;
    }

    private function cellValue(SimpleXMLElement $cell): string
    {
        $type = (string) $cell['t'];

        if ($type === 'inlineStr') {
            return trim($this->plainText($cell->is->asXML() ?: ''));
        }

        $raw = isset($cell->v) ? (string) $cell->v : '';

        if ($raw === '' && isset($cell->is)) {
            return trim($this->plainText($cell->is->asXML() ?: ''));
        }

        if ($type === 's') {
            return trim($this->sharedStrings[(int) $raw] ?? '');
        }

        if ($type === 'b') {
            return $raw === '1' ? 'TRUE' : 'FALSE';
        }

        if ($type === 'e') {
            // Formula error such as #N/A — treat as empty rather than importing it.
            return '';
        }

        if ($type === 'str') {
            return trim($raw);
        }

        if ($raw === '') {
            return '';
        }

        // Numeric: check whether the style makes it a date.
        $styleIndex = $cell['s'] === null ? null : (int) $cell['s'];

        if ($styleIndex !== null && ($this->dateStyles[$styleIndex] ?? false) && is_numeric($raw)) {
            $converted = self::excelSerialToDate((float) $raw);

            if ($converted !== null) {
                return $converted;
            }
        }

        return trim($raw);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * "BC12" => 54 (zero based column index).
     */
    public static function columnIndex(string $reference): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $reference));

        if ($letters === '') {
            return 0;
        }

        $index = 0;

        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    /**
     * 0 => "A", 26 => "AA" — used to label unnamed columns in the mapping UI.
     */
    public static function columnLetter(int $index): string
    {
        $index++;
        $letters = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $index = (int) (($index - $remainder - 1) / 26);
        }

        return $letters;
    }

    /**
     * Excel stores dates as days since 1899-12-30 (with the well known 1900 leap
     * year bug already accounted for by that epoch).
     */
    public static function excelSerialToDate(float $serial): ?string
    {
        if ($serial <= 0 || $serial > 2958465) { // 9999-12-31
            return null;
        }

        $days = (int) floor($serial);
        $fraction = $serial - $days;

        $timestamp = ($days - 25569) * 86400;
        $timestamp += (int) round($fraction * 86400);

        if ($timestamp < -2208988800) { // before 1900
            return null;
        }

        // Time-only values (serial < 1) are not dates.
        if ($days === 0) {
            return gmdate('H:i:s', (int) round($fraction * 86400));
        }

        return $fraction > 0
            ? gmdate('Y-m-d H:i:s', $timestamp)
            : gmdate('Y-m-d', $timestamp);
    }
}
