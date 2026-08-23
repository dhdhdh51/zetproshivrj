<?php

declare(strict_types=1);

namespace App\Services\Export;

use RuntimeException;

/**
 * Small dependency-free PDF writer for LRMS report exports.
 *
 * Supports what the reports actually need: a titled header band repeated on
 * every page, paginated tables with measured column widths, key/value detail
 * blocks, embedded JPEG photographs and a footer with page numbers and the
 * generation timestamp.
 *
 * Text is rendered with the standard Helvetica fonts (WinAnsi). That covers
 * Latin text, digits and the ₹ symbol (mapped to "Rs."); characters outside
 * WinAnsi — Devanagari borrower names, for instance — cannot be drawn by a base
 * font and are transliterated where possible, so the Excel/CSV exports remain
 * the right choice when a report must preserve non-Latin script exactly.
 */
final class PdfWriter
{
    /** @var array<int, string> PDF object bodies, 1-indexed by object number */
    private array $objects = [];

    /** @var array<int, array{content:string, width:float, height:float}> */
    private array $pages = [];

    private string $currentContent = '';

    private float $pageWidth;
    private float $pageHeight;
    private float $marginLeft = 32.0;
    private float $marginRight = 32.0;
    private float $marginTop = 40.0;
    private float $marginBottom = 40.0;

    /** Current cursor, measured from the top of the page. */
    private float $y = 0.0;

    private string $font = 'F1';
    private float $fontSize = 9.0;

    /** @var array<string, array{object:int, width:int, height:int}> */
    private array $images = [];

    private string $title = '';
    private string $subtitle = '';
    /** @var array<int, string> */
    private array $metaLines = [];

    /**
     * Set by documentHeader() when this PDF is the client's form rather than an
     * ordinary management report.
     *
     * @var array{organisation: string, subtitles: array<int, string>}|null
     */
    private ?array $documentHeading = null;

    /** True once the full title block has been drawn, so it is not repeated. */
    private bool $documentHeadingDrawn = false;

    /**
     * The bank's letterhead mark, drawn in the header of every page.
     *
     * Resolved once and remembered — including a resolution to nothing, so a missing or
     * unreadable file is not looked for again on every page of a fifty-page report.
     */
    private ?string $logoPath = null;

    private bool $logoResolved = false;

    private const FONT_REGULAR = 'F1';
    private const FONT_BOLD = 'F2';

    /**
     * The palette of the client's own Word template, kept as the hex codes taken
     * from that file so the two can be compared without arithmetic.
     *
     * Everything on the printed form is a shaded block — the template sets every
     * table border to `none`. Drawing boxes with outlines would look tidier on
     * screen and would not be the document the branch is used to signing.
     */
    private const INK_NAVY = '1f3864';      // header block and section bars
    private const INK_GOLD = 'b8860b';      // report title, section numbers
    private const INK_WHITE = 'ffffff';
    private const INK_PALE = 'd9e2f1';      // header subtitles on navy
    private const INK_TEAL = '0e7c7b';      // the tick glyph
    private const FILL_TICK = 'eaf4f4';     // tick-box cells
    private const INK_BODY = '2b2b2b';      // labels and values
    private const FILL_DECLARATION = 'fbf3df';
    private const FILL_NOTE = 'eef2f8';
    private const INK_MUTED = '6b7280';

    /** Hex to the `r g b` triple a PDF content stream wants. */
    private static function ink(string $hex): string
    {
        return sprintf(
            '%.3F %.3F %.3F',
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255
        );
    }

    public function __construct(private string $orientation = 'portrait', string $size = 'A4')
    {
        // A4 in points.
        [$short, $long] = $size === 'letter' ? [612.0, 792.0] : [595.28, 841.89];

        if ($orientation === 'landscape') {
            $this->pageWidth = $long;
            $this->pageHeight = $short;
        } else {
            $this->pageWidth = $short;
            $this->pageHeight = $long;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Document setup                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<int, string> $metaLines Filter summary lines shown under the title.
     */
    public function header(string $title, string $subtitle = '', array $metaLines = []): void
    {
        $this->title = $title;
        $this->subtitle = $subtitle;
        $this->metaLines = $metaLines;
    }

    /**
     * The four-line title block of the field visit verification report.
     *
     * Reproduces the client's template: organisation name, the report title in
     * gold, and two lines of qualifying text, all centred on navy.
     *
     * Pages after the first get a slim navy bar carrying the title only, so a
     * page that comes loose from the staple can still be identified. The template
     * itself is one blank page and says nothing about continuation pages.
     *
     * @param array<int, string> $subtitles
     */
    public function documentHeader(string $organisation, string $title, array $subtitles = []): void
    {
        $this->title = $title;
        $this->documentHeading = [
            'organisation' => $organisation,
            'subtitles' => array_values(array_filter($subtitles, static fn (string $l): bool => trim($l) !== '')),
        ];
    }

    public function contentWidth(): float
    {
        return $this->pageWidth - $this->marginLeft - $this->marginRight;
    }

    /**
     * Override the letterhead mark, or pass null to print without one.
     *
     * Nothing needs to call this: the header finds the logo by itself, because "every page of
     * every PDF" is not a thing four separate call sites should each have to remember.
     */
    public function logo(?string $path): void
    {
        $this->logoPath = $path;
        $this->logoResolved = true;
    }

    /**
     * The file to print in the header, or null if there is not a usable one.
     *
     * A site can point `site_logo` at its own file — a sharper scan, or a different bank —
     * and that wins. Otherwise the Central Bank of India letterhead that ships with the
     * project is used, because that is whose forms these are.
     */
    private function logoFile(): ?string
    {
        if ($this->logoResolved) {
            return $this->logoPath;
        }

        $this->logoResolved = true;
        $this->logoPath = null;

        $candidates = [];
        $configured = trim((string) (function_exists('setting') ? setting('site_logo', '') : ''));

        if ($configured !== '') {
            // Stored either as a path inside the project or as an upload under storage/.
            $candidates[] = $configured;
            $candidates[] = base_path($configured);
            $candidates[] = storage_path($configured);
        }

        $candidates[] = base_path('public/assets/img/cbi-logo.jpg');

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
                $this->logoPath = $candidate;

                break;
            }
        }

        return $this->logoPath;
    }

    /**
     * Draw the letterhead mark at `$x`, `$y`, no taller than `$maxHeight`, and report how
     * much horizontal room it took.
     *
     * The logo is a JPEG with a white background, and the header bands are navy, so it sits on
     * a white patch. Compositing it onto the navy would need an alpha channel the source file
     * does not have; a white panel is what a printed letterhead does anyway.
     *
     * Returns 0.0 when there is no logo, so a caller can lay out around it either way.
     */
    private function drawLogo(float $x, float $y, float $maxHeight): float
    {
        $file = $this->logoFile();

        if ($file === null) {
            return 0.0;
        }

        $image = $this->prepareImage($file);

        if ($image === null || $image['height'] <= 0) {
            return 0.0;
        }

        $padding = min(3.0, $maxHeight * 0.14);
        $height = $maxHeight - ($padding * 2);
        $width = $height * ($image['width'] / $image['height']);

        // A logo wider than a third of the page would crowd out the title it sits beside.
        $limit = $this->contentWidth() / 3;

        if ($width > $limit) {
            $width = $limit;
            $height = $width * ($image['height'] / $image['width']);
        }

        $panelWidth = $width + ($padding * 2);
        $this->rect($x, $y, $panelWidth, $maxHeight, self::ink(self::INK_WHITE), true);

        $top = $y + (($maxHeight - $height) / 2);

        $this->currentContent .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
            $width,
            $height,
            $x + $padding,
            $this->pageHeight - ($top + $height),
            $image['key']
        );

        return $panelWidth;
    }

    public function addPage(): void
    {
        if ($this->currentContent !== '') {
            $this->pages[] = [
                'content' => $this->currentContent,
                'width' => $this->pageWidth,
                'height' => $this->pageHeight,
            ];
        }

        $this->currentContent = '';
        $this->y = $this->marginTop;
        $this->drawHeaderBand();
    }

    private function ensurePage(float $needed = 0.0): void
    {
        if ($this->pages === [] && $this->currentContent === '') {
            $this->addPage();
        }

        if ($needed > 0 && $this->y + $needed > $this->pageHeight - $this->marginBottom) {
            $this->addPage();
        }
    }

    private function drawHeaderBand(): void
    {
        if ($this->documentHeading !== null) {
            $this->drawDocumentHeading();

            return;
        }

        if ($this->title === '') {
            return;
        }

        $bandHeight = 46.0;

        // Dark band with the report title.
        $this->rect($this->marginLeft, $this->y, $this->contentWidth(), $bandHeight, '0.118 0.227 0.373', true);

        // The letterhead goes at the left of the band, and the title starts after it.
        $logoWidth = $this->drawLogo($this->marginLeft, $this->y, $bandHeight);
        $textLeft = $this->marginLeft + ($logoWidth > 0 ? $logoWidth + 10 : 10);

        $this->setFont(self::FONT_BOLD, 13);
        $this->drawText($textLeft, $this->y + 17, $this->title, '1 1 1');

        if ($this->subtitle !== '') {
            $this->setFont(self::FONT_REGULAR, 8.5);
            $this->drawText($textLeft, $this->y + 32, $this->subtitle, '0.85 0.89 0.95');
        }

        $this->setFont(self::FONT_REGULAR, 7.5);
        $this->drawText(
            $this->marginLeft + $this->contentWidth() - 10,
            $this->y + 17,
            'Generated ' . date('d M Y, h:i A'),
            '0.85 0.89 0.95',
            'right'
        );

        $this->y += $bandHeight + 8;

        if ($this->metaLines !== []) {
            $this->setFont(self::FONT_REGULAR, 8);

            foreach ($this->metaLines as $line) {
                $this->drawText($this->marginLeft, $this->y + 7, $line, '0.35 0.40 0.48');
                $this->y += 11;
            }

            $this->y += 4;
        }
    }

    /**
     * The template's title block: organisation, title, two qualifying lines,
     * centred on navy. Sizes and colours are the template's own.
     */
    private function drawDocumentHeading(): void
    {
        $heading = $this->documentHeading;

        if ($heading === null) {
            return;
        }

        $centre = $this->marginLeft + ($this->contentWidth() / 2);

        // Continuation pages carry a slim bar rather than the whole block — with the
        // letterhead on it, so a page that comes loose from the staple is still the bank's.
        if ($this->documentHeadingDrawn) {
            $barHeight = 24.0;
            $this->rect($this->marginLeft, $this->y, $this->contentWidth(), $barHeight, self::ink(self::INK_NAVY), true);

            $logoWidth = $this->drawLogo($this->marginLeft, $this->y, $barHeight);

            $this->setFont(self::FONT_BOLD, 9.5);
            // Centred on the room left over, so the title is not pushed off centre by the logo
            // on one side only.
            $this->drawText(
                $this->marginLeft + $logoWidth + (($this->contentWidth() - $logoWidth) / 2),
                $this->y + 15.5,
                $this->fit($this->title, $this->contentWidth() - $logoWidth - 16, 9.5, self::FONT_BOLD),
                self::ink(self::INK_WHITE),
                'center'
            );
            $this->y += $barHeight + 8;

            return;
        }

        $lines = [
            ['text' => $heading['organisation'], 'size' => 17.0, 'font' => self::FONT_BOLD, 'ink' => self::INK_WHITE],
            ['text' => $this->title, 'size' => 13.0, 'font' => self::FONT_BOLD, 'ink' => self::INK_GOLD],
        ];

        foreach ($heading['subtitles'] as $index => $subtitle) {
            $lines[] = [
                'text' => $subtitle,
                'size' => $index === 0 ? 9.5 : 9.0,
                'font' => self::FONT_REGULAR,
                'ink' => self::INK_PALE,
            ];
        }

        $padding = 11.0;
        $gap = 4.5;
        $blockHeight = $padding * 2;

        foreach ($lines as $line) {
            $blockHeight += $line['size'] + $gap;
        }

        $blockHeight -= $gap;

        // The letterhead sits in the top-left of the block. The title lines stay centred on
        // the page, as the client's template has them, so the logo has to be narrow enough not
        // to reach them — drawLogo() caps itself at a third of the width.
        $logoHeight = min(38.0, $blockHeight - ($padding * 2));

        $this->rect($this->marginLeft, $this->y, $this->contentWidth(), $blockHeight, self::ink(self::INK_NAVY), true);
        $this->drawLogo($this->marginLeft + 6, $this->y + $padding, $logoHeight);

        $cursor = $this->y + $padding;

        foreach ($lines as $line) {
            $this->setFont($line['font'], $line['size']);
            $this->drawText(
                $centre,
                $cursor + $line['size'],
                $this->fit($line['text'], $this->contentWidth() - 16, $line['size'], $line['font']),
                self::ink($line['ink']),
                'center'
            );
            $cursor += $line['size'] + $gap;
        }

        $this->y += $blockHeight + 9;
        $this->documentHeadingDrawn = true;

        if ($this->metaLines !== []) {
            $this->setFont(self::FONT_REGULAR, 8.0);

            foreach ($this->metaLines as $line) {
                $this->drawText($this->marginLeft, $this->y + 7, $line, self::ink(self::INK_MUTED));
                $this->y += 10.5;
            }

            $this->y += 3;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Content primitives                                                 */
    /* ------------------------------------------------------------------ */

    public function setFont(string $font, float $size): void
    {
        $this->font = $font;
        $this->fontSize = $size;
    }

    public function heading(string $text, float $size = 10.5): void
    {
        $this->ensurePage($size + 12);
        $this->setFont(self::FONT_BOLD, $size);
        $this->drawText($this->marginLeft, $this->y + $size, $text, '0.10 0.14 0.22');
        $this->y += $size + 8;
    }

    public function paragraph(string $text, float $size = 9.0): void
    {
        $this->setFont(self::FONT_REGULAR, $size);
        $lines = $this->wrap($text, $this->contentWidth(), $size, self::FONT_REGULAR);

        foreach ($lines as $line) {
            $this->ensurePage($size + 4);
            $this->drawText($this->marginLeft, $this->y + $size, $line, '0.20 0.25 0.33');
            $this->y += $size + 3;
        }

        $this->y += 4;
    }

    public function spacer(float $height = 8.0): void
    {
        $this->y += $height;
    }

    /**
     * A numbered section band, as used by the field visit verification report
     * ("1. GENERAL INFORMATION").
     */
    public function sectionBand(string $number, string $title): void
    {
        $height = 19.0;
        $this->ensurePage($height + 6);

        // Navy bar, gold number, white title — the template's own styling. The
        // number and title are separate runs there too, in different colours.
        $this->rect($this->marginLeft, $this->y, $this->contentWidth(), $height, self::ink(self::INK_NAVY), true);

        $label = rtrim($number, '.') . '.  ';
        $baseline = $this->y + 13.0;

        $this->setFont(self::FONT_BOLD, 11.0);
        $this->drawText($this->marginLeft + 8, $baseline, $label, self::ink(self::INK_GOLD));

        $offset = $this->textWidth($label, 11.0, self::FONT_BOLD);
        $this->drawText($this->marginLeft + 8 + $offset, $baseline, strtoupper($title), self::ink(self::INK_WHITE));

        $this->y += $height + 7;
    }

    /**
     * A shaded notice block: the declaration and the closing note.
     *
     * @param array<int, string> $paragraphs
     */
    public function noticeBox(string $fillHex, array $paragraphs, ?string $heading = null, float $size = 9.0): void
    {
        $paragraphs = array_values(array_filter($paragraphs, static fn (string $p): bool => trim($p) !== ''));

        if ($paragraphs === []) {
            return;
        }

        $padding = 8.0;
        $inner = $this->contentWidth() - ($padding * 2);
        $lineHeight = $size + 3.0;

        // Measured first so the shading can be drawn behind the text in one
        // piece; a block split mid-sentence across a page break looks like a
        // fault on a document someone has to sign.
        $blocks = [];
        $height = $padding * 2;

        if ($heading !== null && $heading !== '') {
            $blocks[] = ['lines' => [$heading], 'font' => self::FONT_BOLD, 'size' => $size + 0.5];
            $height += $size + 0.5 + 4.0;
        }

        foreach ($paragraphs as $paragraph) {
            $lines = $this->wrap($paragraph, $inner, $size, self::FONT_REGULAR);
            $blocks[] = ['lines' => $lines, 'font' => self::FONT_REGULAR, 'size' => $size];
            $height += (count($lines) * $lineHeight) + 4.0;
        }

        $this->ensurePage($height + 4);
        $this->rect($this->marginLeft, $this->y, $this->contentWidth(), $height, self::ink($fillHex), true);

        $cursor = $this->y + $padding;

        foreach ($blocks as $block) {
            $this->setFont($block['font'], $block['size']);

            foreach ($block['lines'] as $line) {
                $this->drawText($this->marginLeft + $padding, $cursor + $block['size'], $line, self::ink(self::INK_BODY));
                $cursor += $block['font'] === self::FONT_BOLD ? $block['size'] + 4.0 : $lineHeight;
            }

            $cursor += 4.0;
        }

        $this->y += $height + 7;
    }

    /**
     * Ruled boxes to be signed by hand.
     *
     * The report is signed on paper, so what it needs is a line with room above it
     * and a name under it — not a captured image. Printing nothing, as this used to
     * when no signature had been stored, left the certification section with names
     * and dates but nowhere to put a pen.
     *
     * @param array<int, string> $labels
     */
    public function signatureLines(array $labels, float $boxHeight = 34.0): void
    {
        if ($labels === []) {
            return;
        }

        $gutter = 12.0;
        $columns = count($labels);
        $width = ($this->contentWidth() - ($gutter * ($columns - 1))) / $columns;

        $this->ensurePage($boxHeight + 16);

        $top = $this->y;

        foreach (array_values($labels) as $index => $label) {
            $x = $this->marginLeft + ($index * ($width + $gutter));
            $ruleY = $top + $boxHeight;

            // The rule itself, then the label beneath it, the way a form is laid
            // out for signing rather than for reading back.
            $this->line($x, $ruleY, $x + $width, $ruleY, self::ink(self::INK_MUTED));

            $this->setFont(self::FONT_REGULAR, 8.0);
            $this->drawText($x, $ruleY + 9.5, $label, self::ink(self::INK_MUTED));
        }

        $this->y = $top + $boxHeight + 15;
    }

    /**
     * A shaded box left blank for handwriting, as the template does for the
     * observations and recommendation sections when nothing was typed.
     */
    public function writingBox(float $height = 42.0, string $fillHex = self::FILL_NOTE): void
    {
        $this->ensurePage($height + 4);
        $this->rect($this->marginLeft, $this->y, $this->contentWidth(), $height, self::ink($fillHex), true);
        $this->y += $height + 7;
    }

    /**
     * A tick-box list, laid out in columns like the printed form.
     *
     * The boxes are drawn as vector shapes rather than characters: the report
     * uses U+2610 / U+2612, which do not exist in the standard PDF fonts this
     * writer relies on, so a glyph would come out as "?". A drawn box also
     * photocopies and faxes cleanly, which is what these reports are for.
     *
     * @param array<int, string> $options
     * @param array<int, string> $selected values to tick (compared case-insensitively)
     */
    public function checkboxes(array $options, array $selected = [], int $columns = 3, ?string $label = null): void
    {
        if ($options === []) {
            return;
        }

        if ($label !== null && $label !== '') {
            $this->fieldLabel($label);
        }

        $ticked = array_map(
            static fn (string $value): string => strtolower(trim($value)),
            $selected
        );

        $columns = max(1, $columns);
        $gutter = 4.0;
        $cellWidth = ($this->contentWidth() - ($gutter * ($columns - 1))) / $columns;
        $cellHeight = 17.0;
        $rowGap = 3.0;
        $box = 8.0;
        $index = 0;

        foreach ($options as $option) {
            $column = $index % $columns;

            if ($column === 0) {
                $this->ensurePage($cellHeight + $rowGap);
            }

            $x = $this->marginLeft + ($column * ($cellWidth + $gutter));
            $isTicked = in_array(strtolower(trim($option)), $ticked, true);

            // Each option sits in its own pale cell, as in the template.
            $this->rect($x, $this->y, $cellWidth, $cellHeight, self::ink(self::FILL_TICK), true);

            // The glyphs the template uses (U+2610 / U+2612) have no place in the
            // standard PDF fonts, so the marks are drawn. Drawn also survives
            // photocopying, which is what happens to these forms.
            $boxTop = $this->y + (($cellHeight - $box) / 2);
            $boxLeft = $x + 5.0;
            $this->rect($boxLeft, $boxTop, $box, $box, self::ink(self::INK_TEAL), false);

            /*
             * Only what was chosen is marked. Everything else keeps an empty box.
             *
             * Un-chosen options used to be crossed, so that a reader could see the option had
             * been considered rather than skipped. On the page that backfired: a row of four
             * options came out as four marked boxes, and the client read the ticked one as
             * crossed too — "jis par tick kar rahe hai vo bhi cross aata hai". A mark that has
             * to be told apart from another mark by its shape, at eight points, on a
             * photocopy, is not a mark that communicates.
             *
             * Nothing is lost. Every option is still printed, so a group nobody answered is
             * visible as a group with no tick in it — which is what "not asked" looks like on
             * every paper form the branch already uses.
             */
            if ($isTicked) {
                $this->tickMark($boxLeft, $boxTop, $box, self::ink(self::INK_TEAL));
            }

            $font = $isTicked ? self::FONT_BOLD : self::FONT_REGULAR;
            $textLeft = $boxLeft + $box + 5.0;

            $this->setFont($font, 9.0);
            $this->drawText(
                $textLeft,
                $this->y + 11.8,
                $this->fit($option, $x + $cellWidth - $textLeft - 4, 9.0, $font),
                self::ink(self::INK_BODY)
            );

            $index++;

            if ($index % $columns === 0) {
                $this->y += $cellHeight + $rowGap;
            }
        }

        if ($index % $columns !== 0) {
            $this->y += $cellHeight + $rowGap;
        }

        $this->y += 2;
    }

    /**
     * The mark a chosen option carries, and the only mark on the page.
     *
     * A tick, in two strokes: a short fall to the low point, then a longer rise. It used to
     * be drawn as two crossing diagonals — an X — because that is the glyph the Word template
     * uses for a chosen box, and an X in a box is read by most people as "not this".
     *
     * Drawn heavier than the hairlines around it. This is the one mark on the form that
     * carries an answer, and it has to survive being photocopied twice and read at arm's
     * length: at eight points a 0.4pt tick is a smudge, and a smudge in a box is exactly what
     * gets mistaken for a cross.
     */
    private function tickMark(float $left, float $top, float $size, string $colour): void
    {
        $weight = max(0.9, $size * 0.16);

        $this->line(
            $left + ($size * 0.18),
            $top + ($size * 0.52),
            $left + ($size * 0.40),
            $top + ($size * 0.76),
            $colour,
            $weight
        );
        $this->line(
            $left + ($size * 0.40),
            $top + ($size * 0.76),
            $left + ($size * 0.86),
            $top + ($size * 0.20),
            $colour,
            $weight
        );
    }

    /**
     * A bold sub-heading above a group, as the template puts "Case Type" or
     * "Gender" above its tick rows.
     */
    public function fieldLabel(string $text): void
    {
        $this->ensurePage(15);
        $this->setFont(self::FONT_BOLD, 9.5);
        $this->drawText($this->marginLeft, $this->y + 9.5, $text, self::ink(self::INK_BODY));
        $this->y += 14;
    }

    /**
     * A Yes / No pair, which the printed form uses for most of section 6.
     *
     * Answered "yes" prints a tick against Yes and a cross against No, and the reverse.
     * A null value crosses both, which says neither answer was chosen — the question was
     * not reached. It is not the same as printing "No", and the column behind it still
     * holds null rather than a false, so a report and the record agree.
     */
    public function yesNoRow(string $label, ?bool $value, int $columns = 3): void
    {
        $selected = $value === null ? [] : [$value ? 'Yes' : 'No'];

        $this->checkboxes(['Yes', 'No'], $selected, $columns, $label);
    }

    /**
     * Coerce a cell value to printable text.
     *
     * Report rows come straight from PDO, so a cell can be a string, an int, a
     * float, null, a bool from a computed column, or a DateTimeInterface. Each
     * has to become something a reader understands: null prints as an em dash
     * rather than the word "null", and a bool as Yes/No rather than "1".
     */
    private function stringify(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d M Y');
        }

        if (is_float($value)) {
            // Whole numbers read better without trailing zeros.
            return $value === floor($value) && abs($value) < 1.0e15
                ? number_format($value, 0, '.', ',')
                : number_format($value, 2, '.', ',');
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => $this->stringify($item), $value));
        }

        if (is_object($value) && !method_exists($value, '__toString')) {
            return '-';
        }

        return (string) $value;
    }

    /**
     * Two-column key/value block used by the single-record reports
     * (visit report, inspection report, verification report).
     *
     * Values WRAP rather than being clipped. On a report that is filed as
     * evidence, a silently truncated company name, borrower name or address is
     * worse than a taller row, so a long value takes up to three lines and the
     * row grows to fit the tallest cell beside it.
     *
     * @param array<string, string|null> $pairs
     */
    public function keyValues(array $pairs, int $columns = 2): void
    {
        $columns = max(1, $columns);
        $columnWidth = $this->contentWidth() / $columns;
        $labelWidth = $columnWidth * 0.46;
        $valueWidth = $columnWidth - $labelWidth - 8;
        $lineHeight = 10.0;
        $maxLines = 3;

        // Lay the pairs out in rows of $columns so a row can be sized to its
        // tallest cell.
        $cells = [];

        foreach ($pairs as $label => $value) {
            $text = $value === null || $value === '' ? '—' : (string) $value;
            $lines = $this->wrap($text, $valueWidth, 8.5, self::FONT_BOLD);

            if (count($lines) > $maxLines) {
                // Keep the first lines and mark the cut, rather than dropping
                // the rest without saying so.
                $lines = array_slice($lines, 0, $maxLines);
                $lines[$maxLines - 1] = $this->fit($lines[$maxLines - 1] . ' ...', $valueWidth, 8.5, self::FONT_BOLD);
            }

            // Labels wrap rather than being cut. "Father's / Husband's Name :" is
            // the borrower's parentage on a bank document; abbreviating it to
            // "Father's / Husband's N..." is not something to print and sign.
            $labelText = rtrim((string) $label, ' :') . ' :';
            $labelLines = $this->wrap($labelText, $labelWidth - 6, 9.0, self::FONT_BOLD);

            $cells[] = [
                'labelLines' => $labelLines === [] ? [$labelText] : array_slice($labelLines, 0, 2),
                'lines' => $lines === [] ? ['—'] : $lines,
            ];
        }

        foreach (array_chunk($cells, $columns) as $row) {
            $tallest = 1;

            foreach ($row as $cell) {
                $tallest = max($tallest, count($cell['lines']), count($cell['labelLines']));
            }

            $rowHeight = max(15.0, ($tallest * $lineHeight) + 5.0);
            $this->ensurePage($rowHeight + 2);

            foreach ($row as $column => $cell) {
                $x = $this->marginLeft + ($column * $columnWidth);

                // The template writes "Label :" in bold, the same ink as the
                // value, rather than the greyed-out caption a screen would use.
                $this->setFont(self::FONT_BOLD, 9.0);

                foreach ($cell['labelLines'] as $labelIndex => $labelLine) {
                    $this->drawText(
                        $x,
                        $this->y + 10 + ($labelIndex * $lineHeight),
                        $labelLine,
                        self::ink(self::INK_BODY)
                    );
                }

                $this->setFont(self::FONT_BOLD, 8.5);

                foreach ($cell['lines'] as $lineIndex => $line) {
                    $this->drawText(
                        $x + $labelWidth,
                        $this->y + 10 + ($lineIndex * $lineHeight),
                        $line,
                        '0.10 0.14 0.22'
                    );
                }
            }

            $this->y += $rowHeight;
        }

        $this->y += 6;
    }

    /**
     * Paginated table.
     *
     * @param array<int, string>              $headers
     * @param array<int, array<int, mixed>>   $rows
     * @param array<int, float>|null          $weights Relative column widths.
     * @param array<int, string>|null         $aligns  'left'|'right'|'center' per column.
     */
    public function table(array $headers, array $rows, ?array $weights = null, ?array $aligns = null): void
    {
        $columnCount = count($headers);

        if ($columnCount === 0) {
            return;
        }

        $weights ??= array_fill(0, $columnCount, 1.0);
        $weights = array_slice(array_pad($weights, $columnCount, 1.0), 0, $columnCount);
        $totalWeight = array_sum($weights) ?: 1.0;

        $widths = [];

        foreach ($weights as $weight) {
            $widths[] = ($weight / $totalWeight) * $this->contentWidth();
        }

        $aligns ??= array_fill(0, $columnCount, 'left');
        $aligns = array_slice(array_pad($aligns, $columnCount, 'left'), 0, $columnCount);

        $headerHeight = 18.0;
        $this->ensurePage($headerHeight + 20);
        $this->drawTableHeader($headers, $widths, $aligns, $headerHeight);

        $zebra = false;

        foreach ($rows as $row) {
            $row = array_slice(array_pad(array_values($row), $columnCount, ''), 0, $columnCount);

            // Measure the tallest cell so wrapped text stays inside its row.
            $cellLines = [];
            $lineCount = 1;

            foreach ($row as $index => $value) {
                $text = $this->stringify($value);
                $lines = $this->wrap($text, $widths[$index] - 8, 8, self::FONT_REGULAR);
                $lines = array_slice($lines, 0, 3);
                $cellLines[$index] = $lines === [] ? [''] : $lines;
                $lineCount = max($lineCount, count($cellLines[$index]));
            }

            $rowHeight = max(15.0, ($lineCount * 10.0) + 5.0);

            if ($this->y + $rowHeight > $this->pageHeight - $this->marginBottom) {
                $this->addPage();
                $this->drawTableHeader($headers, $widths, $aligns, $headerHeight);
                $zebra = false;
            }

            if ($zebra) {
                $this->rect($this->marginLeft, $this->y, $this->contentWidth(), $rowHeight, '0.965 0.973 0.984', true);
            }

            $zebra = !$zebra;

            $x = $this->marginLeft;
            $this->setFont(self::FONT_REGULAR, 8);

            foreach ($cellLines as $index => $lines) {
                $lineY = $this->y + 10;

                foreach ($lines as $line) {
                    $align = $aligns[$index];
                    $textX = match ($align) {
                        'right' => $x + $widths[$index] - 4,
                        'center' => $x + ($widths[$index] / 2),
                        default => $x + 4,
                    };

                    $this->drawText($textX, $lineY, $line, '0.15 0.20 0.28', $align);
                    $lineY += 10;
                }

                $x += $widths[$index];
            }

            // Bottom rule.
            $this->line($this->marginLeft, $this->y + $rowHeight, $this->marginLeft + $this->contentWidth(), $this->y + $rowHeight, '0.88 0.91 0.95');

            $this->y += $rowHeight;
        }

        $this->y += 10;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, float>  $widths
     * @param array<int, string> $aligns
     */
    private function drawTableHeader(array $headers, array $widths, array $aligns, float $height): void
    {
        $this->rect($this->marginLeft, $this->y, $this->contentWidth(), $height, '0.90 0.93 0.97', true);
        $this->setFont(self::FONT_BOLD, 8);

        $x = $this->marginLeft;

        foreach ($headers as $index => $header) {
            $align = $aligns[$index] ?? 'left';
            $textX = match ($align) {
                'right' => $x + $widths[$index] - 4,
                'center' => $x + ($widths[$index] / 2),
                default => $x + 4,
            };

            $this->drawText(
                $textX,
                $this->y + 12,
                $this->fit((string) $header, $widths[$index] - 8, 8, self::FONT_BOLD),
                '0.11 0.20 0.35',
                $align
            );

            $x += $widths[$index];
        }

        $this->y += $height;
    }

    /**
     * Embed a JPEG photograph. PNGs are converted with GD when available,
     * because PDF can only carry JPEG data directly without a filter chain.
     */
    public function image(string $path, float $width, float $height, ?string $caption = null): void
    {
        $prepared = $this->prepareImage($path);

        if ($prepared === null) {
            return;
        }

        $needed = $height + ($caption !== null ? 12 : 0) + 6;
        $this->ensurePage($needed);

        $key = $prepared['key'];
        $x = $this->marginLeft;
        $bottomY = $this->pageHeight - ($this->y + $height);

        $this->currentContent .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
            $width,
            $height,
            $x,
            $bottomY,
            $key
        );

        $this->y += $height + 2;

        if ($caption !== null) {
            $this->setFont(self::FONT_REGULAR, 7.5);
            $this->drawText($x, $this->y + 8, $caption, '0.40 0.45 0.53');
            $this->y += 12;
        }

        $this->y += 4;
    }

    /**
     * Lay photographs out in a grid, which is how the visit and inspection
     * reports present their evidence.
     *
     * @param array<int, array{path:string, caption?:string}> $photos
     */
    public function imageGrid(array $photos, int $columns = 3): void
    {
        if ($photos === []) {
            return;
        }

        $columns = max(1, $columns);
        $gap = 8.0;
        $cellWidth = ($this->contentWidth() - ($gap * ($columns - 1))) / $columns;
        $cellHeight = $cellWidth * 0.75;

        $index = 0;
        $rowTop = null;

        foreach ($photos as $photo) {
            $prepared = $this->prepareImage($photo['path']);

            if ($prepared === null) {
                continue;
            }

            $column = $index % $columns;

            if ($column === 0) {
                $this->ensurePage($cellHeight + 16);
                $rowTop = $this->y;
            }

            $x = $this->marginLeft + ($column * ($cellWidth + $gap));
            $bottomY = $this->pageHeight - (($rowTop ?? $this->y) + $cellHeight);

            $this->currentContent .= sprintf(
                "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
                $cellWidth,
                $cellHeight,
                $x,
                $bottomY,
                $prepared['key']
            );

            if (isset($photo['caption'])) {
                $this->setFont(self::FONT_REGULAR, 7);
                $this->drawText(
                    $x,
                    ($rowTop ?? $this->y) + $cellHeight + 8,
                    $this->fit($photo['caption'], $cellWidth, 7, self::FONT_REGULAR),
                    '0.40 0.45 0.53'
                );
            }

            $index++;

            if ($index % $columns === 0) {
                $this->y = ($rowTop ?? $this->y) + $cellHeight + 16;
            }
        }

        if ($index % $columns !== 0) {
            $this->y = ($rowTop ?? $this->y) + $cellHeight + 16;
        }
    }

    /**
     * @return array{key:string, width:int, height:int}|null
     */
    private function prepareImage(string $path): ?array
    {
        if (isset($this->images[$path])) {
            return ['key' => 'Im' . $this->images[$path]['object'], 'width' => $this->images[$path]['width'], 'height' => $this->images[$path]['height']];
        }

        if (!is_file($path)) {
            return null;
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        [$width, $height, $type] = $info;
        $data = null;

        if ($type === IMAGETYPE_JPEG) {
            $data = (string) file_get_contents($path);
        } elseif (function_exists('imagecreatefromstring')) {
            // Re-encode anything else (PNG/WebP) as JPEG.
            $source = @imagecreatefromstring((string) file_get_contents($path));

            if ($source !== false) {
                ob_start();
                imagejpeg($source, null, 85);
                $data = (string) ob_get_clean();
                imagedestroy($source);
            }
        }

        if ($data === null || $data === '') {
            return null;
        }

        $objectNumber = $this->addObject(
            "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} "
            . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($data)
            . " >>\nstream\n" . $data . "\nendstream"
        );

        $this->images[$path] = ['object' => $objectNumber, 'width' => $width, 'height' => $height];

        return ['key' => 'Im' . $objectNumber, 'width' => $width, 'height' => $height];
    }

    /* ------------------------------------------------------------------ */
    /* Verification                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * The verification panel that closes every exported document.
     *
     * A printed page leaves the system the moment it is printed: it gets photocopied,
     * faxed, carried to a branch and filed, and from then on nothing about it can be
     * checked. The QR is what puts the paper back in touch with the record — scan it and
     * the panel opens the entry this sheet was printed from, where the submitted answers,
     * the photographs and the GPS fix all still are.
     *
     * The panel requires a login, so the code is not a way of reading customer data off a
     * discarded printout; it is a way for staff who already have access to get from the
     * paper to the source without typing a reference. The reference is printed underneath
     * anyway, for whoever has no phone to hand.
     *
     * @param array<int, string> $lines Reference lines printed beside the code.
     */
    public function verification(
        string $url,
        array $lines = [],
        string $heading = 'Scan to open this record',
        string $note = 'Opens the entry in the LRMS panel. A panel login is required.'
    ): void {
        $codeSize = 62.0;
        $padding = 9.0;
        $height = $codeSize + ($padding * 2);

        // Keep the code and its caption together: a QR alone on a page, or a caption whose
        // code went over the fold, is worse than either on the previous page.
        $this->ensurePage($height + 10);

        $top = $this->y;
        $this->rect($this->marginLeft, $top, $this->contentWidth(), $height, self::ink(self::FILL_NOTE), true);

        $this->drawQr($url, $this->marginLeft + $padding, $top + $padding, $codeSize);

        $textLeft = $this->marginLeft + $padding + $codeSize + 12;
        $textWidth = $this->contentWidth() - ($textLeft - $this->marginLeft) - $padding;
        $y = $top + $padding + 9;

        $this->setFont(self::FONT_BOLD, 8.5);
        $this->drawText($textLeft, $y, $heading, self::ink(self::INK_NAVY));
        $y += 12;

        $this->setFont(self::FONT_REGULAR, 7.5);
        $this->drawText($textLeft, $y, $this->fit($note, $textWidth, 7.5, self::FONT_REGULAR), self::ink(self::INK_MUTED));
        $y += 11;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $this->drawText($textLeft, $y, $this->fit($line, $textWidth, 7.5, self::FONT_REGULAR), self::ink(self::INK_BODY));
            $y += 10;
        }

        $this->y = $top + $height + 10;
    }

    /**
     * Draw a QR code for `$data` as a square of `$size` points with its top-left corner at
     * `$x`, `$y`.
     *
     * `$size` is the whole footprint, quiet zone included. The specification wants four
     * modules of clear space around a code and a scanner really does need it, so the four
     * modules are drawn here as white rather than left to whatever the code happens to be
     * placed on — which on these pages is a tinted panel.
     *
     * The dark modules of a row are merged into runs before being drawn. That keeps the
     * content stream small, and it removes the hairline seams that abutting rectangles can
     * show, which is exactly the artefact that makes a printed code fail to scan.
     */
    private function drawQr(string $data, float $x, float $y, float $size): void
    {
        $matrix = QrCode::encode($data)->matrix();
        $modules = count($matrix);

        // Four modules of quiet zone on each side.
        $step = $size / ($modules + 8);
        $originX = $x + ($step * 4);
        $originY = $y + ($step * 4);

        $this->rect($x, $y, $size, $size, self::ink(self::INK_WHITE), true);

        $path = '';

        foreach ($matrix as $row => $cells) {
            $runStart = null;

            // One past the end, so a run that reaches the right edge is still closed.
            for ($col = 0; $col <= $modules; $col++) {
                $dark = $col < $modules && $cells[$col];

                if ($dark && $runStart === null) {
                    $runStart = $col;

                    continue;
                }

                if ($dark || $runStart === null) {
                    continue;
                }

                $left = $originX + ($runStart * $step);
                $top = $originY + ($row * $step);

                $path .= sprintf(
                    '%.3F %.3F %.3F %.3F re ',
                    $left,
                    $this->pageHeight - ($top + $step),
                    ($col - $runStart) * $step,
                    $step
                );

                $runStart = null;
            }
        }

        if ($path === '') {
            return;
        }

        // One fill for the whole code: hundreds of separate fills would be the same picture
        // and several times the file.
        $this->currentContent .= 'q 0 0 0 rg ' . $path . "f Q\n";
    }

    /* ------------------------------------------------------------------ */
    /* Drawing helpers (y measured from the top)                          */
    /* ------------------------------------------------------------------ */

    private function drawText(float $x, float $y, string $text, string $colour = '0 0 0', string $align = 'left'): void
    {
        $encoded = $this->encode($text);

        if ($encoded === '') {
            return;
        }

        if ($align !== 'left') {
            $width = $this->textWidth($text, $this->fontSize, $this->font);
            $x -= $align === 'right' ? $width : $width / 2;
        }

        $this->currentContent .= sprintf(
            "BT %s rg /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $colour,
            $this->font,
            $this->fontSize,
            $x,
            $this->pageHeight - $y,
            $encoded
        );
    }

    private function rect(float $x, float $y, float $width, float $height, string $colour, bool $filled = true): void
    {
        $this->currentContent .= sprintf(
            "q %s %s %.2F %.2F %.2F %.2F re %s Q\n",
            $colour,
            $filled ? 'rg' : 'RG',
            $x,
            $this->pageHeight - ($y + $height),
            $width,
            $height,
            $filled ? 'f' : 'S'
        );
    }

    /**
     * A straight stroke. `$width` is in points; the default is the hairline used for rules
     * and box outlines. Round caps and joins, so a heavier stroke reads as a drawn mark
     * rather than as two rectangles meeting at a corner.
     */
    private function line(float $x1, float $y1, float $x2, float $y2, string $colour, float $width = 0.4): void
    {
        $this->currentContent .= sprintf(
            "q %s RG %.2F w 1 J 1 j %.2F %.2F m %.2F %.2F l S Q\n",
            $colour,
            $width,
            $x1,
            $this->pageHeight - $y1,
            $x2,
            $this->pageHeight - $y2
        );
    }

    /* ------------------------------------------------------------------ */
    /* Text measurement                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int, string>
     */
    private function wrap(string $text, float $maxWidth, float $size, string $font): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === '') {
            return [];
        }

        if ($this->textWidth($text, $size, $font) <= $maxWidth) {
            return [$text];
        }

        $lines = [];
        $current = '';

        foreach (explode(' ', $text) as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if ($this->textWidth($candidate, $size, $font) <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            // A single word longer than the column: hard split it.
            while ($this->textWidth($word, $size, $font) > $maxWidth && mb_strlen($word) > 1) {
                $cut = mb_strlen($word);

                while ($cut > 1 && $this->textWidth(mb_substr($word, 0, $cut), $size, $font) > $maxWidth) {
                    $cut--;
                }

                $lines[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }

            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function fit(string $text, float $maxWidth, float $size, string $font): string
    {
        if ($this->textWidth($text, $size, $font) <= $maxWidth) {
            return $text;
        }

        $ellipsis = '...';

        while ($text !== '' && $this->textWidth($text . $ellipsis, $size, $font) > $maxWidth) {
            $text = mb_substr($text, 0, max(0, mb_strlen($text) - 1));
        }

        return $text . $ellipsis;
    }

    /**
     * Helvetica / Helvetica-Bold advance widths (units of 1/1000 em).
     */
    private function textWidth(string $text, float $size, string $font): float
    {
        $widths = self::characterWidths($font === self::FONT_BOLD);
        $total = 0;
        $latin = $this->toWinAnsi($text);

        for ($i = 0, $length = strlen($latin); $i < $length; $i++) {
            $total += $widths[ord($latin[$i])] ?? 556;
        }

        return ($total / 1000) * $size;
    }

    /** @return array<int, int> */
    private static function characterWidths(bool $bold): array
    {
        static $cache = [];
        $key = $bold ? 'bold' : 'regular';

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        // Widths for ASCII 32..126 taken from the standard Helvetica metrics.
        $regular = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

        $boldWidths = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
            975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
            333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
            611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

        $source = $bold ? $boldWidths : $regular;
        $map = [];

        foreach ($source as $index => $width) {
            $map[32 + $index] = $width;
        }

        $cache[$key] = $map;

        return $map;
    }

    /**
     * Convert UTF-8 to WinAnsi (CP1252), transliterating what it can and
     * replacing the rest so the PDF never contains invalid bytes.
     */
    private function toWinAnsi(string $text): string
    {
        // Currency and typographic characters the reports use.
        $text = str_replace(
            ['₹', '—', '–', '“', '”', '‘', '’', '•', '…', "\u{00A0}"],
            ['Rs.', '-', '-', '"', '"', "'", "'", '-', '...', ' '],
            $text
        );

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $text);

            if ($converted !== false) {
                return (string) $converted;
            }
        }

        $converted = @mb_convert_encoding($text, 'CP1252', 'UTF-8');

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '' : $converted;
    }

    private function encode(string $text): string
    {
        $latin = $this->toWinAnsi($text);

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $latin);
    }

    /* ------------------------------------------------------------------ */
    /* Output                                                             */
    /* ------------------------------------------------------------------ */

    private function addObject(string $body): int
    {
        $this->objects[] = $body;

        // Object numbers are assigned at assembly time; store a placeholder index.
        return count($this->objects);
    }

    public function save(string $path): string
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The export directory could not be created.');
        }

        if (file_put_contents($path, $this->render()) === false) {
            throw new RuntimeException('The PDF export could not be written.');
        }

        return $path;
    }

    public function render(): string
    {
        // Flush the page in progress.
        if ($this->currentContent !== '') {
            $this->pages[] = [
                'content' => $this->currentContent,
                'width' => $this->pageWidth,
                'height' => $this->pageHeight,
            ];
            $this->currentContent = '';
        }

        if ($this->pages === []) {
            $this->addPage();
            $this->pages[] = ['content' => $this->currentContent, 'width' => $this->pageWidth, 'height' => $this->pageHeight];
        }

        $pageCount = count($this->pages);

        // Object layout:
        //   1            Catalog
        //   2            Pages
        //   3, 4         Fonts (Helvetica, Helvetica-Bold)
        //   5..(5+n*2)   Page + content stream pairs
        //   then         Images (already collected in $this->objects)
        $imageObjects = $this->objects;
        $firstImageNumber = 5 + ($pageCount * 2);

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = [];

        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = sprintf('%d 0 R', 5 + ($i * 2));
        }

        $objects[2] = sprintf(
            '<< /Type /Pages /Count %d /Kids [%s] >>',
            $pageCount,
            implode(' ', $kids)
        );

        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        // XObject resource dictionary shared by every page.
        $xObjects = '';

        foreach ($imageObjects as $index => $ignored) {
            $number = $firstImageNumber + $index;
            $xObjects .= sprintf('/Im%d %d 0 R ', $index + 1, $number);
        }

        $resources = '<< /Font << /F1 3 0 R /F2 4 0 R >>'
            . ($xObjects !== '' ? ' /XObject << ' . $xObjects . '>>' : '')
            . ' >>';

        foreach ($this->pages as $index => $page) {
            $pageNumber = 5 + ($index * 2);
            $contentNumber = $pageNumber + 1;

            $content = $page['content'] . $this->footer($index + 1, $pageCount, $page['width'], $page['height']);

            $objects[$pageNumber] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>',
                $page['width'],
                $page['height'],
                $resources,
                $contentNumber
            );

            $objects[$contentNumber] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content),
                $content
            );
        }

        foreach ($imageObjects as $index => $body) {
            $objects[$firstImageNumber + $index] = $body;
        }

        ksort($objects);

        // Assemble with a cross-reference table.
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $number, $body);
        }

        $xrefOffset = strlen($pdf);
        $maxObject = max(array_keys($objects));

        $pdf .= sprintf("xref\n0 %d\n", $maxObject + 1);
        $pdf .= "0000000000 65535 f \n";

        for ($number = 1; $number <= $maxObject; $number++) {
            $pdf .= isset($offsets[$number])
                ? sprintf("%010d 00000 n \n", $offsets[$number])
                : "0000000000 65535 f \n";
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info << /Producer (LRMS) /CreationDate (D:%s) >> >>\nstartxref\n%d\n%%%%EOF",
            $maxObject + 1,
            date('YmdHis'),
            $xrefOffset
        );

        return $pdf;
    }

    private function footer(int $page, int $total, float $width, float $height): string
    {
        $text = sprintf('Page %d of %d', $page, $total);
        $left = 'LRMS — Loan Recovery Management System. Confidential: contains customer information.';

        $encodedLeft = $this->encode($left);
        $encodedRight = $this->encode($text);

        $rightWidth = $this->textWidth($text, 7.5, self::FONT_REGULAR);

        return sprintf(
            "q 0.80 0.84 0.90 RG 0.4 w %.2F %.2F m %.2F %.2F l S Q\n"
            . "BT 0.45 0.50 0.58 rg /F1 7.00 Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n"
            . "BT 0.45 0.50 0.58 rg /F1 7.50 Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $this->marginLeft,
            $this->marginBottom - 12,
            $width - $this->marginRight,
            $this->marginBottom - 12,
            $this->marginLeft,
            $this->marginBottom - 24,
            $encodedLeft,
            $width - $this->marginRight - $rightWidth,
            $this->marginBottom - 24,
            $encodedRight
        );
    }
}
