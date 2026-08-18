<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function html(string $content, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo $content;
    }

    public static function json(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function redirect(string $to, int $status = 302): void
    {
        $url = str_starts_with($to, 'http://') || str_starts_with($to, 'https://') ? $to : url($to);

        if (!headers_sent()) {
            http_response_code($status);
            header('Location: ' . $url);
        }

        echo '<!doctype html><meta http-equiv="refresh" content="0;url=' . e($url) . '">';
    }

    public static function back(string $fallback = '/'): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        self::redirect($referer !== '' ? $referer : $fallback);
    }

    public static function download(string $path, string $filename, string $contentType = 'application/octet-stream'): void
    {
        if (!is_file($path)) {
            throw new HttpException(404, 'The requested file no longer exists.');
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
            header('Content-Length: ' . (string) filesize($path));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }

        readfile($path);
    }

    public static function inline(string $path, string $filename, string $contentType): void
    {
        if (!is_file($path)) {
            throw new HttpException(404, 'The requested file no longer exists.');
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
            header('Content-Length: ' . (string) filesize($path));
        }

        readfile($path);
    }

    public static function noContent(int $status = 204): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }
    }
}
