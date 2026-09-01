<?php

declare(strict_types=1);

/**
 * SQL file helpers, shared by the installers.
 *
 * Used by database/migrate.php (command line) and public/install.php (browser),
 * so both split a .sql file exactly the same way.
 */

if (!function_exists('lrms_split_sql')) {
    /**
     * Split a .sql file into individual statements.
     *
     * The schema contains no stored routines, so a semicolon at the end of a line
     * (outside a quoted string) reliably terminates a statement.
     *
     * @return array<int, string>
     */
    function lrms_split_sql(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            // Skip -- line comments when not inside a string.
            if (!$inString && $char === '-' && $next === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($inString) {
                if ($char === '\\') {
                    $current .= $char . $next;
                    $i++;
                    continue;
                }

                if ($char === $stringChar) {
                    $inString = false;
                }

                $current .= $char;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
