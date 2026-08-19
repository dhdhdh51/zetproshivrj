<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Translations for the web panel.
 *
 * Field staff and branch clerks read Hindi far more comfortably than English, so
 * every screen they use has to be available in both. The strings live in plain
 * PHP arrays under resources/lang/, keyed with dots ('auth.sign_in'), because a
 * shared host with no Composer and no gettext extension still has to boot.
 *
 * The chosen language is remembered in the session and in a long-lived cookie
 * rather than on the users row: the deployed database already holds live recovery
 * data and there is no incremental migration runner, so a schema change would
 * mean hand-run SQL on a production server. A cookie also matches what people
 * expect from a language toggle — it sticks on the browser they are using.
 *
 * Anything missing from a translation falls back to English rather than
 * disappearing, so a half-finished translation degrades into a readable screen
 * instead of a blank one.
 */
final class Lang
{
    /** Locale code => the language's own name, for the switcher. */
    public const SUPPORTED = [
        'en' => 'English',
        'hi' => 'हिन्दी',
    ];

    public const FALLBACK = 'en';

    private const COOKIE = 'lrms_locale';
    private const COOKIE_LIFETIME = 31536000; // one year

    private static string $locale = self::FALLBACK;
    private static bool $booted = false;

    /** @var array<string, array<string, string>> locale => lines */
    private static array $loaded = [];

    /**
     * Decide which language this request is in.
     *
     * Session first (the switcher just set it), then the cookie (this browser's
     * standing choice), then the installation default an admin picked in
     * Settings, and English if nothing else says otherwise.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        $candidates = [
            Session::get('locale'),
            $_COOKIE[self::COOKIE] ?? null,
            self::defaultLocale(),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && self::isSupported($candidate)) {
                self::$locale = $candidate;

                return;
            }
        }

        self::$locale = self::FALLBACK;
    }

    /**
     * The installation-wide default, from Settings. Wrapped because this runs on
     * every request that has no session or cookie, including before the database
     * is reachable during installation.
     */
    private static function defaultLocale(): ?string
    {
        try {
            $value = Settings::get('default_locale');

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function isSupported(string $locale): bool
    {
        return array_key_exists($locale, self::SUPPORTED);
    }

    /** The language's own name, e.g. 'हिन्दी' — never an English exonym. */
    public static function name(?string $locale = null): string
    {
        $locale ??= self::$locale;

        return self::SUPPORTED[$locale] ?? $locale;
    }

    /**
     * Switch language for this session and remember it on this browser.
     *
     * Returns false for an unknown code so a hand-crafted URL cannot push the
     * panel into a locale that has no strings.
     */
    public static function set(string $locale): bool
    {
        if (!self::isSupported($locale)) {
            return false;
        }

        self::$locale = $locale;
        self::$booted = true;
        Session::put('locale', $locale);

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE, $locale, [
                'expires' => time() + self::COOKIE_LIFETIME,
                'path' => '/',
                'secure' => self::isHttps(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        return true;
    }

    private static function isHttps(): bool
    {
        if (str_starts_with((string) Config::get('app.url', ''), 'https://')) {
            return true;
        }

        return ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * Translate a key.
     *
     * `:placeholder` tokens are replaced, and the replacements are *not* escaped
     * here — views escape on output, so escaping here would double-encode.
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        self::boot();

        $locale ??= self::$locale;
        $line = self::lines($locale)[$key] ?? null;

        if ($line === null && $locale !== self::FALLBACK) {
            // An untranslated string reads better in English than not at all.
            $line = self::lines(self::FALLBACK)[$key] ?? null;
        }

        // Still nothing: show the key so the gap is obvious in review rather than
        // rendering an empty label that looks like a broken page.
        $line ??= $key;

        foreach ($replace as $token => $value) {
            $line = str_replace(':' . $token, (string) $value, $line);
        }

        return $line;
    }

    /** True when the key exists in this locale or in English. */
    public static function has(string $key, ?string $locale = null): bool
    {
        self::boot();
        $locale ??= self::$locale;

        return isset(self::lines($locale)[$key]) || isset(self::lines(self::FALLBACK)[$key]);
    }

    /**
     * @return array<string, string>
     */
    private static function lines(string $locale): array
    {
        if (isset(self::$loaded[$locale])) {
            return self::$loaded[$locale];
        }

        $file = BASE_PATH . '/resources/lang/' . $locale . '.php';
        $lines = [];

        if (is_file($file)) {
            $loaded = require $file;

            if (is_array($loaded)) {
                $lines = $loaded;
            }
        }

        return self::$loaded[$locale] = $lines;
    }

    /**
     * Every key that is present in English, for the coverage check in the test
     * suite: a translation file that has drifted should be visible, not silently
     * falling back on half its labels.
     *
     * @return list<string>
     */
    public static function keys(string $locale = self::FALLBACK): array
    {
        return array_keys(self::lines($locale));
    }

    /** Test seam: forget the resolved locale and cached files. */
    public static function reset(): void
    {
        self::$booted = false;
        self::$locale = self::FALLBACK;
        self::$loaded = [];
    }
}
