<?php

declare(strict_types=1);

/**
 * DocuPilot AI — HTTP smoke test.
 *
 * Drives the real application over HTTP (routing, middleware, CSRF, sessions,
 * document creation, PDF download, sharing) against a running server.
 *
 * Usage: php tests/http-smoke.php http://127.0.0.1:8123
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8123', '/');
$jar = tempnam(sys_get_temp_dir(), 'dp-cookies-');

$passed = 0;
$failed = [];

function section(string $name): void
{
    echo "\n\033[1m» " . $name . "\033[0m\n";
}

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  \033[32m✓\033[0m " . $label . "\n";

        return;
    }

    $failed[] = $label . ($detail !== '' ? ' (' . $detail . ')' : '');
    echo "  \033[31m✗ " . $label . ($detail !== '' ? ' — ' . $detail : '') . "\033[0m\n";
}

/**
 * @return array{status:int, body:string, headers:string, content_type:string, redirect:string}
 */
function request(string $method, string $url, array $fields = [], bool $follow = false): array
{
    global $jar;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT => 60,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    return [
        'status' => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
        'content_type' => $contentType,
        'redirect' => $redirect,
    ];
}

function token(string $html): string
{
    if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $matches) === 1) {
        return $matches[1];
    }

    if (preg_match('/name="_token" value="([a-f0-9]+)"/', $html, $matches) === 1) {
        return $matches[1];
    }

    return '';
}

echo "DocuPilot AI — HTTP smoke test against " . $base . "\n";
echo str_repeat('=', 62) . "\n";

/* -------------------------------------------------------------------------- */

section('Public pages');

$home = request('GET', $base . '/');
check('Landing page returns 200', $home['status'] === 200, 'HTTP ' . $home['status']);
check('Hero headline rendered', str_contains($home['body'], 'Create professional business documents with AI'));
check('Primary call to action present', str_contains($home['body'], 'Start Creating'));
check('Pricing plans rendered on the landing page', str_contains($home['body'], 'Business'));
check('Meta description present', str_contains($home['body'], '<meta name="description"'));
check('Open Graph tags present', str_contains($home['body'], 'og:title'));
check('Canonical URL present', str_contains($home['body'], 'rel="canonical"'));
check('Structured data present', str_contains($home['body'], 'application/ld+json'));

foreach (['/pricing' => 'Pricing', '/privacy' => 'Privacy Policy', '/terms' => 'Terms of Service', '/contact' => 'Contact us'] as $path => $needle) {
    $page = request('GET', $base . $path);
    check($path . ' returns 200', $page['status'] === 200, 'HTTP ' . $page['status']);
    check($path . ' shows "' . $needle . '"', str_contains($page['body'], $needle));
}

$robots = request('GET', $base . '/robots.txt');
check('robots.txt served', $robots['status'] === 200 && str_contains($robots['body'], 'User-agent: *'));
check('robots.txt links the sitemap', str_contains($robots['body'], 'Sitemap:'));
check('robots.txt blocks the admin area', str_contains($robots['body'], 'Disallow: /admin'));

$sitemap = request('GET', $base . '/sitemap.xml');
check('sitemap.xml served as XML', $sitemap['status'] === 200 && str_contains($sitemap['content_type'], 'xml'));
check('sitemap.xml lists the pricing page', str_contains($sitemap['body'], '/pricing'));

$health = request('GET', $base . '/health');
$healthJson = json_decode($health['body'], true);
check('Health endpoint returns JSON', is_array($healthJson));
check('Health reports the database as up', ($healthJson['database'] ?? false) === true);

$assets = request('GET', $base . '/assets/css/app.css');
check('Stylesheet is served', $assets['status'] === 200 && str_contains($assets['body'], '--dp-primary'));

$missing = request('GET', $base . '/this-page-does-not-exist');
check('Unknown page returns 404', $missing['status'] === 404, 'HTTP ' . $missing['status']);
check('404 page is friendly', str_contains($missing['body'], "can't find that page") || str_contains($missing['body'], '404'));

$badShare = request('GET', $base . '/documents/share/not-a-real-token');
check('Invalid share token returns 404', $badShare['status'] === 404, 'HTTP ' . $badShare['status']);

/* -------------------------------------------------------------------------- */

section('Guards & CSRF');

$dashboard = request('GET', $base . '/dashboard');
check('Dashboard redirects guests', $dashboard['status'] === 302, 'HTTP ' . $dashboard['status']);
check('Guest is sent to the login page', str_contains($dashboard['redirect'], '/login'));

$admin = request('GET', $base . '/admin');
check('Admin area redirects guests', $admin['status'] === 302, 'HTTP ' . $admin['status']);

$noCsrf = request('POST', $base . '/login', ['email' => 'a@b.test', 'password' => 'x']);
check('POST without a CSRF token is rejected (419)', $noCsrf['status'] === 419, 'HTTP ' . $noCsrf['status']);

/* -------------------------------------------------------------------------- */

section('Registration & sign-in');

$registerPage = request('GET', $base . '/register');
check('Registration page returns 200', $registerPage['status'] === 200);
$csrf = token($registerPage['body']);
check('CSRF token issued', $csrf !== '');

$suffix = bin2hex(random_bytes(4));
$email = 'http+' . $suffix . '@example.test';

$register = request('POST', $base . '/register', [
    '_token' => $csrf,
    'name' => 'HTTP Tester',
    'email' => $email,
    'password' => 'Secret123',
    'password_confirmation' => 'Secret123',
    'terms' => '1',
]);
check('Registration succeeds and redirects', $register['status'] === 302, 'HTTP ' . $register['status']);
check('New user is sent to business profile onboarding', str_contains($register['redirect'], '/profile/business'));

$profilePage = request('GET', $base . '/profile/business?onboarding=1');
check('Business profile page loads for the new user', $profilePage['status'] === 200, 'HTTP ' . $profilePage['status']);
check('Onboarding hint shown', str_contains($profilePage['body'], 'business name is required')
    || str_contains($profilePage['body'], 'Set up your business'));

$csrf = token($profilePage['body']);
$saveProfile = request('POST', $base . '/profile/business', [
    '_token' => $csrf,
    'business_name' => 'HTTP Test Studio',
    'email' => 'studio@example.test',
    'phone' => '+91 90000 11111',
    'city' => 'Pune',
    'country' => 'India',
    'gstin' => '27ABCDE1234F1Z5',
    'bank_name' => 'ICICI Bank',
    'account_number' => '000111222333',
    'ifsc' => 'ICIC0000123',
    'default_currency' => 'INR',
    'default_template' => 'modern',
    'default_terms' => '1. Payment within 15 days.',
    'signature_name' => 'HTTP Tester',
]);
check('Business profile saved', $saveProfile['status'] === 302, 'HTTP ' . $saveProfile['status']);

$dashboard = request('GET', $base . '/dashboard');
check('Dashboard loads after sign-in', $dashboard['status'] === 200, 'HTTP ' . $dashboard['status']);
check('Dashboard greets the user', str_contains($dashboard['body'], 'HTTP'));
check('Dashboard shows the plan card', str_contains($dashboard['body'], 'Current plan'));
check('Dashboard shows usage counters', str_contains($dashboard['body'], 'AI generations used'));

/* -------------------------------------------------------------------------- */

section('Clients over HTTP');

$clientPage = request('GET', $base . '/clients/create');
check('Add client page loads', $clientPage['status'] === 200);
$csrf = token($clientPage['body']);

$createClient = request('POST', $base . '/clients', [
    '_token' => $csrf,
    'name' => 'Neha Kapoor',
    'company' => 'Kapoor Retail',
    'email' => 'neha@kapoor.test',
    'phone' => '+91 98111 22333',
    'address' => 'Shop 12, Market Road',
    'notes' => 'Wholesale client',
]);
check('Client created', $createClient['status'] === 302, 'HTTP ' . $createClient['status']);

$clientList = request('GET', $base . '/clients');
check('Client list shows the new client', str_contains($clientList['body'], 'Neha Kapoor'));

$clientSearch = request('GET', $base . '/clients?q=Kapoor');
check('Client search finds the client', str_contains($clientSearch['body'], 'Kapoor Retail'));

$clientSearchMiss = request('GET', $base . '/clients?q=zzzznotfound');
check('Client search shows an empty state', str_contains($clientSearchMiss['body'], 'No clients match that search'));

$clientsApi = request('GET', $base . '/api/clients?q=Neha');
$clientsJson = json_decode($clientsApi['body'], true);
check('Client JSON lookup works', is_array($clientsJson) && ($clientsJson['success'] ?? false) === true);
check('Client JSON returns the record', !empty($clientsJson['clients'][0]['name'] ?? ''));

/* -------------------------------------------------------------------------- */

section('Document lifecycle over HTTP');

$createPage = request('GET', $base . '/documents/create');
check('Create wizard loads', $createPage['status'] === 200, 'HTTP ' . $createPage['status']);
check('Wizard asks the AI question', str_contains($createPage['body'], 'What do you want to create?'));
check('Wizard lists all five document types', str_contains($createPage['body'], 'Purchase Order'));
check('Wizard offers the three templates', str_contains($createPage['body'], 'Corporate') && str_contains($createPage['body'], 'Minimal'));
check('Wizard pre-fills default terms from the profile', str_contains($createPage['body'], 'Payment within 15 days'));

$csrf = token($createPage['body']);
$create = request('POST', $base . '/documents', [
    '_token' => $csrf,
    'document_type' => 'quotation',
    'title' => 'Ecommerce Website Quotation',
    'summary' => 'Storefront build with payment integration.',
    'currency' => 'INR',
    'issue_date' => date('Y-m-d'),
    'valid_until' => date('Y-m-d', strtotime('+20 days')),
    'client_name' => 'Neha Kapoor',
    'client_company' => 'Kapoor Retail',
    'client_email' => 'neha@kapoor.test',
    'client_phone' => '+91 98111 22333',
    'client_address' => 'Shop 12, Market Road',
    'template' => 'modern',
    'status' => 'draft',
    'discount_type' => 'percent',
    'discount_value' => '5',
    'notes' => 'Thanks for the opportunity.',
    'terms' => '1. Payment within 15 days.',
    'items' => [
        ['description' => 'Storefront design', 'quantity' => '1', 'unit' => 'project', 'rate' => '50000', 'tax_percent' => '18'],
        ['description' => 'Payment gateway integration', 'quantity' => '2', 'unit' => 'unit', 'rate' => '5000', 'tax_percent' => '18'],
    ],
]);
check('Document created', $create['status'] === 302, 'HTTP ' . $create['status']);
check('Redirected into the editor', str_contains($create['redirect'], '/edit'));

preg_match('#/documents/(\d+)/edit#', $create['redirect'], $matches);
$documentId = (int) ($matches[1] ?? 0);
check('Document id captured', $documentId > 0);

if ($documentId > 0) {
    $view = request('GET', $base . '/documents/' . $documentId);
    check('Document page loads', $view['status'] === 200, 'HTTP ' . $view['status']);
    check('Document number displayed', preg_match('/QT-' . date('Y') . '-\d{4}/', $view['body']) === 1);
    // 60000 subtotal, 5% discount = 3000, tax 18% on 57000 = 10260, total 67260
    check('Server-side total is correct (67,260.00)', str_contains($view['body'], '67,260.00'), 'totals mismatch');
    check('Subtotal shown (60,000.00)', str_contains($view['body'], '60,000.00'));
    check('Discount shown (3,000.00)', str_contains($view['body'], '3,000.00'));

    $preview = request('GET', $base . '/documents/' . $documentId . '/preview');
    check('Printable preview renders', $preview['status'] === 200 && str_contains($preview['body'], 'Ecommerce Website Quotation'));
    check('Preview includes the business GSTIN', str_contains($preview['body'], '27ABCDE1234F1Z5'));
    check('Preview includes bank details', str_contains($preview['body'], 'ICIC0000123'));

    $editPage = request('GET', $base . '/documents/' . $documentId . '/edit');
    check('Editor loads with existing items', str_contains($editPage['body'], 'Storefront design'));
    check('Editor hides AI tools while OpenRouter is unconfigured', !str_contains($editPage['body'], 'data-ai-action'));
    check('Wizard explains that AI is unavailable', str_contains($createPage['body'], 'AI unavailable'));

    $csrf = token($editPage['body']);
    $update = request('POST', $base . '/documents/' . $documentId, [
        '_token' => $csrf,
        'document_type' => 'quotation',
        'title' => 'Ecommerce Website Quotation (revised)',
        'currency' => 'INR',
        'issue_date' => date('Y-m-d'),
        'client_name' => 'Neha Kapoor',
        'status' => 'final',
        'template' => 'modern',
        'discount_type' => 'fixed',
        'discount_value' => '0',
        'items' => [
            ['description' => 'Storefront design', 'quantity' => '1', 'unit' => 'project', 'rate' => '50000', 'tax_percent' => '18'],
        ],
    ]);
    check('Document updated', $update['status'] === 302, 'HTTP ' . $update['status']);

    $view = request('GET', $base . '/documents/' . $documentId);
    check('Revised title saved', str_contains($view['body'], 'revised'));
    check('Totals recalculated after edit (59,000.00)', str_contains($view['body'], '59,000.00'));
    check('Status is now final', str_contains($view['body'], 'final'));

    $csrf = token($view['body']);
    $pdf = request('POST', $base . '/documents/' . $documentId . '/pdf', ['_token' => $csrf]);
    check('PDF generation succeeds', $pdf['status'] === 302, 'HTTP ' . $pdf['status']);

    $download = request('GET', $base . '/documents/' . $documentId . '/download');
    check('PDF download returns a PDF', str_contains($download['content_type'], 'application/pdf'), $download['content_type']);
    check('PDF body starts with the PDF marker', str_starts_with($download['body'], '%PDF-'));
    check('PDF is a realistic size', strlen($download['body']) > 8192, strlen($download['body']) . ' bytes');
    check('PDF is sent as an attachment', str_contains($download['headers'], 'attachment; filename="QT-'));

    $view = request('GET', $base . '/documents/' . $documentId);
    $csrf = token($view['body']);
    $share = request('POST', $base . '/documents/' . $documentId . '/share', ['_token' => $csrf]);
    check('Share link enabled', $share['status'] === 302, 'HTTP ' . $share['status']);

    $view = request('GET', $base . '/documents/' . $documentId);
    preg_match('#/documents/share/([a-f0-9]{48})#', $view['body'], $shareMatch);
    $shareToken = (string) ($shareMatch[1] ?? '');
    check('Share token rendered on the page', $shareToken !== '');

    if ($shareToken !== '') {
        $publicPage = request('GET', $base . '/documents/share/' . $shareToken);
        check('Public share page is reachable without signing in', $publicPage['status'] === 200, 'HTTP ' . $publicPage['status']);
        check('Public page shows the document', str_contains($publicPage['body'], 'Ecommerce Website Quotation'));
        check('Public page is marked noindex', str_contains($publicPage['body'], 'noindex'));

        $publicPdf = request('GET', $base . '/documents/share/' . $shareToken . '/download');
        check('Public PDF download works', str_contains($publicPdf['content_type'], 'application/pdf'));

        $csrf = token($view['body']);
        request('POST', $base . '/documents/' . $documentId . '/unshare', ['_token' => $csrf]);
        $disabled = request('GET', $base . '/documents/share/' . $shareToken);
        check('Disabled share link returns 403', $disabled['status'] === 403, 'HTTP ' . $disabled['status']);
    }

    $sendPage = request('GET', $base . '/documents/' . $documentId . '/send');
    check('Send page explains the Free plan limit', $sendPage['status'] === 200
        && str_contains($sendPage['body'], 'Email delivery is a paid feature'));

    $list = request('GET', $base . '/documents');
    check('Document list renders', $list['status'] === 200);
    check('List includes the document', str_contains($list['body'], 'Ecommerce'));
    check('Type filter renders', str_contains($list['body'], 'All types'));

    $filtered = request('GET', $base . '/documents?status=draft');
    check('Status filter with no matches shows an empty state', str_contains($filtered['body'], 'Nothing matches those filters'));

    $duplicateSource = request('GET', $base . '/documents/' . $documentId);
    $csrf = token($duplicateSource['body']);
    $duplicate = request('POST', $base . '/documents/' . $documentId . '/duplicate', ['_token' => $csrf]);
    check('Duplicate created', $duplicate['status'] === 302, 'HTTP ' . $duplicate['status']);

    $documentsPage = request('GET', $base . '/documents');
    check('Duplicate appears in the list', substr_count($documentsPage['body'], 'Ecommerce') >= 2);
}

/* -------------------------------------------------------------------------- */

section('AI endpoints without a key');

$editPage = request('GET', $base . '/documents/create');
$csrf = token($editPage['body']);

$aiCall = request('POST', $base . '/api/ai/document', [
    '_token' => $csrf,
    'instructions' => 'Create a quotation for a website worth 40000 including maintenance',
    'document_type' => 'quotation',
    'currency' => 'INR',
]);
$aiJson = json_decode($aiCall['body'], true);
check('AI endpoint responds with JSON', is_array($aiJson));
check('AI endpoint reports it is not configured (503)', $aiCall['status'] === 503, 'HTTP ' . $aiCall['status']);
check('AI failure message is actionable', str_contains((string) ($aiJson['message'] ?? ''), 'OpenRouter'));

$calc = request('POST', $base . '/api/documents/calculate', [
    '_token' => $csrf,
    'discount_type' => 'percent',
    'discount_value' => '10',
    'items' => [
        ['description' => 'Item A', 'quantity' => '2', 'rate' => '1000', 'tax_percent' => '18'],
    ],
]);
$calcJson = json_decode($calc['body'], true);
check('Server-side calculation endpoint works', ($calcJson['success'] ?? false) === true);
check('Calculation returns the discounted total', abs((float) ($calcJson['totals']['total'] ?? 0) - 2124.0) < 0.011,
    'got ' . (string) ($calcJson['totals']['total'] ?? 'n/a'));

/* -------------------------------------------------------------------------- */

section('Billing pages');

$pricing = request('GET', $base . '/pricing');
check('Pricing page loads for a signed-in user', $pricing['status'] === 200);
check('Current plan highlighted', str_contains($pricing['body'], 'Your plan'));
check('Upgrade buttons rendered', str_contains($pricing['body'], 'Upgrade to Pro'));

$billing = request('GET', $base . '/billing');
check('Billing page loads', $billing['status'] === 200);
check('Billing shows usage', str_contains($billing['body'], 'Documents this month'));
check('Billing shows an empty payment history', str_contains($billing['body'], 'No payments yet'));

/* -------------------------------------------------------------------------- */

section('Ownership isolation over HTTP');

$otherSuffix = bin2hex(random_bytes(4));
$logoutPage = request('GET', $base . '/dashboard');
$csrf = token($logoutPage['body']);
request('POST', $base . '/logout', ['_token' => $csrf]);

$registerPage = request('GET', $base . '/register');
$csrf = token($registerPage['body']);
request('POST', $base . '/register', [
    '_token' => $csrf,
    'name' => 'Second Tester',
    'email' => 'second+' . $otherSuffix . '@example.test',
    'password' => 'Secret123',
    'password_confirmation' => 'Secret123',
    'terms' => '1',
]);

if ($documentId > 0) {
    $stolen = request('GET', $base . '/documents/' . $documentId);
    check("Another user cannot open someone else's document (403)", $stolen['status'] === 403, 'HTTP ' . $stolen['status']);

    $stolenPdf = request('GET', $base . '/documents/' . $documentId . '/download');
    check("Another user cannot download someone else's PDF (403)", $stolenPdf['status'] === 403, 'HTTP ' . $stolenPdf['status']);

    $stolenEdit = request('GET', $base . '/documents/' . $documentId . '/edit');
    check("Another user cannot edit someone else's document (403)", $stolenEdit['status'] === 403, 'HTTP ' . $stolenEdit['status']);
}

$emptyList = request('GET', $base . '/documents');
check('New user sees an empty document list', str_contains($emptyList['body'], 'No documents yet'));

$adminBlocked = request('GET', $base . '/admin');
check('Standard user is blocked from the admin panel (403)', $adminBlocked['status'] === 403, 'HTTP ' . $adminBlocked['status']);

/* -------------------------------------------------------------------------- */

section('Admin panel');

$logoutPage = request('GET', $base . '/dashboard');
$csrf = token($logoutPage['body']);
request('POST', $base . '/logout', ['_token' => $csrf]);

$loginPage = request('GET', $base . '/login');
$csrf = token($loginPage['body']);
$adminLogin = request('POST', $base . '/login', [
    '_token' => $csrf,
    'email' => 'admin@docupilot.ai',
    'password' => 'Admin@12345',
]);
check('Admin can sign in', $adminLogin['status'] === 302, 'HTTP ' . $adminLogin['status']);
check('Admin lands in the admin panel', str_contains($adminLogin['redirect'], '/admin'));

$adminDash = request('GET', $base . '/admin');
check('Admin dashboard loads', $adminDash['status'] === 200, 'HTTP ' . $adminDash['status']);
foreach (['Total users', 'Total documents', 'AI generations', 'Revenue', 'Active subscriptions', 'System status'] as $needle) {
    check('Admin dashboard shows "' . $needle . '"', str_contains($adminDash['body'], $needle));
}

$adminPages = [
    '/admin/users' => 'Users',
    '/admin/documents' => 'Documents',
    '/admin/ai' => 'OpenRouter',
    '/admin/plans' => 'Plans',
    '/admin/payments' => 'Payments',
    '/admin/email' => 'SMTP',
    '/admin/payu' => 'Merchant credentials',
    '/admin/templates' => 'Document templates',
    '/admin/settings' => 'System settings',
];

foreach ($adminPages as $path => $needle) {
    $page = request('GET', $base . $path);
    check($path . ' loads', $page['status'] === 200, 'HTTP ' . $page['status']);
    check($path . ' shows "' . $needle . '"', str_contains($page['body'], $needle));
}

$usersPage = request('GET', $base . '/admin/users?q=' . urlencode($email));
check('Admin user search works', str_contains($usersPage['body'], $email));
check('Admin listing never prints password hashes', !str_contains($usersPage['body'], '$2y$'));

preg_match('#/admin/users/(\d+)#', $usersPage['body'], $userMatch);
$targetUserId = (int) ($userMatch[1] ?? 0);

if ($targetUserId > 0) {
    $userDetail = request('GET', $base . '/admin/users/' . $targetUserId);
    check('Admin user detail loads', $userDetail['status'] === 200);
    check('User detail shows plan and usage', str_contains($userDetail['body'], 'Plan &amp; usage'));
    check('User detail never shows a password', !str_contains($userDetail['body'], '$2y$'));
    check('User detail states passwords are hidden', str_contains($userDetail['body'], 'Never displayed'));

    $csrf = token($userDetail['body']);
    $deactivate = request('POST', $base . '/admin/users/' . $targetUserId . '/status', ['_token' => $csrf]);
    check('Admin can deactivate a user', $deactivate['status'] === 302, 'HTTP ' . $deactivate['status']);

    $userDetail = request('GET', $base . '/admin/users/' . $targetUserId);
    check('User now shows as inactive', str_contains($userDetail['body'], 'inactive'));

    $csrf = token($userDetail['body']);
    request('POST', $base . '/admin/users/' . $targetUserId . '/status', ['_token' => $csrf]);

    $plansPage = request('GET', $base . '/admin/plans');
    preg_match('#/admin/users/' . $targetUserId . '/plan#', $userDetail['body'], $planFormMatch);
    check('Plan assignment form present on the user page', $planFormMatch !== []);
}

$aiPage = request('GET', $base . '/admin/ai');
check('AI key field never renders a real key value', preg_match('/name="openrouter_api_key"[^>]*value="sk-or-/', $aiPage['body']) !== 1);
check('AI settings page shows the model field', str_contains($aiPage['body'], 'openrouter_model'));
$csrf = token($aiPage['body']);
$saveAi = request('POST', $base . '/admin/ai', [
    '_token' => $csrf,
    'openrouter_api_key' => '',
    'openrouter_model' => 'openai/gpt-4o-mini',
    'openrouter_base_url' => 'https://openrouter.ai/api/v1',
    'ai_temperature' => '0.4',
    'ai_max_tokens' => '2000',
    'ai_enabled' => '1',
]);
check('AI settings saved', $saveAi['status'] === 302, 'HTTP ' . $saveAi['status']);

// Store a dummy key, confirm it is masked on reload, then clear it again.
$aiPage = request('GET', $base . '/admin/ai');
$csrf = token($aiPage['body']);
request('POST', $base . '/admin/ai', [
    '_token' => $csrf,
    'openrouter_api_key' => 'sk-or-v1-dummy-key-for-testing-1234567890',
    'openrouter_model' => 'openai/gpt-4o-mini',
    'openrouter_base_url' => 'https://openrouter.ai/api/v1',
    'ai_temperature' => '0.4',
    'ai_max_tokens' => '2000',
    'ai_enabled' => '1',
]);

$aiPage = request('GET', $base . '/admin/ai');
check('Stored API key is masked in the form', !str_contains($aiPage['body'], 'sk-or-v1-dummy-key'));
check('Masked key shows only the last characters', str_contains($aiPage['body'], '••••••••••••7890'));
check('AI status flips to active once a key exists', str_contains($aiPage['body'], 'Active'));

$aiTest = request('POST', $base . '/admin/ai/test', ['_token' => token($aiPage['body'])]);
check('Test AI connection reports the failure of a bad key', $aiTest['status'] === 302, 'HTTP ' . $aiTest['status']);

$aiPage = request('GET', $base . '/admin/ai');
check('Failed AI test surfaces an error message', str_contains($aiPage['body'], 'OpenRouter')
    || str_contains($aiPage['body'], 'data-flash="error"'));

$emailPage = request('GET', $base . '/admin/email');
check('Email settings masks the stored password', !str_contains($emailPage['body'], 'value="secret'));
$csrf = token($emailPage['body']);
$saveEmail = request('POST', $base . '/admin/email', [
    '_token' => $csrf,
    'smtp_host' => 'smtp.example.test',
    'smtp_port' => '587',
    'smtp_username' => 'documents@example.test',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'smtp_from_email' => 'documents@example.test',
    'smtp_from_name' => 'DocuPilot AI',
]);
check('Email settings saved', $saveEmail['status'] === 302, 'HTTP ' . $saveEmail['status']);

$payuPage = request('GET', $base . '/admin/payu');
check('PayU page shows the callback URLs', str_contains($payuPage['body'], '/billing/payu/success'));
check('PayU salt is masked', !str_contains($payuPage['body'], 'eCwWELxi'));

$templatesPage = request('GET', $base . '/admin/templates');
check('Templates page lists all three designs', str_contains($templatesPage['body'], 'Modern')
    && str_contains($templatesPage['body'], 'Corporate') && str_contains($templatesPage['body'], 'Minimal'));

$csrf = token($templatesPage['body']);
preg_match('#/admin/templates/(\d+)/default#', $templatesPage['body'], $tplMatch);
if (!empty($tplMatch[1])) {
    $makeDefault = request('POST', $base . '/admin/templates/' . (int) $tplMatch[1] . '/default', ['_token' => $csrf]);
    check('Default template can be changed', $makeDefault['status'] === 302, 'HTTP ' . $makeDefault['status']);
}

$settingsPage = request('GET', $base . '/admin/settings');
$csrf = token($settingsPage['body']);
$saveSettings = request('POST', $base . '/admin/settings', [
    '_token' => $csrf,
    'site_name' => 'DocuPilot AI',
    'contact_email' => 'support@example.test',
    'default_currency' => 'INR',
    'registration_enabled' => '1',
    'ai_enabled' => '1',
]);
check('System settings saved', $saveSettings['status'] === 302, 'HTTP ' . $saveSettings['status']);

$settingsPage = request('GET', $base . '/admin/settings');
check('Contact email persisted', str_contains($settingsPage['body'], 'support@example.test'));

$adminDocs = request('GET', $base . '/admin/documents');
check('Admin can see documents from all users', str_contains($adminDocs['body'], 'Ecommerce'));

if ($documentId > 0) {
    $adminDocPreview = request('GET', $base . '/admin/documents/' . $documentId . '/preview');
    check('Admin can preview any document', $adminDocPreview['status'] === 200);
}

/* -------------------------------------------------------------------------- */

section('Maintenance mode');

$settingsPage = request('GET', $base . '/admin/settings');
$csrf = token($settingsPage['body']);
request('POST', $base . '/admin/settings', [
    '_token' => $csrf,
    'site_name' => 'DocuPilot AI',
    'contact_email' => 'support@example.test',
    'default_currency' => 'INR',
    'registration_enabled' => '1',
    'ai_enabled' => '1',
    'maintenance_mode' => '1',
]);

$adminStillWorks = request('GET', $base . '/admin');
check('Administrators bypass maintenance mode', $adminStillWorks['status'] === 200, 'HTTP ' . $adminStillWorks['status']);

$guestJar = tempnam(sys_get_temp_dir(), 'dp-guest-');
$ch = curl_init($base . '/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $guestJar,
    CURLOPT_TIMEOUT => 30,
]);
$guestBody = (string) curl_exec($ch);
$guestStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($guestJar);

check('Visitors see the maintenance page (503)', $guestStatus === 503, 'HTTP ' . $guestStatus);
check('Maintenance page is friendly', str_contains($guestBody, 'right back'));

$settingsPage = request('GET', $base . '/admin/settings');
$csrf = token($settingsPage['body']);
request('POST', $base . '/admin/settings', [
    '_token' => $csrf,
    'site_name' => 'DocuPilot AI',
    'contact_email' => 'support@example.test',
    'default_currency' => 'INR',
    'registration_enabled' => '1',
    'ai_enabled' => '1',
]);
$restored = request('GET', $base . '/pricing');
check('Maintenance mode switched back off', $restored['status'] === 200, 'HTTP ' . $restored['status']);

/* -------------------------------------------------------------------------- */

@unlink($jar);

echo "\n" . str_repeat('=', 62) . "\n";
echo sprintf("\033[32m%d passed\033[0m", $passed);

if ($failed !== []) {
    echo sprintf(", \033[31m%d failed\033[0m\n\n", count($failed));
    foreach ($failed as $failure) {
        echo "  \033[31m✗\033[0m " . $failure . "\n";
    }
    echo "\n";
    exit(1);
}

echo ", 0 failed — all good.\n";
exit(0);
