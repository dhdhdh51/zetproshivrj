<?php

declare(strict_types=1);

/**
 * Minimal assertion helpers for the LRMS smoke tests.
 *
 * These tests run against a real MySQL/MariaDB database (the same schema the
 * application uses), because the parts most worth testing here — the importer,
 * the allocation invariants, the deadline logic and the API — are all about SQL
 * behaviour that a mock would not exercise.
 */

final class TestRunner
{
    private static int $passed = 0;
    private static int $failed = 0;
    private static string $section = '';
    /** @var array<int, string> */
    private static array $failures = [];

    public static function section(string $name): void
    {
        self::$section = $name;
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public static function ok(bool $condition, string $message): bool
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32mPASS\033[0m  " . $message . "\n";

            return true;
        }

        self::$failed++;
        self::$failures[] = self::$section . ' → ' . $message;
        echo "  \033[31mFAIL\033[0m  " . $message . "\n";

        return false;
    }

    public static function equals(mixed $expected, mixed $actual, string $message): bool
    {
        $condition = $expected === $actual;

        if (!$condition && (is_scalar($expected) || $expected === null)) {
            $message .= sprintf(' (expected %s, got %s)', var_export($expected, true), var_export($actual, true));
        }

        return self::ok($condition, $message);
    }

    public static function throws(callable $callback, string $message, ?string $contains = null): bool
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($contains !== null && !str_contains(strtolower($e->getMessage()), strtolower($contains))) {
                return self::ok(false, $message . ' (wrong message: ' . $e->getMessage() . ')');
            }

            return self::ok(true, $message);
        }

        return self::ok(false, $message . ' (no exception thrown)');
    }

    public static function summary(): int
    {
        $total = self::$passed + self::$failed;
        echo "\n" . str_repeat('─', 64) . "\n";
        printf("  %d/%d checks passed", self::$passed, $total);

        if (self::$failed > 0) {
            printf(", \033[31m%d failed\033[0m\n\n", self::$failed);

            foreach (self::$failures as $failure) {
                echo "  • " . $failure . "\n";
            }

            echo "\n";

            return 1;
        }

        echo "\033[32m — all good\033[0m\n\n";

        return 0;
    }
}

function section(string $name): void
{
    TestRunner::section($name);
}

function ok(bool $condition, string $message): bool
{
    return TestRunner::ok($condition, $message);
}

function equals(mixed $expected, mixed $actual, string $message): bool
{
    return TestRunner::equals($expected, $actual, $message);
}

function throws(callable $callback, string $message, ?string $contains = null): bool
{
    return TestRunner::throws($callback, $message, $contains);
}


/**
 * The visible text of a PDF produced by App\Services\Export\PdfWriter.
 *
 * The writer emits uncompressed content streams and draws every string with a
 * `Tj` operator, so the printed words can be read back without a PDF library.
 * This lets the suite assert what a report actually says — which section
 * headings appear, which values are printed — instead of only checking that a
 * file was created.
 *
 * Deflated streams are still handled, so this keeps working if the writer later
 * compresses its output.
 */
function pdf_text(string $path): string
{
    if (!is_file($path)) {
        return '';
    }

    $data = (string) file_get_contents($path);
    $text = [];

    if (preg_match_all('/stream\r?\n(.*?)endstream/s', $data, $streams) === false) {
        return '';
    }

    foreach ($streams[1] ?? [] as $stream) {
        $inflated = @gzuncompress($stream);

        if ($inflated === false) {
            $inflated = @gzinflate($stream);
        }

        $content = $inflated === false ? $stream : $inflated;

        if (preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\)\s*Tj/', $content, $matches) === false) {
            continue;
        }

        foreach ($matches[1] ?? [] as $run) {
            $text[] = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $run);
        }
    }

    // The writer encodes to CP1252, so bring it back to UTF-8 for comparison.
    $joined = implode("\n", $text);
    $converted = @mb_convert_encoding($joined, 'UTF-8', 'CP1252');

    return $converted === false ? $joined : $converted;
}

/**
 * Count the tick strokes in a PDF. The report draws a ticked box as two crossing
 * lines, because the ballot-box characters do not exist in the standard PDF
 * fonts, so this is how a test can tell a ticked box from an empty one.
 */
function pdf_tick_strokes(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }

    $data = (string) file_get_contents($path);

    return preg_match_all('/m [\d.]+ [\d.]+ l S Q/', $data) ?: 0;
}


/**
 * Vector strokes drawn in one colour.
 *
 * The tick on a chosen box and the cross on one that was ruled out are drawn, not
 * typed, so they cannot be found in the PDF's text. They are told apart by colour:
 * a tick is the teal the boxes are drawn in, a cross is the muted grey. Filled and
 * stroked rectangles use a different operator, so box outlines are not counted.
 */
function pdf_stroke_count(string $path, string $colour): int
{
    if (!is_file($path)) {
        return 0;
    }

    $data = (string) file_get_contents($path);

    // A PDF names colours as RGB fractions, so the hex has to be converted the same
    // way PdfWriter::ink() does before it can be found in the content stream.
    $rgb = sprintf(
        '%.3F %.3F %.3F',
        hexdec(substr($colour, 0, 2)) / 255,
        hexdec(substr($colour, 2, 2)) / 255,
        hexdec(substr($colour, 4, 2)) / 255
    );

    // Line caps and joins are set on every stroke, so they sit between the width and the
    // first coordinate.
    $pattern = '/' . preg_quote($rgb, '/') . ' RG [\d.]+ w 1 J 1 j [\d.]+ [\d.]+ m [\d.]+ [\d.]+ l S Q/';

    return preg_match_all($pattern, $data) ?: 0;
}


/**
 * PDF text with all whitespace collapsed to single spaces.
 *
 * Long values wrap across lines in the PDF, so a value like a company name
 * arrives as two separate text runs. Use this when asserting that a *value*
 * appears; use pdf_text() when asserting on layout, such as a section heading.
 */
function pdf_text_flat(string $path): string
{
    return trim((string) preg_replace('/\s+/', ' ', pdf_text($path)));
}


/**
 * How many times a given image is drawn on each page of a PDF.
 *
 * The letterhead has to be on every page, and "the file contains the logo once" would pass
 * for a fifty-page report that prints it only on page one. So this resolves the XObject whose
 * pixel dimensions match the file on disk, finds the resource name it was published under,
 * and counts that name's draw operator per content stream.
 *
 * Matching on dimensions rather than on the resource name matters because photographs are
 * XObjects too, and their names are assigned in whatever order the writer happened to embed
 * them.
 *
 * @return array<int, int> One count per page, in page order.
 */
function pdf_image_draws_per_page(string $pdfPath, string $imagePath): array
{
    if (!is_file($pdfPath) || !is_file($imagePath)) {
        return [];
    }

    $size = @getimagesize($imagePath);

    if ($size === false) {
        return [];
    }

    [$width, $height] = $size;
    $data = (string) file_get_contents($pdfPath);

    // Which object numbers hold an image of exactly these dimensions?
    $objects = [];

    if (preg_match_all('/(\d+) 0 obj\s*<< \/Type \/XObject[^>]*?\/Width (\d+) \/Height (\d+)/s', $data, $found, PREG_SET_ORDER)) {
        foreach ($found as $match) {
            if ((int) $match[2] === $width && (int) $match[3] === $height) {
                $objects[] = (int) $match[1];
            }
        }
    }

    if ($objects === []) {
        return [];
    }

    // And what are they called in the shared resource dictionary?
    $names = [];

    if (preg_match_all('/\/(Im\d+) (\d+) 0 R/', $data, $found, PREG_SET_ORDER)) {
        foreach ($found as $match) {
            if (in_array((int) $match[2], $objects, true)) {
                $names[] = $match[1];
            }
        }
    }

    if ($names === []) {
        return [];
    }

    $counts = [];

    // Content streams are the ones carrying drawing operators; an image's own stream is
    // binary JPEG and has none.
    if (preg_match_all('/stream\r?\n(.*?)endstream/s', $data, $streams)) {
        foreach ($streams[1] as $stream) {
            if (!str_contains($stream, ' re ') && !str_contains($stream, 'BT ')) {
                continue;
            }

            $drawn = 0;

            foreach ($names as $name) {
                $drawn += preg_match_all('/\/' . preg_quote($name, '/') . ' Do/', $stream) ?: 0;
            }

            $counts[] = $drawn;
        }
    }

    return $counts;
}


/**
 * Diagonal strokes of one colour — which is what a cross is made of.
 *
 * Counting strokes by colour alone cannot tell "this option was crossed out" from "this is the
 * rule somebody signs on": both are drawn in the muted grey. The signature rules and the lines
 * of a writing box are horizontal, and a cross is two diagonals, so the slope is the thing to
 * look at.
 */
function pdf_diagonal_strokes(string $path, string $colour): int
{
    if (!is_file($path)) {
        return 0;
    }

    $rgb = sprintf(
        '%.3F %.3F %.3F',
        hexdec(substr($colour, 0, 2)) / 255,
        hexdec(substr($colour, 2, 2)) / 255,
        hexdec(substr($colour, 4, 2)) / 255
    );

    $pattern = '/' . preg_quote($rgb, '/')
        . ' RG [\d.]+ w 1 J 1 j ([\d.]+) ([\d.]+) m ([\d.]+) ([\d.]+) l S Q/';

    if (preg_match_all($pattern, (string) file_get_contents($path), $strokes, PREG_SET_ORDER) === false) {
        return 0;
    }

    $diagonal = 0;

    foreach ($strokes as $stroke) {
        $dx = abs((float) $stroke[3] - (float) $stroke[1]);
        $dy = abs((float) $stroke[4] - (float) $stroke[2]);

        // Both axes moving by something visible: neither a rule nor a border.
        if ($dx > 0.5 && $dy > 0.5) {
            $diagonal++;
        }
    }

    return $diagonal;
}


/**
 * Every string a PDF draws, with where it was drawn and in what.
 *
 * PdfWriter emits one `Tm ... Tj` per string with the font and size set immediately before it,
 * so position, size and text can all be read back without a PDF library. Coordinates are PDF
 * ones: y grows upward from the bottom of the page, and the y reported is the text's baseline.
 *
 * @return array<int, array{page:int, x:float, y:float, font:string, size:float, text:string}>
 */
function pdf_text_runs(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = (string) file_get_contents($path);
    $runs = [];

    if (preg_match_all('/stream\r?\n(.*?)endstream/s', $data, $streams) === false) {
        return [];
    }

    $page = 0;

    foreach ($streams[1] as $stream) {
        if (!str_contains($stream, 'Tj')) {
            continue;
        }

        $page++;

        $pattern = '/BT [\d.\s]+ rg \/(F\d) ([\d.]+) Tf 1 0 0 1 ([\d.-]+) ([\d.-]+) Tm \((.*?)\) Tj ET/';

        if (preg_match_all($pattern, $stream, $found, PREG_SET_ORDER) === false) {
            continue;
        }

        foreach ($found as $match) {
            $runs[] = [
                'page' => $page,
                'font' => $match[1],
                'size' => (float) $match[2],
                'x' => (float) $match[3],
                'y' => (float) $match[4],
                'text' => str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $match[5]),
            ];
        }
    }

    return $runs;
}

/**
 * Pairs of stacked lines whose baselines are closer together than the type is drawn.
 *
 * "Text tangled together" is not usually literal overlap — a PDF will happily place two
 * baselines exactly one em apart, which is tighter than any font is designed for, and the
 * descenders of one line then sit in the ascenders of the next. So this measures leading rather
 * than looking for collisions: consecutive baselines must be at least `$ratio` times the larger
 * of the two font sizes apart.
 *
 * Only lines that actually sit above one another are compared, using a horizontal overlap of
 * their approximate extents — two columns of a key/value block share a baseline and are not
 * stacked at all.
 *
 * @return array<int, string> One description per offending pair.
 */
function pdf_tight_leading(string $path, float $ratio = 1.15): array
{
    $runs = pdf_text_runs($path);
    $tight = [];

    // Grouped by page, then walked down the page.
    $byPage = [];

    foreach ($runs as $run) {
        $byPage[$run['page']][] = $run;
    }

    foreach ($byPage as $page => $lines) {
        // y grows upward, so descending y is down the page.
        usort($lines, static fn (array $a, array $b): int => $b['y'] <=> $a['y']);

        foreach ($lines as $i => $line) {
            foreach (array_slice($lines, $i + 1) as $next) {
                $gap = $line['y'] - $next['y'];

                if ($gap <= 0.01) {
                    continue; // Same baseline: side by side, not stacked.
                }

                $needed = max($line['size'], $next['size']) * $ratio;

                if ($gap >= $needed) {
                    break; // Sorted, so everything further down is further away.
                }

                // Horizontal extents, approximated from the string length. Helvetica averages
                // close to half its point size per character, which is accurate enough to tell
                // "these are stacked" from "these are in different columns".
                $aWidth = strlen($line['text']) * $line['size'] * 0.5;
                $bWidth = strlen($next['text']) * $next['size'] * 0.5;

                $overlap = min($line['x'] + $aWidth, $next['x'] + $bWidth) - max($line['x'], $next['x']);

                if ($overlap > 2.0) {
                    $tight[] = sprintf(
                        'page %d: "%s" and "%s" are %.1Fpt apart, %.1Fpt needed at %.1Fpt type',
                        $page,
                        mb_strimwidth($line['text'], 0, 34, '...'),
                        mb_strimwidth($next['text'], 0, 34, '...'),
                        $gap,
                        $needed,
                        max($line['size'], $next['size'])
                    );
                }
            }
        }
    }

    return $tight;
}


/**
 * The printed size of one QR module, in millimetres, for every code in a PDF.
 *
 * This is the property worth asserting about a printed QR, and it is not the same question as
 * "does a decoder read it". Whether a decoder succeeds depends on how many pixels it gets over
 * the code, which depends on the camera and how close it is held — a page rasterised whole at
 * 200dpi gives about 4 pixels per module and fails, while a phone framing the code itself gets
 * five times that and does not. What the writer controls is the physical size, so that is what
 * is checked.
 *
 * Below roughly 0.5mm a code stops surviving the photocopier these pages go through. The size
 * is recovered from the drawn geometry: the white quiet-zone square is the whole footprint, and
 * the shortest rectangle in the fill is one module tall.
 *
 * @return array<int, float> One millimetre-per-module figure per code found, in document order.
 */
function pdf_qr_module_sizes(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = (string) file_get_contents($path);
    $pattern = '/q 1\.000 1\.000 1\.000 rg ([\d.]+) [\d.]+ ([\d.]+) [\d.]+ re f Q\s*'
        . 'q 0 0 0 rg ((?:[\d.]+ [\d.]+ [\d.]+ [\d.]+ re )+)f Q/';

    if (preg_match_all($pattern, $data, $found, PREG_SET_ORDER) === false) {
        return [];
    }

    $sizes = [];

    foreach ($found as $match) {
        $box = (float) $match[2];

        if (preg_match_all('/[\d.]+ [\d.]+ [\d.]+ ([\d.]+) re /', $match[3], $rects) === false) {
            continue;
        }

        $heights = array_map('floatval', $rects[1]);
        $heights = array_filter($heights, static fn (float $h): bool => $h > 0.0);

        if ($heights === [] || $box <= 0.0) {
            continue;
        }

        // Runs are merged horizontally but never vertically, so every rectangle is exactly one
        // module tall and the minimum is the module size.
        $sizes[] = min($heights) * 25.4 / 72;
    }

    return $sizes;
}
