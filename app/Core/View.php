<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function exists(string $view): bool
    {
        return is_file(self::path($view));
    }

    public static function render(string $view, array $data = [], ?string $layout = null): string
    {
        $content = self::renderFile(self::path($view), array_merge(self::$shared, $data));

        if ($layout === null) {
            return $content;
        }

        return self::renderFile(
            self::path($layout),
            array_merge(self::$shared, $data, ['content' => $content])
        );
    }

    public static function partial(string $view, array $data = []): string
    {
        return self::renderFile(self::path($view), array_merge(self::$shared, $data));
    }

    private static function path(string $view): string
    {
        return base_path('resources/views/' . str_replace('.', '/', trim($view, '/')) . '.php');
    }

    private static function renderFile(string $file, array $data): string
    {
        if (!is_file($file)) {
            throw new HttpException(500, 'View not found: ' . basename($file));
        }

        $level = ob_get_level();
        ob_start();

        try {
            extract($data, EXTR_SKIP);
            require $file;
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
