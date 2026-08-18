<?php

declare(strict_types=1);

namespace App\Services\Export;

use RuntimeException;
use ZipArchive;

/**
 * Minimal .xlsx writer (SpreadsheetML) built on ZipArchive only.
 *
 * Produces a single worksheet with a styled header row, frozen header, auto
 * filter, sensible column widths and typed cells (text / number / date), which
 * is everything an LRMS report export needs — without adding a Composer
 * dependency that shared hosting would have to install.
 */
final class XlsxWriter
{
    private const STYLE_DEFAULT = 0;
    private const STYLE_HEADER = 1;
    private const STYLE_DATE = 2;
    private const STYLE_AMOUNT = 3;
    private const STYLE_TITLE = 4;
    private const STYLE_INTEGER = 5;

    /** @var array<int, string> rendered <row> XML fragments */
    private array $rows = [];

    /** @var array<int, string> */
    private array $headers = [];

    /** @var array<int, int> column index => character width */
    private array $widths = [];

    private int $rowNumber = 0;
    private int $dataRows = 0;
    private int $headerRowNumber = 0;
    private int $columnCount = 0;

    public function __construct(private string $sheetName = 'Report')
    {
    }

    /**
     * A merged title line above the table (report name, filters, generated at).
     */
    public function title(string ...$lines): void
    {
        foreach ($lines as $line) {
            $this->rowNumber++;
            $this->rows[] = sprintf(
                '<row r="%d" ht="18" customHeight="1">%s</row>',
                $this->rowNumber,
                $this->cellXml(0, $this->rowNumber, $line, self::STYLE_TITLE)
            );
        }

        // Blank spacer row.
        $this->rowNumber++;
        $this->rows[] = sprintf('<row r="%d"/>', $this->rowNumber);
    }

    /** @param array<int, string> $headers */
    public function headers(array $headers): void
    {
        $this->headers = array_values($headers);
        $this->columnCount = count($this->headers);
        $this->rowNumber++;
        $this->headerRowNumber = $this->rowNumber;

        $cells = '';

        foreach ($this->headers as $index => $header) {
            $cells .= $this->cellXml($index, $this->rowNumber, $header, self::STYLE_HEADER);
            $this->widths[$index] = max($this->widths[$index] ?? 10, min(46, mb_strlen($header) + 4));
        }

        $this->rows[] = sprintf('<row r="%d" ht="20" customHeight="1">%s</row>', $this->rowNumber, $cells);
    }

    /**
     * @param array<int, mixed> $row Values; floats/ints are written as numbers,
     *                               Y-m-d strings as dates, everything else text.
     */
    public function row(array $row): void
    {
        $this->rowNumber++;
        $this->dataRows++;
        $cells = '';

        foreach (array_values($row) as $index => $value) {
            $style = self::STYLE_DEFAULT;

            if (is_float($value)) {
                $style = self::STYLE_AMOUNT;
            } elseif (is_int($value)) {
                $style = self::STYLE_INTEGER;
            } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $style = self::STYLE_DATE;
            }

            $cells .= $this->cellXml($index, $this->rowNumber, $value, $style);

            $length = mb_strlen((string) (is_float($value) ? number_format($value, 2) : $value));
            $this->widths[$index] = max($this->widths[$index] ?? 10, min(46, $length + 2));
        }

        $this->rows[] = sprintf('<row r="%d">%s</row>', $this->rowNumber, $cells);
    }

    /** @param array<int, array<int, mixed>> $rows */
    public function rows(array $rows): void
    {
        foreach ($rows as $row) {
            $this->row($row);
        }
    }

    public function rowCount(): int
    {
        return $this->dataRows;
    }

    /* ------------------------------------------------------------------ */
    /* Cell rendering                                                     */
    /* ------------------------------------------------------------------ */

    private function cellXml(int $columnIndex, int $rowNumber, mixed $value, int $style): string
    {
        $reference = self::columnLetter($columnIndex) . $rowNumber;

        if ($value === null || $value === '') {
            return sprintf('<c r="%s" s="%d"/>', $reference, $style);
        }

        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            return sprintf('<c r="%s" s="%d"><v>%s</v></c>', $reference, $style, self::number((float) $value));
        }

        $string = (string) $value;

        if ($style === self::STYLE_DATE) {
            $serial = self::dateToSerial($string);

            if ($serial !== null) {
                return sprintf('<c r="%s" s="%d"><v>%d</v></c>', $reference, self::STYLE_DATE, $serial);
            }

            $style = self::STYLE_DEFAULT;
        }

        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $reference,
            $style,
            self::escape($string)
        );
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }

    private static function escape(string $value): string
    {
        // Strip control characters that would make the XML invalid.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function dateToSerial(string $date): ?int
    {
        // Parse as UTC: Excel serials are timezone-less day numbers, and using
        // local time would shift dates by a day for positive offsets like IST.
        $timestamp = strtotime($date . ' UTC');

        if ($timestamp === false) {
            return null;
        }

        return (int) floor($timestamp / 86400) + 25569;
    }

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

    /* ------------------------------------------------------------------ */
    /* Packaging                                                          */
    /* ------------------------------------------------------------------ */

    public function save(string $path): string
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The export directory could not be created.');
        }

        @unlink($path);

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The Excel export could not be created.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('docProps/app.xml', $this->appProps());
        $zip->addFromString('docProps/core.xml', $this->coreProps());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());
        $zip->close();

        return $path;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function appProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>LRMS</Application></Properties>';
    }

    private function coreProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>LRMS</dc:creator><cp:lastModifiedBy>LRMS</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>'
            . '</cp:coreProperties>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape(mb_substr($this->sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="dd\-mmm\-yyyy"/>'
            . '<numFmt numFmtId="165" formatCode="#,##0.00"/>'
            . '</numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="12"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD0D7E2"/></left><right style="thin"><color rgb="FFD0D7E2"/></right>'
            . '<top style="thin"><color rgb="FFD0D7E2"/></top><bottom style="thin"><color rgb="FFD0D7E2"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            // 0 default
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
            // 1 header
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 2 date
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 3 amount
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>'
            // 4 title
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="center"/></xf>'
            // 5 integer
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function sheet(): string
    {
        $columns = '';

        if ($this->widths !== []) {
            $columns = '<cols>';

            foreach ($this->widths as $index => $width) {
                $columns .= sprintf(
                    '<col min="%d" max="%d" width="%d" customWidth="1"/>',
                    $index + 1,
                    $index + 1,
                    max(8, $width)
                );
            }

            $columns .= '</cols>';
        }

        $panes = '';
        $autoFilter = '';

        if ($this->headerRowNumber > 0) {
            $firstDataRow = $this->headerRowNumber + 1;
            $panes = sprintf(
                '<sheetView showGridLines="0" workbookViewId="0"><pane ySplit="%d" topLeftCell="A%d" '
                . 'activePane="bottomLeft" state="frozen"/></sheetView>',
                $this->headerRowNumber,
                $firstDataRow
            );

            if ($this->columnCount > 0 && $this->dataRows > 0) {
                $autoFilter = sprintf(
                    '<autoFilter ref="A%d:%s%d"/>',
                    $this->headerRowNumber,
                    self::columnLetter($this->columnCount - 1),
                    $this->headerRowNumber + $this->dataRows
                );
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . ($panes !== '' ? '<sheetViews>' . $panes . '</sheetViews>' : '')
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $columns
            . '<sheetData>' . implode('', $this->rows) . '</sheetData>'
            . $autoFilter
            . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            . '</worksheet>';
    }
}
