<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Switches the panel between English and Hindi.
 *
 * Deliberately open to signed-out visitors: a clerk who reads Hindi has to be
 * able to change the language *before* reading the login form, not after.
 * Nothing here touches recovery data, and an unknown language code is ignored
 * rather than applied, so the worst a crafted request can do is reload the page
 * someone was already on.
 */
final class LocaleController extends Controller
{
    public function update(Request $request): void
    {
        $locale = (string) $request->input('locale', '');

        if (Lang::set($locale)) {
            Session::flash('success', Lang::get('locale.changed', ['language' => Lang::name($locale)]));
        }

        Response::redirect($this->safeReturnPath($request));
    }

    /**
     * Where to send the user back to.
     *
     * The Referer decides, but only after it is confirmed to point at this
     * installation — echoing it back unchecked would turn a language link into
     * an open redirect, which is exactly the kind of thing that gets used to
     * make a phishing page look like it came from the bank's own panel.
     */
    private function safeReturnPath(Request $request): string
    {
        $fallback = Auth::check() ? Auth::homeFor() : '/login';
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

        if ($referer === '') {
            return $fallback;
        }

        $parts = parse_url($referer);

        if (!is_array($parts) || !isset($parts['path'])) {
            return $fallback;
        }

        // Only ordinary web URLs; nothing like javascript: gets echoed into a
        // Location header.
        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return $fallback;
        }

        // An absolute Referer must match the configured host, or a language link
        // becomes an open redirect — handy for making a phishing page look as if
        // it came from the bank's own panel.
        if (isset($parts['host'])) {
            $appHost = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);

            if (is_string($appHost) && strcasecmp($appHost, $parts['host']) !== 0) {
                return $fallback;
            }
        }

        if (!str_starts_with($parts['path'], '/')) {
            return $fallback;
        }

        // Never bounce back to a POST-only endpoint such as /locale itself.
        if (in_array($parts['path'], ['/locale', '/logout'], true)) {
            return $fallback;
        }

        // Returned absolutely when it came in absolutely: Response::redirect()
        // passes full URLs through untouched, whereas a bare path would be run
        // through url() and pick up the base path a second time on an
        // installation that lives in a subdirectory.
        return isset($parts['host'])
            ? $referer
            : $parts['path'] . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }
}
