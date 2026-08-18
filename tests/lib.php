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
