<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return BASE_PATH . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path === '' ? '' : '/' . ltrim($path, '/')));
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return Settings::get($key, $default);
    }
}

if (!function_exists('app_name')) {
    function app_name(): string
    {
        return Settings::string('site_name', 'DocuPilot AI');
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_url')) {
    function base_url(): string
    {
        static $base = null;

        if ($base === null) {
            $base = (new App\Core\Request())->baseUrl();
        }

        return $base;
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        if ($path === '') {
            return base_url();
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return base_url() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return url('assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('redirect_to')) {
    function redirect_to(string $path): void
    {
        App\Core\Response::redirect($path);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('auth_id')) {
    function auth_id(): ?int
    {
        return Auth::id();
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return Session::old($key, $default);
    }
}

if (!function_exists('error_for')) {
    function error_for(string $field): ?string
    {
        $errors = Session::errors();

        if (!isset($errors[$field])) {
            return null;
        }

        return is_array($errors[$field]) ? (string) reset($errors[$field]) : (string) $errors[$field];
    }
}

if (!function_exists('has_error')) {
    function has_error(string $field): bool
    {
        return error_for($field) !== null;
    }
}

if (!function_exists('now')) {
    function now(string $format = 'Y-m-d H:i:s'): string
    {
        return date($format);
    }
}

if (!function_exists('str_random')) {
    function str_random(int $length = 32): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }
}

if (!function_exists('currencies')) {
    /** @return array<string, array{symbol:string, name:string}> */
    function currencies(): array
    {
        return [
            'INR' => ['symbol' => '₹', 'name' => 'Indian Rupee'],
            'USD' => ['symbol' => '$', 'name' => 'US Dollar'],
            'EUR' => ['symbol' => '€', 'name' => 'Euro'],
            'GBP' => ['symbol' => '£', 'name' => 'British Pound'],
            'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar'],
            'CAD' => ['symbol' => 'C$', 'name' => 'Canadian Dollar'],
            'AED' => ['symbol' => 'AED ', 'name' => 'UAE Dirham'],
            'SGD' => ['symbol' => 'S$', 'name' => 'Singapore Dollar'],
        ];
    }
}

if (!function_exists('currency_symbol')) {
    function currency_symbol(string $code): string
    {
        return currencies()[strtoupper($code)]['symbol'] ?? (strtoupper($code) . ' ');
    }
}

if (!function_exists('money')) {
    function money(float|int|string $amount, string $currency = 'INR'): string
    {
        return currency_symbol($currency) . number_format((float) $amount, 2);
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date, string $format = 'd M Y'): string
    {
        if ($date === null || $date === '' || str_starts_with($date, '0000')) {
            return '—';
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? '—' : date($format, $timestamp);
    }
}

if (!function_exists('document_types')) {
    /** @return array<string, array{label:string, prefix:string, icon:string}> */
    function document_types(): array
    {
        return [
            'quotation' => ['label' => 'Quotation', 'prefix' => 'QT', 'icon' => 'file-text'],
            'invoice' => ['label' => 'Invoice', 'prefix' => 'INV', 'icon' => 'receipt'],
            'proposal' => ['label' => 'Proposal', 'prefix' => 'PROP', 'icon' => 'presentation'],
            'estimate' => ['label' => 'Estimate', 'prefix' => 'EST', 'icon' => 'calculator'],
            'purchase_order' => ['label' => 'Purchase Order', 'prefix' => 'PO', 'icon' => 'shopping-cart'],
        ];
    }
}

if (!function_exists('document_type_label')) {
    function document_type_label(string $type): string
    {
        return document_types()[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type));
    }
}

if (!function_exists('status_class')) {
    function status_class(string $status): string
    {
        return match ($status) {
            'final' => 'badge badge-info',
            'sent' => 'badge badge-success',
            'paid' => 'badge badge-success',
            'failed' => 'badge badge-danger',
            'pending' => 'badge badge-warning',
            default => 'badge badge-muted',
        };
    }
}

if (!function_exists('nav_active')) {
    function nav_active(string $prefix): string
    {
        $path = (new App\Core\Request())->path();

        if ($prefix === '/') {
            return $path === '/' ? 'active' : '';
        }

        return str_starts_with($path, $prefix) ? 'active' : '';
    }
}

if (!function_exists('view_partial')) {
    function view_partial(string $view, array $data = []): string
    {
        return View::partial($view, $data);
    }
}

if (!function_exists('str_excerpt')) {
    function str_excerpt(?string $text, int $length = 120): string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text) ?? '');

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 1) . '…';
    }
}

if (!function_exists('percent_of')) {
    function percent_of(int|float $used, int|float $limit): float
    {
        if ($limit <= 0) {
            return $used > 0 ? 100.0 : 0.0;
        }

        return min(100.0, round(((float) $used / (float) $limit) * 100, 1));
    }
}

if (!function_exists('initials')) {
    function initials(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'DP';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $second = mb_substr($parts[1] ?? '', 0, 1);

        return strtoupper($first . $second) ?: 'DP';
    }
}

if (!function_exists('is_https')) {
    function is_https(): bool
    {
        return str_starts_with(base_url(), 'https://');
    }
}


if (!function_exists('icon')) {
    /**
     * Inline SVG icon set (Feather-style, 1.75 stroke). Keeps views free of
     * external icon fonts and works inside PDFs and emails.
     */
    function icon(string $name, string $classes = '', int $size = 18): string
    {
        static $paths = null;

        if ($paths === null) {
            $paths = [
                'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
                'file-text' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
                'receipt' => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 7h6M9 11h6M9 15h3"/>',
                'presentation' => '<path d="M3 4h18"/><path d="M4 4v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4"/><path d="M12 16v5"/><path d="M9 21h6"/>',
                'calculator' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 11h.01M12 11h.01M16 11h.01M8 15h.01M12 15h.01M16 15h.01M8 19h8"/>',
                'shopping-cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.4 11.2a2 2 0 0 0 2 1.6h8.2a2 2 0 0 0 2-1.6L21 7H6"/>',
                'users' => '<path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 20v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13A4 4 0 0 1 16 11"/>',
                'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
                'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><path d="M2 13h20"/>',
                'credit-card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
                'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6 1.65 1.65 0 0 0 10 3.09V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.14.37.43.68.79.86.24.12.5.19.77.21H21a2 2 0 1 1 0 4h-.09c-.65.02-1.24.4-1.51 1z"/>',
                'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
                'plus' => '<path d="M12 5v14M5 12h14"/>',
                'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
                'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
                'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
                'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
                'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.8 1.8"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.8-1.8"/>',
                'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2.5 6.5l9.5 6 9.5-6"/>',
                'send' => '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>',
                'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
                'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/>',
                'copy' => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
                'check' => '<path d="M20 6L9 17l-5-5"/>',
                'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
                'x' => '<path d="M18 6L6 18M6 6l12 12"/>',
                'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
                'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
                'sparkles' => '<path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6z"/><path d="M18.5 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/>',
                'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
                'chevron-left' => '<path d="M15 18l-6-6 6-6"/>',
                'chevron-right' => '<path d="M9 18l6-6-6-6"/>',
                'arrow-right' => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
                'arrow-left' => '<path d="M19 12H5"/><path d="M11 18l-6-6 6-6"/>',
                'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
                'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
                'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
                'zap' => '<path d="M13 2L4 14h6l-1 8 9-12h-6z"/>',
                'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
                'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/>',
                'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14L21 3"/>',
                'star' => '<path d="M12 2l3 6.5 7 1-5 4.9 1.2 7-6.2-3.4L5.8 21.4 7 14.4 2 9.5l7-1z"/>',
                'palette' => '<circle cx="12" cy="12" r="9"/><circle cx="9" cy="9" r="1.2"/><circle cx="15" cy="9" r="1.2"/><circle cx="9.5" cy="15" r="1.2"/>',
                'building' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1"/><path d="M10 21v-3h4v3"/>',
                'bank' => '<path d="M3 10l9-6 9 6"/><path d="M5 10v9h14v-9"/><path d="M9 19v-5h6v5"/>',
                'activity' => '<path d="M22 12h-4l-3 8-4-16-3 8H2"/>',
                'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
                'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18z"/>',
                'layers' => '<path d="M12 2l9 5-9 5-9-5z"/><path d="M3 12l9 5 9-5"/><path d="M3 17l9 5 9-5"/>',
            ];
        }

        $path = $paths[$name] ?? $paths['info'];

        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            e($classes),
            $size,
            $size,
            $path
        );
    }
}

if (!function_exists('site_logo_url')) {
    function site_logo_url(): ?string
    {
        $logo = App\Core\Settings::string('site_logo');

        return $logo === '' ? null : url('media/logo/' . $logo);
    }
}
