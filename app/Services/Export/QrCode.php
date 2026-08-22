<?php

declare(strict_types=1);

namespace App\Services\Export;

use RuntimeException;

/**
 * A QR code, as a grid of black and white squares.
 *
 * WHY THIS IS HAND-WRITTEN
 *
 * This project has no Composer: the panel has to boot on shared hosting where
 * `composer install` was never run, so a library is not an option. `gd` can draw the result
 * but cannot encode one, and PHP has nothing built in. So the encoder is here, and it is
 * deliberately narrow: byte mode, error correction level M, versions 1 to 10. That covers a
 * URL of up to 216 characters, which is far more than the verification links these documents
 * carry, and every table it needs is small enough to read.
 *
 * Level M is the middle of the four: about 15% of the code can be dirty, torn or
 * photocopied badly and it still reads. These pages get folded into files and photocopied,
 * so that margin is the point.
 *
 * It produces a matrix. Drawing is PdfWriter's job.
 *
 * VERIFIED AGAINST A KNOWN-GOOD IMPLEMENTATION
 *
 * Every table here is from the specification, but a table typed by hand is a table with a
 * typo in it. `tests/test-qr.php` encodes a set of payloads and compares the matrix, module
 * for module, with the output of Python's `qrcode` library for the same version and mask —
 * a scanner would not be gentler than that comparison.
 */
final class QrCode
{
    /** Error correction level M: [ec codewords per block, [ [blocks, data codewords], ... ] ] */
    private const ECC_M = [
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

    /** Centres of the alignment patterns, per version. */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** The 18-bit version block, needed from version 7 up. */
    private const VERSION_BITS = [
        7 => 0x07C94,
        8 => 0x085BC,
        9 => 0x09A99,
        10 => 0x0A4D3,
    ];

    /** The 15-bit format block for level M, one per mask. */
    private const FORMAT_BITS_M = [
        0 => 0x5412,
        1 => 0x5125,
        2 => 0x5E7C,
        3 => 0x5B4B,
        4 => 0x45F9,
        5 => 0x40CE,
        6 => 0x4F97,
        7 => 0x4AA0,
    ];

    /** @var array<int, int> */
    private static array $expTable = [];

    /** @var array<int, int> */
    private static array $logTable = [];

    /**
     * The finished grid: `$matrix[$row][$col]`, true where the module is dark.
     *
     * @var array<int, array<int, bool>>
     */
    private array $matrix = [];

    private int $size = 0;

    private int $version = 0;

    private int $mask = 0;

    private function __construct()
    {
    }

    /**
     * Encode a string. Anything up to 216 bytes; longer than that and there is nothing
     * sensible to put on a printed page anyway.
     */
    public static function encode(string $data): self
    {
        if ($data === '') {
            throw new RuntimeException('A QR code needs something to encode.');
        }

        $qr = new self();
        $qr->version = self::versionFor(strlen($data));
        $qr->size = 17 + (4 * $qr->version);

        $codewords = $qr->codewords($data);
        $qr->buildMatrix($codewords);

        return $qr;
    }

    /** @return array<int, array<int, bool>> */
    public function matrix(): array
    {
        return $this->matrix;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function mask(): int
    {
        return $this->mask;
    }

    private static function versionFor(int $length): int
    {
        foreach (self::ECC_M as $version => [, $groups]) {
            $capacity = 0;

            foreach ($groups as [$blocks, $dataCodewords]) {
                $capacity += $blocks * $dataCodewords;
            }

            // 4 bits of mode, 8 or 16 of length, then the bytes themselves.
            $overhead = $version >= 10 ? 20 : 12;

            if (($length * 8) + $overhead <= $capacity * 8) {
                return $version;
            }
        }

        throw new RuntimeException('That is too much data for a printed QR code (limit 216 bytes).');
    }

    /**
     * Data bits, padded, split into blocks, error-corrected and interleaved.
     *
     * @return array<int, int>
     */
    private function codewords(string $data): array
    {
        [$ecPerBlock, $groups] = self::ECC_M[$this->version];

        $totalData = 0;

        foreach ($groups as [$blocks, $dataCodewords]) {
            $totalData += $blocks * $dataCodewords;
        }

        // Byte mode is 0100. The length field is 8 bits below version 10, 16 from there up.
        $bits = '0100';
        $bits .= str_pad(decbin(strlen($data)), $this->version >= 10 ? 16 : 8, '0', STR_PAD_LEFT);

        foreach (str_split($data) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        // Terminator, then up to a byte boundary.
        $capacityBits = $totalData * 8;
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));

        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        $dataBytes = [];

        foreach (str_split($bits, 8) as $byte) {
            $dataBytes[] = bindec($byte);
        }

        // The two pad bytes alternate for the rest of the capacity.
        $pad = [0xEC, 0x11];
        $index = 0;

        while (count($dataBytes) < $totalData) {
            $dataBytes[] = $pad[$index % 2];
            $index++;
        }

        // Split into blocks, and give each its own error correction.
        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;

        foreach ($groups as [$blocks, $dataCodewords]) {
            for ($i = 0; $i < $blocks; $i++) {
                $block = array_slice($dataBytes, $offset, $dataCodewords);
                $offset += $dataCodewords;
                $dataBlocks[] = $block;
                $ecBlocks[] = self::reedSolomon($block, $ecPerBlock);
            }
        }

        // Interleave: the first codeword of every block, then the second, and so on. A tear
        // across the page then damages a little of each block rather than destroying one.
        $out = [];
        $longest = max(array_map('count', $dataBlocks));

        for ($i = 0; $i < $longest; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Reed-Solomon over GF(256)                                          */
    /* ------------------------------------------------------------------ */

    private static function initialiseTables(): void
    {
        if (self::$expTable !== []) {
            return;
        }

        $x = 1;

        for ($i = 0; $i < 256; $i++) {
            self::$expTable[$i] = $x;
            self::$logTable[$x] = $i;
            $x <<= 1;

            if ($x & 0x100) {
                $x ^= 0x11D; // The QR field's primitive polynomial.
            }
        }
    }

    private static function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        self::initialiseTables();

        return self::$expTable[(self::$logTable[$a] + self::$logTable[$b]) % 255];
    }

    /**
     * @param array<int, int> $data
     * @return array<int, int>
     */
    private static function reedSolomon(array $data, int $ecCount): array
    {
        self::initialiseTables();

        // The generator polynomial for this many EC codewords.
        $generator = [1];

        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);

            foreach ($generator as $index => $coefficient) {
                $next[$index] ^= $coefficient;
                $next[$index + 1] ^= self::multiply($coefficient, self::$expTable[$i]);
            }

            $generator = $next;
        }

        $remainder = array_merge($data, array_fill(0, $ecCount, 0));

        for ($i = 0; $i < count($data); $i++) {
            $factor = $remainder[$i];

            if ($factor === 0) {
                continue;
            }

            foreach ($generator as $index => $coefficient) {
                $remainder[$i + $index] ^= self::multiply($coefficient, $factor);
            }
        }

        return array_slice($remainder, count($data), $ecCount);
    }

    /* ------------------------------------------------------------------ */
    /* Placing the modules                                                */
    /* ------------------------------------------------------------------ */

    /** @param array<int, int> $codewords */
    private function buildMatrix(array $codewords): void
    {
        $reserved = [];

        for ($row = 0; $row < $this->size; $row++) {
            for ($col = 0; $col < $this->size; $col++) {
                $this->matrix[$row][$col] = false;
                $reserved[$row][$col] = false;
            }
        }

        $this->placeFinders($reserved);
        $this->placeTiming($reserved);
        $this->placeAlignment($reserved);
        $this->reserveFormatAreas($reserved);
        $this->placeData($codewords, $reserved);

        // Every mask is tried and the least ugly wins, which is what the specification asks
        // for: a code full of long runs or false finder patterns is a code a scanner fumbles.
        $best = null;
        $bestPenalty = PHP_INT_MAX;
        $bestMask = 0;
        $unmasked = $this->matrix;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->matrix = $unmasked;
            $this->applyMask($mask, $reserved);
            $this->placeFormat($mask);
            $this->placeVersion();

            $penalty = $this->penalty();

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $this->matrix;
                $bestMask = $mask;
            }
        }

        $this->matrix = $best ?? $unmasked;
        $this->mask = $bestMask;
    }

    /** @param array<int, array<int, bool>> $reserved */
    private function placeFinders(array &$reserved): void
    {
        foreach ([[0, 0], [$this->size - 7, 0], [0, $this->size - 7]] as [$originRow, $originCol]) {
            for ($row = -1; $row <= 7; $row++) {
                for ($col = -1; $col <= 7; $col++) {
                    $r = $originRow + $row;
                    $c = $originCol + $col;

                    if ($r < 0 || $c < 0 || $r >= $this->size || $c >= $this->size) {
                        continue;
                    }

                    $onRing = ($row === 0 || $row === 6) && $col >= 0 && $col <= 6;
                    $onSide = ($col === 0 || $col === 6) && $row >= 0 && $row <= 6;
                    $inCore = $row >= 2 && $row <= 4 && $col >= 2 && $col <= 4;

                    $this->matrix[$r][$c] = $onRing || $onSide || $inCore;
                    $reserved[$r][$c] = true;
                }
            }
        }
    }

    /** @param array<int, array<int, bool>> $reserved */
    private function placeTiming(array &$reserved): void
    {
        for ($i = 8; $i < $this->size - 8; $i++) {
            $dark = $i % 2 === 0;

            $this->matrix[6][$i] = $dark;
            $reserved[6][$i] = true;

            $this->matrix[$i][6] = $dark;
            $reserved[$i][6] = true;
        }
    }

    /** @param array<int, array<int, bool>> $reserved */
    private function placeAlignment(array &$reserved): void
    {
        $centres = self::ALIGNMENT[$this->version];

        foreach ($centres as $centreRow) {
            foreach ($centres as $centreCol) {
                // The three corners already carry finder patterns.
                if (($centreRow === 6 && $centreCol === 6)
                    || ($centreRow === 6 && $centreCol === $this->size - 7)
                    || ($centreRow === $this->size - 7 && $centreCol === 6)
                ) {
                    continue;
                }

                for ($row = -2; $row <= 2; $row++) {
                    for ($col = -2; $col <= 2; $col++) {
                        $r = $centreRow + $row;
                        $c = $centreCol + $col;

                        $ring = max(abs($row), abs($col));
                        $this->matrix[$r][$c] = $ring !== 1;
                        $reserved[$r][$c] = true;
                    }
                }
            }
        }
    }

    /** @param array<int, array<int, bool>> $reserved */
    private function reserveFormatAreas(array &$reserved): void
    {
        for ($i = 0; $i < 9; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }

        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$this->size - 1 - $i] = true;
            $reserved[$this->size - 1 - $i][8] = true;
        }

        // The module that is always dark.
        $this->matrix[$this->size - 8][8] = true;
        $reserved[$this->size - 8][8] = true;

        if ($this->version >= 7) {
            for ($row = 0; $row < 6; $row++) {
                for ($col = 0; $col < 3; $col++) {
                    $reserved[$row][$this->size - 11 + $col] = true;
                    $reserved[$this->size - 11 + $col][$row] = true;
                }
            }
        }
    }

    /**
     * @param array<int, int> $codewords
     * @param array<int, array<int, bool>> $reserved
     */
    private function placeData(array $codewords, array $reserved): void
    {
        $bits = '';

        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $length = strlen($bits);
        $index = 0;
        $upward = true;

        // Two columns at a time, right to left, snaking up and down.
        for ($col = $this->size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // The vertical timing line is not a data column.
            }

            for ($step = 0; $step < $this->size; $step++) {
                $row = $upward ? $this->size - 1 - $step : $step;

                foreach ([$col, $col - 1] as $c) {
                    if ($reserved[$row][$c]) {
                        continue;
                    }

                    $this->matrix[$row][$c] = $index < $length && $bits[$index] === '1';
                    $index++;
                }
            }

            $upward = !$upward;
        }
    }

    /** @param array<int, array<int, bool>> $reserved */
    private function applyMask(int $mask, array $reserved): void
    {
        for ($row = 0; $row < $this->size; $row++) {
            for ($col = 0; $col < $this->size; $col++) {
                if ($reserved[$row][$col]) {
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
                    $this->matrix[$row][$col] = !$this->matrix[$row][$col];
                }
            }
        }
    }

    private function placeFormat(int $mask): void
    {
        $bits = self::FORMAT_BITS_M[$mask];

        for ($i = 0; $i < 15; $i++) {
            $dark = (($bits >> $i) & 1) === 1;

            // The first copy wraps the top-left finder: bits 0-7 climb column 8 from the top,
            // then bits 8-14 turn the corner and run leftwards along row 8. The two dog-legs
            // at bits 6 and 8 are the timing line at index 6 being skipped in each direction.
            if ($i < 6) {
                $this->matrix[$i][8] = $dark;
            } elseif ($i === 6) {
                $this->matrix[7][8] = $dark;
            } elseif ($i === 7) {
                $this->matrix[8][8] = $dark;
            } elseif ($i === 8) {
                $this->matrix[8][7] = $dark;
            } else {
                $this->matrix[8][14 - $i] = $dark;
            }

            // The second copy is split between the other two finders: bits 0-7 run leftwards
            // along row 8 from the right edge, bits 8-14 run down column 8 to the bottom edge.
            // Getting these two the wrong way round is invisible to the eye and fatal to a
            // scanner, so note that this copy runs row-first where the one above runs
            // column-first.
            if ($i < 8) {
                $this->matrix[8][$this->size - 1 - $i] = $dark;
            } else {
                $this->matrix[$this->size - 15 + $i][8] = $dark;
            }
        }

        $this->matrix[$this->size - 8][8] = true;
    }

    private function placeVersion(): void
    {
        if ($this->version < 7) {
            return;
        }

        $bits = self::VERSION_BITS[$this->version];

        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $row = (int) ($i / 3);
            $col = $i % 3;

            $this->matrix[$row][$this->size - 11 + $col] = $dark;
            $this->matrix[$this->size - 11 + $col][$row] = $dark;
        }
    }

    /**
     * How awkward this arrangement is to scan. Lower is better; the four rules are the
     * specification's.
     */
    private function penalty(): int
    {
        $score = 0;

        // 1. Runs of five or more of the same colour.
        for ($row = 0; $row < $this->size; $row++) {
            $score += $this->runPenalty($this->matrix[$row]);
        }

        for ($col = 0; $col < $this->size; $col++) {
            $column = [];

            for ($row = 0; $row < $this->size; $row++) {
                $column[] = $this->matrix[$row][$col];
            }

            $score += $this->runPenalty($column);
        }

        // 2. Blocks of the same colour, 2x2 at a time.
        for ($row = 0; $row < $this->size - 1; $row++) {
            for ($col = 0; $col < $this->size - 1; $col++) {
                $value = $this->matrix[$row][$col];

                if ($value === $this->matrix[$row][$col + 1]
                    && $value === $this->matrix[$row + 1][$col]
                    && $value === $this->matrix[$row + 1][$col + 1]
                ) {
                    $score += 3;
                }
            }
        }

        // 3. Anything that looks like a finder pattern.
        $needle = [true, false, true, true, true, false, true, false, false, false, false];
        $reversed = array_reverse($needle);

        for ($row = 0; $row < $this->size; $row++) {
            for ($col = 0; $col <= $this->size - 11; $col++) {
                $slice = array_slice($this->matrix[$row], $col, 11);

                if ($slice === $needle || $slice === $reversed) {
                    $score += 40;
                }
            }
        }

        for ($col = 0; $col < $this->size; $col++) {
            for ($row = 0; $row <= $this->size - 11; $row++) {
                $slice = [];

                for ($i = 0; $i < 11; $i++) {
                    $slice[] = $this->matrix[$row + $i][$col];
                }

                if ($slice === $needle || $slice === $reversed) {
                    $score += 40;
                }
            }
        }

        // 4. Too much or too little black overall.
        $dark = 0;

        for ($row = 0; $row < $this->size; $row++) {
            foreach ($this->matrix[$row] as $value) {
                if ($value) {
                    $dark++;
                }
            }
        }

        $percent = ($dark * 100) / ($this->size * $this->size);
        $score += ((int) (abs($percent - 50) / 5)) * 10;

        return $score;
    }

    /** @param array<int, bool> $line */
    private function runPenalty(array $line): int
    {
        $score = 0;
        $run = 1;

        for ($i = 1; $i < count($line); $i++) {
            if ($line[$i] === $line[$i - 1]) {
                $run++;

                continue;
            }

            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }

            $run = 1;
        }

        if ($run >= 5) {
            $score += 3 + ($run - 5);
        }

        return $score;
    }
}
