<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Models\Plan;
use App\Services\MailService;

final class PageController extends Controller
{
    public function landing(Request $request): void
    {
        if (Auth::check()) {
            $this->redirect(Auth::isAdmin() ? '/admin' : '/dashboard');

            return;
        }

        $this->view('pages.landing', [
            'title' => 'Create professional business documents with AI',
            'meta_description' => 'Create quotations, invoices, proposals and client-ready documents in minutes with '
                . app_name() . '. AI drafting, professional templates, PDF export, client sharing and email delivery.',
            'plans' => (new Plan())->activePlans(),
            'is_landing' => true,
        ], 'layouts.public');
    }

    public function privacy(Request $request): void
    {
        $this->view('pages.privacy', [
            'title' => 'Privacy Policy',
            'meta_description' => 'How ' . app_name() . ' collects, stores and protects your data.',
        ], Auth::check() ? 'layouts.app' : 'layouts.public');
    }

    public function terms(Request $request): void
    {
        $this->view('pages.terms', [
            'title' => 'Terms of Service',
            'meta_description' => 'The terms that apply when you use ' . app_name() . '.',
        ], Auth::check() ? 'layouts.app' : 'layouts.public');
    }

    public function contact(Request $request): void
    {
        $this->view('pages.contact', [
            'title' => 'Contact us',
            'meta_description' => 'Get in touch with the ' . app_name() . ' team.',
            'contact_email' => Settings::string('contact_email'),
        ], Auth::check() ? 'layouts.app' : 'layouts.public');
    }

    public function sendContact(Request $request): void
    {
        RateLimiter::guard('contact:' . $request->ip(), 5, 3600, 'Too many messages. Please try again later.');

        $data = $this->validate($request, [
            'name' => 'required|min:2|max:120',
            'email' => 'required|email|max:190',
            'message' => 'required|min:10|max:4000',
        ], [], '/contact');

        $to = Settings::string('contact_email');

        if ($to === '') {
            $to = Settings::string('smtp_from_email');
        }

        if ($to === '') {
            $this->error('Contact form is not configured yet. Please email us directly.');
            $this->redirect('/contact');

            return;
        }

        $body = '<p><strong>From:</strong> ' . e((string) $data['name']) . ' &lt;' . e((string) $data['email']) . '&gt;</p>'
            . '<p>' . nl2br(e((string) $data['message'])) . '</p>';

        $result = (new MailService())->send($to, 'Contact form · ' . app_name(), $body, [
            'type' => 'contact',
            'reply_to' => (string) $data['email'],
            'user_id' => Auth::id(),
        ]);

        if ($result['success']) {
            $this->success('Thanks for reaching out — we will reply soon.');
        } else {
            $this->error('Your message could not be sent: ' . $result['message']);
        }

        $this->redirect('/contact');
    }

    /* ================================================================== */
    /* SEO                                                                 */
    /* ================================================================== */

    public function robots(Request $request): void
    {
        $lines = [
            'User-agent: *',
            'Allow: /$',
            'Allow: /pricing',
            'Allow: /privacy',
            'Allow: /terms',
            'Allow: /contact',
            'Disallow: /dashboard',
            'Disallow: /documents',
            'Disallow: /clients',
            'Disallow: /profile',
            'Disallow: /billing',
            'Disallow: /admin',
            'Disallow: /api',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /password',
            'Disallow: /email',
            '',
            'Sitemap: ' . url('sitemap.xml'),
        ];

        header('Content-Type: text/plain; charset=UTF-8');
        echo implode("\n", $lines) . "\n";
    }

    public function sitemap(Request $request): void
    {
        $pages = [
            ['loc' => url('/'), 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['loc' => url('pricing'), 'priority' => '0.9', 'changefreq' => 'monthly'],
            ['loc' => url('register'), 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['loc' => url('login'), 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['loc' => url('privacy'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['loc' => url('terms'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['loc' => url('contact'), 'priority' => '0.4', 'changefreq' => 'yearly'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($pages as $page) {
            $xml .= "  <url>\n"
                . '    <loc>' . e($page['loc']) . "</loc>\n"
                . '    <lastmod>' . date('Y-m-d') . "</lastmod>\n"
                . '    <changefreq>' . $page['changefreq'] . "</changefreq>\n"
                . '    <priority>' . $page['priority'] . "</priority>\n"
                . "  </url>\n";
        }

        $xml .= '</urlset>';

        header('Content-Type: application/xml; charset=UTF-8');
        echo $xml;
    }

    public function health(Request $request): void
    {
        $database = \App\Core\Database::isConnected();

        Response::json([
            'app' => app_name(),
            'status' => $database ? 'ok' : 'degraded',
            'database' => $database,
            'php' => PHP_VERSION,
            'time' => now('c'),
        ], $database ? 200 : 503);
    }
}
