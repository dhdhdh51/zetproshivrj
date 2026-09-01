<?php

declare(strict_types=1);

/**
 * QR code tests — the encoder behind the verification block on every exported PDF.
 *
 *   php tests/test-qr.php
 *
 * No database needed: this is pure computation.
 *
 * The encoder is hand-written (see App\Services\Export\QrCode for why there is no library
 * to lean on), and every table in it was typed from the specification. So the risk here is
 * not a design mistake, it is a typo — one wrong entry in an alignment table or one
 * transposed pair of format bits, which produces a code that looks perfectly convincing on
 * the page and cannot be scanned at all. Nothing about the picture tells you which.
 *
 * Three things are checked, in increasing order of how much they would catch:
 *
 *   1. The matrix matches a capture from Python's `qrcode` library, module for module.
 *   2. The function patterns are where the specification puts them, for every version.
 *   3. The payload can be read back out by a reader that shares no code with the writer.
 *
 * (3) is the one that matters most, because it is what a scanner does. It walks the finished
 * matrix from the outside — undoing the mask, following the snake, de-interleaving the
 * blocks — and recovers the bytes. If a bit went in the wrong place, the string comes back
 * as rubbish, whichever payload it was.
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Services\Export\PdfWriter;
use App\Services\Export\QrCode;

/* ------------------------------------------------------------------------ */
/* 1. Against an independent implementation                                  */
/* ------------------------------------------------------------------------ */

section('Matches a known-good implementation, module for module');

/** @var array<int, array{payload:string, version:int, mask:int, rows:array<int, string>}> $reference */
$reference = require __DIR__ . '/fixtures/qr-reference.php';

ok($reference !== [], 'The reference fixtures are present');

foreach ($reference as $case) {
    $qr = QrCode::encode($case['payload']);

    $label = strlen($case['payload']) > 30
        ? substr($case['payload'], 0, 27) . '...'
        : $case['payload'];

    equals($case['version'], $qr->version(), sprintf('v%d chosen for %s', $case['version'], $label));
    equals($case['mask'], $qr->mask(), sprintf('mask %d chosen for %s', $case['mask'], $label));

    $rows = [];

    foreach ($qr->matrix() as $row) {
        $rows[] = implode('', array_map(static fn (bool $dark): string => $dark ? '1' : '0', $row));
    }

    if (equals(count($case['rows']), count($rows), sprintf('v%d is %d rows', $case['version'], count($case['rows'])))) {
        $differing = 0;
        $firstDifference = '';

        foreach ($case['rows'] as $index => $expected) {
            if ($expected === $rows[$index]) {
                continue;
            }

            for ($col = 0, $width = strlen($expected); $col < $width; $col++) {
                if ($expected[$col] === $rows[$index][$col]) {
                    continue;
                }

                $differing++;

                if ($firstDifference === '') {
                    $firstDifference = sprintf('row %d, column %d', $index, $col);
                }
            }
        }

        ok(
            $differing === 0,
            $differing === 0
                ? sprintf('Every one of the %d modules agrees (%s)', count($rows) ** 2, $label)
                : sprintf('%d modules differ, first at %s (%s)', $differing, $firstDifference, $label)
        );
    }
}

/* ------------------------------------------------------------------------ */
/* 2. Function patterns                                                     */
/* ------------------------------------------------------------------------ */

section('The function patterns are where the specification puts them');

/** How many alignment patterns each version carries, from the centres in the standard. */
$alignmentCounts = [1 => 0, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 6, 8 => 6, 9 => 6, 10 => 6];

for ($version = 1; $version <= 10; $version++) {
    // The shortest payload that cannot fit in the version below, so encode() is forced to
    // pick this one. Capacities at level M, in bytes.
    $capacity = [1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213];
    $qr = QrCode::encode(str_repeat('A', $capacity[$version]));

    equals($version, $qr->version(), sprintf('%d bytes needs v%d', $capacity[$version], $version));

    $matrix = $qr->matrix();
    $size = $qr->size();

    equals(17 + (4 * $version), $size, sprintf('v%d is %d modules across', $version, 17 + (4 * $version)));

    // Three finders, 7x7, one per corner but not the fourth.
    $finders = [[0, 0], [0, $size - 7], [$size - 7, 0]];
    $finderOk = true;

    foreach ($finders as [$top, $left]) {
        for ($row = 0; $row < 7; $row++) {
            for ($col = 0; $col < 7; $col++) {
                // Dark unless it is the one-module white ring: ring 1 of the 7x7.
                $ring = max(abs($row - 3), abs($col - 3));

                if ($matrix[$top + $row][$left + $col] !== ($ring !== 2)) {
                    $finderOk = false;
                }
            }
        }
    }

    ok($finderOk, sprintf('v%d: all three finder patterns are correct', $version));

    // The timing lines alternate, starting and ending dark, and connect the finders.
    $timingOk = true;

    for ($i = 8; $i < $size - 8; $i++) {
        $expected = $i % 2 === 0;

        if ($matrix[6][$i] !== $expected || $matrix[$i][6] !== $expected) {
            $timingOk = false;
        }
    }

    ok($timingOk, sprintf('v%d: both timing lines alternate', $version));

    // The module below the bottom-left finder is dark in every code ever made.
    ok($matrix[$size - 8][8], sprintf('v%d: the always-dark module is dark', $version));

    // Alignment patterns: 5x5, dark centre, white ring, dark border.
    $found = 0;
    $centres = [];

    for ($row = 6; $row < $size - 6; $row++) {
        for ($col = 6; $col < $size - 6; $col++) {
            if (!$matrix[$row][$col]) {
                continue;
            }

            // A 5x5 alignment pattern read from its centre: dark, white ring, dark border.
            if ($row < 2 || $col < 2 || $row + 2 >= $size || $col + 2 >= $size) {
                continue;
            }

            $matches = true;

            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $ring = max(abs($r), abs($c));

                    if ($matrix[$row + $r][$col + $c] !== ($ring !== 1)) {
                        $matches = false;

                        break 2;
                    }
                }
            }

            if ($matches) {
                $found++;
                $centres[] = $row . ',' . $col;
            }
        }
    }

    // The search can also land on a finder's own 5x5 core, so only assert that every
    // alignment pattern the version should have was found.
    ok(
        $found >= $alignmentCounts[$version],
        sprintf('v%d: found %d alignment pattern(s), expected at least %d', $version, $found, $alignmentCounts[$version])
    );
}

/* ------------------------------------------------------------------------ */
/* 3. Read it back out                                                      */
/* ------------------------------------------------------------------------ */

section('A reader that shares no code with the writer recovers the payload');

/**
 * Read the payload back out of a finished matrix, the way a scanner does.
 *
 * Undoes the mask, walks the two-column snake to recover the interleaved codeword stream,
 * de-interleaves it back into blocks, and parses the byte-mode header. Error correction is
 * not applied — there is nothing to correct in a matrix that was just generated, and
 * skipping it keeps this function short enough to trust.
 *
 * The block structure has to be known to de-interleave, so those numbers are repeated here
 * rather than read from the encoder. That is the point: if the encoder's table is wrong,
 * this disagrees with it.
 */
function qr_read_back(QrCode $qr): string
{
    // [ec codewords per block, [[blocks, data codewords], ...]] at level M.
    $structure = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    $version = $qr->version();
    $size = $qr->size();
    $matrix = $qr->matrix();
    [, $groups] = $structure[$version];

    // Which modules carry data: everything that is not a function pattern. Worked out from
    // the geometry, independently of how the encoder marked them.
    $alignment = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ][$version];

    $function = [];

    for ($row = 0; $row < $size; $row++) {
        for ($col = 0; $col < $size; $col++) {
            $function[$row][$col] = false;
        }
    }

    $mark = static function (int $top, int $left, int $height, int $width) use (&$function, $size): void {
        for ($row = $top; $row < $top + $height; $row++) {
            for ($col = $left; $col < $left + $width; $col++) {
                if ($row >= 0 && $col >= 0 && $row < $size && $col < $size) {
                    $function[$row][$col] = true;
                }
            }
        }
    };

    // Finders with their separators, and the format areas beside them.
    $mark(0, 0, 9, 9);
    $mark(0, $size - 8, 9, 8);
    $mark($size - 8, 0, 8, 9);

    // Timing lines.
    $mark(6, 0, 1, $size);
    $mark(0, 6, $size, 1);

    // Alignment patterns, minus the three that would sit on a finder.
    foreach ($alignment as $centreRow) {
        foreach ($alignment as $centreCol) {
            $onFinder = ($centreRow === 6 && $centreCol === 6)
                || ($centreRow === 6 && $centreCol === end($alignment))
                || ($centreRow === end($alignment) && $centreCol === 6);

            if (!$onFinder) {
                $mark($centreRow - 2, $centreCol - 2, 5, 5);
            }
        }
    }

    // The version block, from version 7.
    if ($version >= 7) {
        $mark(0, $size - 11, 6, 3);
        $mark($size - 11, 0, 3, 6);
    }

    // Undo the mask over the data modules only.
    $mask = $qr->mask();

    for ($row = 0; $row < $size; $row++) {
        for ($col = 0; $col < $size; $col++) {
            if ($function[$row][$col]) {
                continue;
            }

            $flip = match ($mask) {
                0 => ($row + $col) % 2 === 0,
                1 => $row % 2 === 0,
                2 => $col % 3 === 0,
                3 => ($row + $col) % 3 === 0,
                4 => ((int) ($row / 2) + (int) ($col / 3)) % 2 === 0,
                5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
                6 => ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0,
                default => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            };

            if ($flip) {
                $matrix[$row][$col] = !$matrix[$row][$col];
            }
        }
    }

    // Follow the snake: two columns at a time from the right, alternating direction.
    $bits = '';
    $upward = true;

    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--;
        }

        for ($step = 0; $step < $size; $step++) {
            $row = $upward ? $size - 1 - $step : $step;

            foreach ([$col, $col - 1] as $c) {
                if (!$function[$row][$c]) {
                    $bits .= $matrix[$row][$c] ? '1' : '0';
                }
            }
        }

        $upward = !$upward;
    }

    $stream = [];

    foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
        $stream[] = bindec($byte);
    }

    // De-interleave. Data codewords come first, one from each block in turn.
    $lengths = [];

    foreach ($groups as [$blocks, $dataCodewords]) {
        for ($i = 0; $i < $blocks; $i++) {
            $lengths[] = $dataCodewords;
        }
    }

    $blocks = array_fill(0, count($lengths), []);
    $index = 0;
    $longest = max($lengths);

    for ($position = 0; $position < $longest; $position++) {
        foreach ($lengths as $block => $length) {
            if ($position < $length) {
                $blocks[$block][] = $stream[$index];
                $index++;
            }
        }
    }

    $data = [];

    foreach ($blocks as $block) {
        foreach ($block as $codeword) {
            $data[] = $codeword;
        }
    }

    // Parse the byte-mode header: 4 bits of mode, then the length.
    $dataBits = '';

    foreach ($data as $codeword) {
        $dataBits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
    }

    $mode = bindec(substr($dataBits, 0, 4));

    if ($mode !== 0b0100) {
        return '[mode ' . $mode . ', expected byte mode]';
    }

    $countBits = $version >= 10 ? 16 : 8;
    $length = bindec(substr($dataBits, 4, $countBits));
    $payload = '';

    for ($i = 0; $i < $length; $i++) {
        $byte = substr($dataBits, 4 + $countBits + ($i * 8), 8);

        if (strlen($byte) < 8) {
            return '[ran off the end after ' . $i . ' of ' . $length . ' bytes]';
        }

        $payload .= chr(bindec($byte));
    }

    return $payload;
}

$payloads = [
    'LRMS',
    'https://lrms.example.in/admin/visits/7',
    'https://lrms.example.in/admin/inspections/1204',
    // A report link with its filters, which is the longest thing these PDFs carry.
    'https://lrms.example.in/admin/reports/sss_target?from=2026-08-01&to=2026-08-31&branch_id=14',
    // Every byte value that WinAnsi cannot print, to prove the encoder is byte-clean and is
    // not quietly transliterating the way the PDF text layer has to.
    implode('', array_map('chr', range(1, 60))),
    // The exact capacity of each version, so the boundary is read back too.
    str_repeat('A', 14),
    str_repeat('B', 15),
    str_repeat('C', 62),
    str_repeat('D', 63),
    str_repeat('E', 152),
    str_repeat('F', 213),
];

foreach ($payloads as $payload) {
    $qr = QrCode::encode($payload);
    $recovered = qr_read_back($qr);

    $label = strlen($payload) > 34
        ? sprintf('%d bytes starting "%s"', strlen($payload), substr($payload, 0, 12))
        : sprintf('"%s"', addcslashes($payload, "\0..\37"));

    ok(
        $recovered === $payload,
        $recovered === $payload
            ? sprintf('v%d mask %d reads back as %s', $qr->version(), $qr->mask(), $label)
            : sprintf(
                'v%d mask %d should read back as %s, got "%s"',
                $qr->version(),
                $qr->mask(),
                $label,
                addcslashes(substr($recovered, 0, 40), "\0..\37")
            )
    );
}

/* ------------------------------------------------------------------------ */
/* 4. Printed size                                                          */
/* ------------------------------------------------------------------------ */

section('A longer link is printed larger, not denser');

/*
 * The square used to be a fixed 62 points whatever went into it. That is fine for a link to one
 * record — 33 modules, about half a millimetre each — but a report link carries the filters it
 * was printed with, and those push the module count up while the square stayed the same size.
 *
 * Measured: a plain date range gave 0.45mm modules and a filter set at the cap 0.36mm. Against a
 * page rasterised at 200dpi, roughly what a phone resolves across a whole A4 sheet, the 0.45mm
 * code would not decode while the 0.53mm one did. These pages are photocopied before anyone
 * scans them, so that margin is the whole point.
 *
 * The module size is what is held constant now; the square grows instead. Asserting the printed
 * size rather than a decode keeps the test about something the writer controls — whether a given
 * decoder succeeds depends on how close the camera is held.
 */
$floor = 0.5;
$payloads = [
    'a record link' => 'https://server.d2squarecreditsolutions.in/r/inspection/1',
    'a report with no filters' => 'https://server.d2squarecreditsolutions.in/r/report/customer_visit',
    'a report with a date range' => 'https://server.d2squarecreditsolutions.in/r/report/branch_performance'
        . '?from=2026-08-01&to=2026-08-31',
    'a report with filters at the cap' => 'https://server.d2squarecreditsolutions.in/r/report/branch_performance?'
        . str_repeat('a', 119),
    // Exactly the 213 bytes the encoder accepts and not a byte more. The filler is sized to the
    // host, which is why it changed when the server was renamed: a longer address leaves less
    // room for the filters, and this fixture is here to sit on that boundary.
    'the longest link that can be encoded' => 'https://server.d2squarecreditsolutions.in/r/report/x?'
        . str_repeat('b', 160),
];

foreach ($payloads as $description => $payload) {
    $page = new PdfWriter('portrait');
    $page->header('Module size', 'Test');
    $page->verification($payload, ['reference line']);

    $file = storage_path('generated/test-qr-size-' . getmypid() . '.pdf');
    $page->save($file);

    $sizes = pdf_qr_module_sizes($file);
    @unlink($file);

    if (!ok($sizes !== [], 'A code is drawn for ' . $description)) {
        continue;
    }

    $modules = QrCode::encode($payload)->size();

    ok(
        $sizes[0] >= $floor,
        sprintf(
            '%s prints at %.3Fmm per module across %d modules (floor %.2Fmm)',
            ucfirst($description),
            $sizes[0],
            $modules,
            $floor
        )
    );
}

/*
 * And a link too long to encode must not take the export down with it. The site address, the
 * report slug and the filters are all assembled outside this class, so a payload the encoder
 * refuses is reachable — and a member of staff pressing "export" should get their figures
 * without a QR rather than an error page.
 */
$refused = new PdfWriter('portrait');
$refused->header('Overlong', 'Test');
$refused->paragraph('The link below cannot be encoded.');

$survived = true;

try {
    $refused->verification(
        'https://server.d2squarecreditsolutions.in/r/report/x?' . str_repeat('z', 300),
        ['Reference: still printed']
    );
} catch (Throwable $e) {
    $survived = false;
}

ok($survived, 'A link too long to encode is dropped rather than thrown');

$refusedFile = storage_path('generated/test-qr-refused-' . getmypid() . '.pdf');
$refused->save($refusedFile);

ok(is_file($refusedFile) && filesize($refusedFile) > 500, 'And the document is still written');
equals([], pdf_qr_module_sizes($refusedFile), 'With no code on it');
ok(
    str_contains(pdf_text_flat($refusedFile), 'Reference: still printed'),
    'The reference beside it is printed anyway, so the record is still findable'
);

@unlink($refusedFile);

/* ------------------------------------------------------------------------ */
/* 5. Limits                                                                */
/* ------------------------------------------------------------------------ */

section('Limits are refused rather than silently truncated');

throws(
    static fn () => QrCode::encode(''),
    'An empty payload is refused',
    'needs something to encode'
);

throws(
    static fn () => QrCode::encode(str_repeat('x', 214)),
    'One byte past version 10 is refused',
    'too much data'
);

ok(QrCode::encode(str_repeat('x', 213))->version() === 10, '213 bytes still encodes, at version 10');

/* ------------------------------------------------------------------------ */
/* 6. On the page                                                           */
/* ------------------------------------------------------------------------ */

section('The verification block reaches the PDF');

$pdf = new PdfWriter('portrait');
$pdf->header('QR harness', 'Test');
$pdf->paragraph('A page with a verification block on it.');
$pdf->verification('https://lrms.example.in/admin/visits/7', [
    'Visit reference: 6f1c2a44-0000-4000-8000-000000000001',
    'Account 9876543210   •   Test Borrower',
]);

$path = storage_path('generated/test-qr-' . getmypid() . '.pdf');
$pdf->save($path);

$text = pdf_text_flat($path);

ok(str_contains($text, 'Scan to open this record'), 'The caption is printed');
ok(str_contains($text, 'A panel login is required.'), 'And what the code leads to');
ok(
    str_contains($text, '6f1c2a44-0000-4000-8000-000000000001'),
    'The reference is printed too, for whoever has no phone'
);

$raw = (string) file_get_contents($path);

// The modules are drawn as one black fill containing many rectangles. Runs of dark modules
// in a row are merged, so the count is well below one per module, but a code of this size
// cannot be drawn in a handful.
if (preg_match('/q 0 0 0 rg ((?:[\d.]+ [\d.]+ [\d.]+ [\d.]+ re )+)f Q/', $raw, $match) === 1) {
    $rectangles = preg_match_all('/re/', $match[1]);

    ok($rectangles > 40, sprintf('The code is drawn as %d merged rectangles in one fill', $rectangles));
    ok(
        substr_count($raw, 'q 0 0 0 rg') === 1,
        'In a single fill, not one operation per module'
    );
} else {
    ok(false, 'The code is drawn as a black fill path');
}

// The quiet zone: a white rectangle the full size of the block, under the modules.
ok(
    substr_count($raw, '1.000 1.000 1.000 rg') >= 1,
    'A white quiet zone is drawn behind it, not left to the tinted panel'
);

@unlink($path);

exit(TestRunner::summary());
