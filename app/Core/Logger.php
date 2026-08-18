<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $dir = base_path('storage/logs');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            "[%s] %s: %s%s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            PHP_EOL
        );

        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
