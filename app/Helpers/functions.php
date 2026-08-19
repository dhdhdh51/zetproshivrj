<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Lang;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

/* -------------------------------------------------------------------------- */
/* Paths, config, urls                                                        */
/* -------------------------------------------------------------------------- */

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
        return Settings::string('site_name', 'LRMS');
    }
}

if (!function_exists('org_name')) {
    function org_name(): string
    {
        return Settings::string('organisation_name', 'Loan Recovery Management System');
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

if (!function_exists('today')) {
    function today(): string
    {
        return date('Y-m-d');
    }
}

if (!function_exists('str_random')) {
    function str_random(int $length = 32): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }
}

if (!function_exists('uuid4')) {
    function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('view_partial')) {
    function view_partial(string $view, array $data = []): string
    {
        return View::partial($view, $data);
    }
}

/* -------------------------------------------------------------------------- */
/* Formatting                                                                 */
/* -------------------------------------------------------------------------- */

if (!function_exists('money')) {
    /** Indian digit grouping (1,23,45,678.00) which is what bank staff expect. */
    function money(float|int|string|null $amount, bool $symbol = true): string
    {
        $amount = (float) ($amount ?? 0);
        $negative = $amount < 0;
        $amount = abs($amount);

        $decimals = number_format($amount - floor($amount), 2, '.', '');
        $whole = (string) (int) floor($amount);
        $decimalPart = substr($decimals, 1);

        if (strlen($whole) > 3) {
            $last3 = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
            $whole = $rest . ',' . $last3;
        }

        return ($negative ? '-' : '') . ($symbol ? '₹' : '') . $whole . $decimalPart;
    }
}

if (!function_exists('money_short')) {
    /** Compact amounts for dashboard tiles: ₹1.24 Cr / ₹4.50 L / ₹8,200 */
    function money_short(float|int|string|null $amount): string
    {
        $amount = (float) ($amount ?? 0);

        if (abs($amount) >= 10000000) {
            return '₹' . number_format($amount / 10000000, 2) . ' Cr';
        }

        if (abs($amount) >= 100000) {
            return '₹' . number_format($amount / 100000, 2) . ' L';
        }

        return money($amount);
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

if (!function_exists('format_datetime')) {
    function format_datetime(?string $date): string
    {
        return format_date($date, 'd M Y, h:i A');
    }
}

if (!function_exists('format_time')) {
    function format_time(?string $date): string
    {
        return format_date($date, 'h:i A');
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return 'never';
        }

        $timestamp = strtotime($datetime);

        if ($timestamp === false) {
            return 'never';
        }

        $diff = time() - $timestamp;

        if ($diff < 0) {
            return 'just now';
        }

        return match (true) {
            $diff < 60 => 'just now',
            $diff < 3600 => (int) ($diff / 60) . ' min ago',
            $diff < 86400 => (int) ($diff / 3600) . ' hr ago',
            $diff < 2592000 => (int) ($diff / 86400) . ' d ago',
            default => date('d M Y', $timestamp),
        };
    }
}

if (!function_exists('minutes_to_hours')) {
    function minutes_to_hours(int $minutes): string
    {
        if ($minutes <= 0) {
            return '—';
        }

        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}

if (!function_exists('percent_of')) {
    function percent_of(int|float $achieved, int|float $target): float
    {
        if ($target <= 0) {
            return $achieved > 0 ? 100.0 : 0.0;
        }

        return round(((float) $achieved / (float) $target) * 100, 1);
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

if (!function_exists('initials')) {
    function initials(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'LR';
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        return strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1)) ?: 'LR';
    }
}

if (!function_exists('mask_mobile')) {
    /** Customer contact numbers are masked wherever a full number is not needed. */
    function mask_mobile(?string $mobile): string
    {
        $mobile = preg_replace('/\D/', '', (string) $mobile) ?? '';

        if ($mobile === '') {
            return '—';
        }

        if (strlen($mobile) <= 4) {
            return $mobile;
        }

        return substr($mobile, 0, 2) . str_repeat('•', max(0, strlen($mobile) - 6)) . substr($mobile, -4);
    }
}

if (!function_exists('coordinates')) {
    function coordinates(?string $lat, ?string $lng, int $precision = 5): string
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return '—';
        }

        return number_format((float) $lat, $precision) . ', ' . number_format((float) $lng, $precision);
    }
}

if (!function_exists('map_link')) {
    function map_link(?string $lat, ?string $lng): ?string
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return null;
        }

        return sprintf('https://www.google.com/maps/search/?api=1&query=%s,%s', (float) $lat, (float) $lng);
    }
}

/* -------------------------------------------------------------------------- */
/* Domain labels                                                              */
/* -------------------------------------------------------------------------- */

if (!function_exists('enum_label')) {
    function enum_label(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return ucwords(str_replace('_', ' ', $value));
    }
}

if (!function_exists('visit_status_label')) {
    function visit_status_label(?string $status): string
    {
        return match ($status) {
            'customer_met' => 'Customer met',
            'family_met' => 'Family met',
            'phone_contact' => 'Phone contact only',
            'house_locked' => 'House locked',
            'not_available' => 'Customer not available',
            'address_not_found' => 'Address not found',
            'deceased' => 'Deceased',
            'shifted' => 'Shifted',
            'refused' => 'Refused to pay',
            default => enum_label($status),
        };
    }
}

if (!function_exists('inspection_results')) {
    /** @return array<string, string> */
    function inspection_results(): array
    {
        return [
            'work_verified' => 'Work Verified',
            'partially_verified' => 'Partially Verified',
            'not_satisfactory' => 'Work Not Satisfactory',
            'customer_not_found' => 'Customer Not Found',
            'bc_not_present' => 'BC Supervisor Not Present',
            'visit_not_genuine' => 'Visit Not Genuine',
            'incorrect_information' => 'Incorrect Information',
            'gps_issue' => 'GPS Issue',
            'photo_issue' => 'Photo Issue',
            'other' => 'Other',
        ];
    }
}

if (!function_exists('inspection_result_label')) {
    function inspection_result_label(?string $result): string
    {
        return inspection_results()[$result] ?? enum_label($result);
    }
}

if (!function_exists('inspection_result_is_negative')) {
    /** Negative outcomes must always carry remarks. */
    function inspection_result_is_negative(?string $result): bool
    {
        return $result !== null && $result !== 'work_verified';
    }
}

if (!function_exists('photo_types')) {
    /** @return array<string, string> */
    function photo_types(): array
    {
        return [
            'customer' => 'Customer',
            'house' => 'House',
            'shop' => 'Shop',
            'land' => 'Land',
            'document' => 'Document',
            'other' => 'Other',
        ];
    }
}

if (!function_exists('inspection_photo_types')) {
    /** @return array<string, string> */
    function inspection_photo_types(): array
    {
        return [
            'bc_supervisor' => 'BC Supervisor',
            'customer' => 'Customer',
            'location' => 'Location',
            'document' => 'Document',
            'selfie' => 'Inspector selfie',
            'other' => 'Other',
        ];
    }
}

if (!function_exists('payment_modes')) {
    /** @return array<int, string> */
    function payment_modes(): array
    {
        return Settings::list('payment_modes', ['Cash', 'Bank Transfer', 'UPI', 'Cheque', 'Other']);
    }
}

if (!function_exists('badge')) {
    /**
     * Map a domain status onto a badge CSS class.
     */
    function badge(?string $status): string
    {
        $good = ['active', 'approved', 'submitted', 'kept', 'work_verified', 'recovered', 'present',
                 'verified', 'completed', 'renewed', 'paid', 'done', 'high'];
        $warn = ['pending', 'in_progress', 'partly_recovered', 'partially_verified', 'partially_kept',
                 'late_pending', 'under_review', 'documents_awaited', 'half_day', 'proposed', 'medium',
                 'draft', 'queued', 'partly_paid'];
        $bad = ['inactive', 'suspended', 'rejected', 'broken', 'not_satisfactory', 'visit_not_genuine',
                'incorrect_information', 'gps_issue', 'photo_issue', 'bc_not_present', 'customer_not_found',
                'absent', 'failed', 'blocked', 'not_eligible', 'not_traceable', 'written_off', 'nil', 'locked'];

        $status = (string) $status;

        return match (true) {
            in_array($status, $good, true) => 'badge badge-success',
            in_array($status, $warn, true) => 'badge badge-warning',
            in_array($status, $bad, true) => 'badge badge-danger',
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

        return $path === $prefix || str_starts_with($path, $prefix . '/') ? 'active' : '';
    }
}

if (!function_exists('query_string')) {
    /**
     * Rebuild the current query string with overrides — used by table filters,
     * sorting and pagination links.
     */
    function query_string(array $overrides = [], array $except = []): string
    {
        $params = array_merge($_GET, $overrides);

        foreach ($except as $key) {
            unset($params[$key]);
        }

        $params = array_filter(
            $params,
            static fn ($value): bool => $value !== '' && $value !== null && !is_array($value)
        );

        return $params === [] ? '' : '?' . http_build_query($params);
    }
}

if (!function_exists('sort_link_direction')) {
    function sort_link_direction(string $column, string $currentColumn, string $currentDirection): string
    {
        if ($column !== $currentColumn) {
            return 'asc';
        }

        return strtolower($currentDirection) === 'asc' ? 'desc' : 'asc';
    }
}

/* -------------------------------------------------------------------------- */
/* Icons                                                                      */
/* -------------------------------------------------------------------------- */

if (!function_exists('icon')) {
    /**
     * Inline SVG icon set (Feather style). Keeps views free of external icon
     * fonts, which also lets the CSP stay strict.
     */
    function icon(string $name, string $classes = '', int $size = 18): string
    {
        static $paths = null;

        if ($paths === null) {
            $paths = [
                'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
                'bank' => '<path d="M3 10l9-6 9 6"/><path d="M5 10v9h14v-9"/><path d="M9 19v-5h6v5"/>',
                'building' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1"/><path d="M10 21v-3h4v3"/>',
                'users' => '<path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 20v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13A4 4 0 0 1 16 11"/>',
                'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
                'user-check' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M16 11l2 2 4-4"/>',
                'file-text' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
                'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3h6v1"/><path d="M9 10h6M9 14h6M9 18h3"/>',
                'search-check' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/><path d="M8.5 11l2 2 3.5-3.5"/>',
                'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
                'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
                'rupee' => '<path d="M7 4h10M7 9h10M14 4c2.5 0 4 1.5 4 3.5S16.5 11 14 11H7l7 9"/>',
                'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>',
                'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
                'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
                'map-pin' => '<path d="M12 22s7-6.1 7-12A7 7 0 0 0 5 10c0 5.9 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
                'camera' => '<path d="M3 8h3l1.5-2h9L18 8h3a1 1 0 0 1 1 1v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.5"/>',
                'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8"/><path d="M10.3 21a2 2 0 0 0 3.4 0"/>',
                'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
                'activity' => '<path d="M22 12h-4l-3 8-4-16-3 8H2"/>',
                'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
                'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6 1.65 1.65 0 0 0 10 3.09V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.14.37.43.68.79.86.24.12.5.19.77.21H21a2 2 0 1 1 0 4h-.09c-.65.02-1.24.4-1.51 1z"/>',
                'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
                'plus' => '<path d="M12 5v14M5 12h14"/>',
                'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
                'filter' => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
                'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/>',
                'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
                'check' => '<path d="M20 6L9 17l-5-5"/>',
                'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
                'x' => '<path d="M18 6L6 18M6 6l12 12"/>',
                'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>',
                'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
                'alert-triangle' => '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
                'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
                'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
                'chevron-left' => '<path d="M15 18l-6-6 6-6"/>',
                'chevron-right' => '<path d="M9 18l6-6-6-6"/>',
                'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
                'arrow-right' => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
                'arrow-left' => '<path d="M19 12H5"/><path d="M11 18l-6-6 6-6"/>',
                'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
                'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/>',
                'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.8 1.8"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.8-1.8"/>',
                'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
                'unlock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.5-2"/>',
                'smartphone' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
                'wifi-off' => '<path d="M2 4l18 18"/><path d="M8.5 16.5a5 5 0 0 1 7 0"/><path d="M5 12.9a10 10 0 0 1 3-2"/><path d="M19 12.9a10 10 0 0 0-6-2.8"/><path d="M12 20h.01"/>',
                'layers' => '<path d="M12 2l9 5-9 5-9-5z"/><path d="M3 12l9 5 9-5"/><path d="M3 17l9 5 9-5"/>',
                'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
                'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.8.6 2.6a2 2 0 0 1-.4 2.1L8 9.7a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.8.3 1.7.5 2.6.6a2 2 0 0 1 1.7 2z"/>',
                'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
                'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
                'sliders' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><path d="M1 14h6M9 8h6M17 16h6"/>',
                'history' => '<path d="M3 12a9 9 0 1 0 9-9 9 9 0 0 0-7.5 4"/><path d="M3 3v5h5"/><path d="M12 8v5l4 2"/>',
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


if (!function_exists('audit_value')) {
    /**
     * Render an audit-log value that may be a scalar, a bool or a nested array.
     */
    function audit_value(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return (string) (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—');
        }

        return (string) $value;
    }
}


/* -------------------------------------------------------------------------- */
/* Translation                                                                */
/* -------------------------------------------------------------------------- */

if (!function_exists('__')) {
    /**
     * Translate a key into the current language.
     *
     * Returns the raw string — views still escape it with e(), exactly as they do
     * for any other text, so a translation containing an apostrophe or an
     * ampersand cannot break the markup.
     */
    function __(string $key, array $replace = []): string
    {
        return Lang::get($key, $replace);
    }
}

if (!function_exists('et')) {
    /**
     * Translate and escape in one step, for the common case in a view:
     * `<?= et('nav.dashboard') ?>`.
     */
    function et(string $key, array $replace = []): string
    {
        return e(Lang::get($key, $replace));
    }
}

if (!function_exists('current_locale')) {
    function current_locale(): string
    {
        Lang::boot();

        return Lang::locale();
    }
}

if (!function_exists('locale_names')) {
    /**
     * Locale code => the language's own name, for rendering the switcher.
     *
     * @return array<string, string>
     */
    function locale_names(): array
    {
        return Lang::SUPPORTED;
    }
}
