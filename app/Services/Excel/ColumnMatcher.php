<?php

declare(strict_types=1);

namespace App\Services\Excel;

/**
 * Automatic Excel column matching.
 *
 * Real sheets never use the same captions twice ("A/C No", "Loan A/c No.",
 * "ACCOUNT NUMBER"), so matching runs in decreasing order of confidence:
 *
 *   100  exact match on the normalised system key or a known alias
 *    85  alias contained in the header (or vice versa)
 *    60+ token overlap / similarity above the threshold
 *
 * Anything below `CERTAIN_THRESHOLD` is still suggested but flagged so the
 * Admin/Supervisor is asked to confirm it on the mapping screen.
 */
final class ColumnMatcher
{
    public const CERTAIN_THRESHOLD = 85;
    private const SUGGEST_THRESHOLD = 55;

    /**
     * @param array<int, string> $headers column index => header caption
     * @return array<string, array{
     *   column: int|null,
     *   header: string|null,
     *   confidence: int,
     *   certain: bool
     * }> keyed by system field
     */
    public static function match(array $headers): array
    {
        $fields = SystemFields::all();
        $normalisedHeaders = [];

        foreach ($headers as $index => $header) {
            $normalisedHeaders[$index] = self::normalise($header);
        }

        // Score every (field, column) pair, then resolve greedily by best score
        // so two fields cannot claim the same column.
        $scores = [];

        foreach ($fields as $key => $field) {
            foreach ($normalisedHeaders as $index => $normalised) {
                if ($normalised === '') {
                    continue;
                }

                $score = self::score($key, $field['aliases'], $normalised);

                if ($score >= self::SUGGEST_THRESHOLD) {
                    $scores[] = ['field' => $key, 'column' => $index, 'score' => $score];
                }
            }
        }

        usort($scores, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $result = [];
        $usedColumns = [];

        foreach ($fields as $key => $field) {
            $result[$key] = ['column' => null, 'header' => null, 'confidence' => 0, 'certain' => false];
        }

        foreach ($scores as $candidate) {
            $field = $candidate['field'];
            $column = $candidate['column'];

            if ($result[$field]['column'] !== null || isset($usedColumns[$column])) {
                continue;
            }

            $result[$field] = [
                'column' => $column,
                'header' => $headers[$column] ?? null,
                'confidence' => $candidate['score'],
                'certain' => $candidate['score'] >= self::CERTAIN_THRESHOLD,
            ];

            $usedColumns[$column] = true;
        }

        return $result;
    }

    /**
     * Confidence that a header refers to the given system field, 0–100.
     *
     * @param array<int, string> $aliases
     */
    public static function score(string $key, array $aliases, string $normalisedHeader): int
    {
        $candidates = array_map([self::class, 'normalise'], array_merge([$key, SystemFields::label($key)], $aliases));
        $candidates = array_values(array_unique(array_filter($candidates)));

        $best = 0;

        foreach ($candidates as $candidate) {
            if ($candidate === $normalisedHeader) {
                return 100;
            }

            // "outstandingamt" vs "outstanding": containment is a strong signal,
            // but only when the shorter string is meaningful on its own.
            if (strlen($candidate) >= 3) {
                if (str_contains($normalisedHeader, $candidate) || str_contains($candidate, $normalisedHeader)) {
                    $lengthRatio = min(strlen($candidate), strlen($normalisedHeader))
                        / max(strlen($candidate), strlen($normalisedHeader));
                    $best = max($best, (int) round(70 + (15 * $lengthRatio)));
                    continue;
                }
            }

            $similarity = 0;
            similar_text($candidate, $normalisedHeader, $similarity);

            if ($similarity > $best) {
                $best = (int) round($similarity);
            }
        }

        // Guard against short accidental matches like "os" vs "no".
        if ($best < 100 && strlen($normalisedHeader) <= 2) {
            $best = min($best, 40);
        }

        return min(100, $best);
    }

    /**
     * "Father's Name " => "fathersname", "A/C No." => "acno"
     */
    public static function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['&', '/'], ['and', ''], $value);
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        // Collapse the most common noise words so "outstandingamount" and
        // "outstandingamt" score as the same thing.
        $value = str_replace(['amount', 'amt', 'number', 'numbr'], ['amt', 'amt', 'no', 'no'], $value);

        return $value;
    }

    /**
     * Fields that were matched but need a human to confirm.
     *
     * @param array<string, array{column:int|null, confidence:int, certain:bool}> $matches
     * @return array<int, string>
     */
    public static function uncertainFields(array $matches): array
    {
        $uncertain = [];

        foreach ($matches as $key => $match) {
            if ($match['column'] !== null && !$match['certain']) {
                $uncertain[] = $key;
            }
        }

        return $uncertain;
    }

    /**
     * Required fields that no column could be found for.
     *
     * @param array<string, array{column:int|null}> $matches
     * @return array<int, string>
     */
    public static function missingRequired(array $matches): array
    {
        $missing = [];

        foreach (SystemFields::requiredKeys() as $key) {
            if (($matches[$key]['column'] ?? null) === null) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Convert a match result into the persisted mapping shape
     * (system field => header caption), which is what mapping templates store so
     * they survive column re-ordering between uploads.
     *
     * @param array<string, array{header:string|null}> $matches
     * @return array<string, string>
     */
    public static function toMapping(array $matches): array
    {
        $mapping = [];

        foreach ($matches as $key => $match) {
            if (($match['header'] ?? null) !== null) {
                $mapping[$key] = (string) $match['header'];
            }
        }

        return $mapping;
    }

    /**
     * Resolve a stored mapping (field => header caption) against the headers of
     * the file being imported now.
     *
     * @param array<string, string> $mapping
     * @param array<int, string>    $headers
     * @return array<string, int> system field => column index
     */
    public static function resolveMapping(array $mapping, array $headers): array
    {
        $byNormalised = [];

        foreach ($headers as $index => $header) {
            $byNormalised[self::normalise($header)] = $index;
        }

        $resolved = [];

        foreach ($mapping as $field => $header) {
            if ($header === '' || $header === null) {
                continue;
            }

            // Templates may also store a raw column index.
            if (is_numeric($header) && isset($headers[(int) $header])) {
                $resolved[$field] = (int) $header;
                continue;
            }

            $normalised = self::normalise((string) $header);

            if (isset($byNormalised[$normalised])) {
                $resolved[$field] = $byNormalised[$normalised];
            }
        }

        return $resolved;
    }
}
