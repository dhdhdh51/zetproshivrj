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

    $pattern = '/' . preg_quote($rgb, '/') . ' RG [\d.]+ w [\d.]+ [\d.]+ m [\d.]+ [\d.]+ l S Q/';

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
