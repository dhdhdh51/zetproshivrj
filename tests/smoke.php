<?php

declare(strict_types=1);

/**
 * DocuPilot AI — functional smoke test.
 *
 * Exercises the real services and models against a real database:
 * auth, business profile, logo upload, clients, calculations, numbering,
 * plan limits, ownership rules, share links, PDF rendering, email logging,
 * PayU hashing and subscription activation.
 *
 * Usage:  php tests/smoke.php        (see tests/run-smoke.sh for a one-shot run)
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('DOCUPILOT_TESTING', true);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Settings;
use App\Core\Validator;
use App\Models\ActivityLog;
use App\Models\AiUsage;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\EmailLog;
use App\Models\EmailVerification;
use App\Models\PasswordReset;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ShareLink;
use App\Models\Subscription;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\MailService;
use App\Services\PayUService;
use App\Services\PDFService;
use App\Services\UploadService;
use App\Services\UsageService;

/* -------------------------------------------------------------------------- */
/* Tiny assertion helpers                                                     */
/* -------------------------------------------------------------------------- */

$passed = 0;
$failed = [];
$section = '';

function section(string $name): void
{
    global $section;
    $section = $name;
    echo "\n\033[1m» " . $name . "\033[0m\n";
}

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $section;

    if ($condition) {
        $passed++;
        echo "  \033[32m✓\033[0m " . $label . "\n";

        return;
    }

    $failed[] = $section . ' → ' . $label . ($detail !== '' ? ' (' . $detail . ')' : '');
    echo "  \033[31m✗ " . $label . ($detail !== '' ? ' — ' . $detail : '') . "\033[0m\n";
}

function same(string $label, mixed $expected, mixed $actual): void
{
    check(
        $label,
        $expected === $actual,
        sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true))
    );
}

function near(string $label, float $expected, float $actual): void
{
    check($label, abs($expected - $actual) < 0.011, sprintf('expected %.2f, got %.2f', $expected, $actual));
}

/* -------------------------------------------------------------------------- */

echo "DocuPilot AI — functional smoke test\n";
echo str_repeat('=', 62) . "\n";

section('Environment');
check('Database connection', Database::isConnected(), (string) Database::lastError());

if (!Database::isConnected()) {
    exit("\nCannot continue without a database.\n");
}

check('Composer autoloader present', is_file(base_path('vendor/autoload.php')));
check('Dompdf available', (new PDFService())->isAvailable());
check('PHPMailer available', (new MailService())->isAvailable());
check('storage/generated writable', is_writable(storage_path('generated')));
check('storage/uploads writable', is_writable(storage_path('uploads')));
check('Seeded plans present', (new Plan())->findBySlug('free') !== null && (new Plan())->findBySlug('pro') !== null);
check('Seeded templates present', count((new DocumentTemplate())->active()) === 3);
check('Seeded admin present', (new User())->findByEmail('admin@docupilot.ai') !== null);

// Keep every email in this run inside the log instead of hitting a real SMTP server.
Config::set('mail.log_only', true);
Settings::set('smtp_host', 'smtp.example.test', 'email');
Settings::set('smtp_from_email', 'documents@example.test', 'email');
Settings::set('smtp_from_name', 'DocuPilot Test', 'email');

/* -------------------------------------------------------------------------- */

section('Authentication');

$users = new User();
$suffix = bin2hex(random_bytes(4));
$email = 'tester+' . $suffix . '@example.test';

$userId = $users->create([
    'name' => 'Priya Sharma',
    'email' => $email,
    'password' => Auth::hashPassword('Secret123'),
    'role' => 'user',
    'status' => 'active',
    'email_verified_at' => null,
]);

check('User created', $userId > 0);
check('Wrong password rejected', Auth::attempt($email, 'nope-nope') === false);
check('Correct password accepted', Auth::attempt($email, 'Secret123', true));
same('Signed-in user id', $userId, (int) Auth::id());
check('Password hash is not readable', (string) ($users->find($userId)['password'] ?? '') !== 'Secret123');
check('Remember token stored hashed', strlen((string) ($users->find($userId)['remember_token'] ?? '')) === 64);
check('Not an admin', Auth::isAdmin() === false);

$verifications = new EmailVerification();
$verifyToken = $verifications->issue($userId);
$verifyRecord = $verifications->findValid($verifyToken);
check('Verification token issued and found', $verifyRecord !== null);
check('Verification token stored as hash', $verifyRecord !== null && (string) $verifyRecord['token'] === hash('sha256', $verifyToken));
check('Bogus verification token rejected', $verifications->findValid(str_repeat('a', 64)) === null);

if ($verifyRecord !== null) {
    $verifications->consume((int) $verifyRecord['id']);
    $users->markVerified($userId);
    check('Email marked verified', !empty($users->find($userId)['email_verified_at']));
    check('Verification token single-use', $verifications->findValid($verifyToken) === null);
}

$resets = new PasswordReset();
$resetToken = $resets->issue($email);
$resetRecord = $resets->findValid($resetToken);
check('Password reset token issued', $resetRecord !== null);

if ($resetRecord !== null) {
    $users->updatePassword($userId, 'BrandNew456');
    $resets->consume((int) $resetRecord['id']);
    check('Password reset token consumed', $resets->findValid($resetToken) === null);
    check('New password works', Auth::attempt($email, 'BrandNew456'));
    check('Old password no longer works', Auth::attempt($email, 'Secret123') === false);
    Auth::attempt($email, 'BrandNew456');
}

$mailer = new MailService();
$verificationEmail = $mailer->sendVerification($users->find($userId) ?? [], $verifyToken);
check('Verification email dispatched (log mode)', $verificationEmail['success'], $verificationEmail['message']);

/* -------------------------------------------------------------------------- */

section('Business profile & logo upload');

$profiles = new BusinessProfile();
$profiles->saveForUser($userId, [
    'business_name' => 'Sharma Digital Studio',
    'email' => 'hello@sharma.test',
    'phone' => '+91 98765 43210',
    'website' => 'https://sharma.test',
    'address' => '14 MG Road',
    'city' => 'Bengaluru',
    'state' => 'Karnataka',
    'country' => 'India',
    'postal_code' => '560001',
    'gstin' => '29ABCDE1234F1Z5',
    'tax_number' => 'ABCDE1234F',
    'bank_name' => 'HDFC Bank',
    'account_name' => 'Sharma Digital Studio',
    'account_number' => '50100123456789',
    'ifsc' => 'HDFC0001234',
    'default_terms' => "1. Valid for 15 days.\n2. 50% advance to start.",
    'default_currency' => 'INR',
    'default_template' => 'modern',
    'signature_name' => 'Priya Sharma',
]);

$profile = $profiles->forUser($userId);
check('Profile saved', $profile !== null);
check('Profile is complete', $profiles->isComplete($profile));
same('GSTIN stored verbatim', '29ABCDE1234F1Z5', (string) ($profile['gstin'] ?? ''));
check('Profile is unique per user', $profiles->count(['user_id' => $userId]) === 1);

$uploads = new UploadService();
$logoSource = sys_get_temp_dir() . '/dp-logo-' . $suffix . '.png';

if (function_exists('imagecreatetruecolor')) {
    $image = imagecreatetruecolor(220, 90);
    imagefill($image, 0, 0, imagecolorallocate($image, 79, 70, 229));
    imagestring($image, 5, 40, 36, 'SHARMA', imagecolorallocate($image, 255, 255, 255));
    imagepng($image, $logoSource);
    imagedestroy($image);

    $logoResult = $uploads->storeLogo([
        'name' => 'logo.png',
        'type' => 'image/png',
        'tmp_name' => $logoSource,
        'error' => UPLOAD_ERR_OK,
        'size' => (int) filesize($logoSource),
    ]);

    check('Logo upload accepted', $logoResult['success'], (string) $logoResult['error']);
    check('Logo filename is generated, not client supplied', $logoResult['success']
        && preg_match('/^[a-f0-9]{32}\.png$/', $logoResult['filename']) === 1);

    if ($logoResult['success']) {
        $profiles->saveForUser($userId, ['logo_path' => $logoResult['filename']]);
        $profile = $profiles->forUser($userId);
        check('Logo stored outside the web root', str_starts_with((string) $uploads->path($logoResult['filename']), storage_path('uploads')));
        check('Logo embeds as a data URI for PDFs', str_starts_with((string) (new PDFService())->logoDataUri($profile), 'data:image/png;base64,'));
    }

    // A text file pretending to be an image must be rejected.
    $fakePath = sys_get_temp_dir() . '/dp-fake-' . $suffix . '.png';
    file_put_contents($fakePath, "<?php echo 'not an image'; ?>");
    $fakeResult = $uploads->storeLogo([
        'name' => 'evil.png',
        'type' => 'image/png',
        'tmp_name' => $fakePath,
        'error' => UPLOAD_ERR_OK,
        'size' => (int) filesize($fakePath),
    ]);
    check('Fake image rejected by MIME check', $fakeResult['success'] === false);
    @unlink($fakePath);
} else {
    echo "  (GD not available — logo upload tests skipped)\n";
}

/* -------------------------------------------------------------------------- */

section('Clients');

$clients = new Client();
$clientId = $clients->create([
    'user_id' => $userId,
    'name' => 'Rahul Verma',
    'company' => 'ABC Technologies',
    'email' => 'rahul@abctech.test',
    'phone' => '+91 90000 00000',
    'address' => "Office 402, Tech Park\nBengaluru 560001",
    'notes' => 'Prefers weekly updates.',
]);

check('Client created', $clientId > 0);
check('Client search by company', $clients->search($userId, 'ABC') !== []);
check('Client search ignores other users', $clients->search($userId + 9999, 'ABC') === []);
$clients->updateById($clientId, ['phone' => '+91 91111 11111']);
same('Client updated', '+91 91111 11111', (string) ($clients->find($clientId)['phone'] ?? ''));

/* -------------------------------------------------------------------------- */

section('Server-side calculations');

$service = new DocumentService();

$items = [
    ['description' => 'Website design & development', 'quantity' => 10, 'unit' => 'hour', 'rate' => 1000, 'tax_percent' => 18],
    ['description' => 'Maintenance & support', 'quantity' => 3, 'unit' => 'month', 'rate' => 2000, 'tax_percent' => 18],
];

$fixed = $service->calculate($items, 'fixed', 2000);
near('Subtotal (10×1000 + 3×2000)', 16000.00, $fixed['subtotal']);
near('Fixed discount applied', 2000.00, $fixed['discount_total']);
near('Tax on discounted amount', 2520.00, $fixed['tax_total']);
near('Grand total', 16520.00, $fixed['total']);

$percent = $service->calculate($items, 'percent', 10);
near('Percentage discount (10%)', 1600.00, $percent['discount_total']);
near('Tax with percentage discount', 2592.00, $percent['tax_total']);
near('Grand total with percentage discount', 16992.00, $percent['total']);

$capped = $service->calculate($items, 'fixed', 999999);
near('Discount cannot exceed the subtotal', 16000.00, $capped['discount_total']);
near('Total never goes negative', 0.00, $capped['total']);

$dirty = $service->calculate([
    ['description' => 'Tampered row', 'quantity' => '2', 'unit' => 'unit', 'rate' => '1,500.50', 'tax_percent' => '500'],
    ['description' => '', 'quantity' => 5, 'rate' => 9999, 'tax_percent' => 5],
    ['description' => 'Negative attempt', 'quantity' => -4, 'unit' => 'unit', 'rate' => -100, 'tax_percent' => -8],
], 'percent', 250);
same('Rows without a description are dropped', 2, count($dirty['items']));
near('Formatted numbers parsed', 3001.00, $dirty['items'][0]['line_subtotal']);
near('Tax percentage clamped to 100', 100.00, $dirty['items'][0]['tax_percent']);
near('Negative quantities and rates clamped to 0', 0.00, $dirty['items'][1]['line_subtotal']);
near('Percentage discount clamped to 100', 3001.00, $dirty['discount_total']);

/* -------------------------------------------------------------------------- */

section('Documents, numbering & items');

$documents = new Document();
$year = date('Y');

$documentId = $service->create($userId, [
    'client_id' => $clientId,
    'document_type' => 'quotation',
    'title' => 'Website Development Quotation',
    'summary' => 'Design, build and support for the new marketing site.',
    'status' => 'draft',
    'template' => 'modern',
    'currency' => 'INR',
    'issue_date' => date('Y-m-d'),
    'valid_until' => date('Y-m-d', strtotime('+15 days')),
    'client_name' => 'Rahul Verma',
    'client_company' => 'ABC Technologies',
    'client_email' => 'rahul@abctech.test',
    'client_phone' => '+91 90000 00000',
    'client_address' => 'Office 402, Tech Park, Bengaluru',
    'notes' => 'Thank you for the opportunity.',
    'terms' => "1. Valid for 15 days.\n2. 50% advance to start.",
    'discount_type' => 'fixed',
    'discount_value' => 2000,
    'ai_generated' => true,
    'ai_prompt' => 'Quotation for ABC Technologies, website development worth 16000 including maintenance',
    'items' => $items,
]);

$document = $documents->find($documentId) ?? [];
check('Document created', $document !== []);
same('First quotation number', 'QT-' . $year . '-0001', (string) ($document['document_number'] ?? ''));
near('Stored total matches calculation', 16520.00, (float) ($document['total'] ?? 0));
same('Items persisted', 2, count($documents->items($documentId)));
// 10 × 1000 = 10,000 subtotal; discount spread pro-rata leaves 8,750 taxable → 1,575 tax.
near('Line total stored per item', 11575.00, (float) ($documents->items($documentId)[0]['line_total'] ?? 0));
near('Line tax stored per item', 1575.00, (float) ($documents->items($documentId)[0]['line_tax'] ?? 0));
check('AI flag stored', (int) ($document['ai_generated'] ?? 0) === 1);

$secondId = $service->create($userId, [
    'document_type' => 'quotation', 'title' => 'Second quote', 'currency' => 'INR',
    'client_name' => 'Rahul Verma', 'items' => [['description' => 'Consulting', 'quantity' => 1, 'rate' => 5000]],
]);
same('Numbering increments', 'QT-' . $year . '-0002', (string) ($documents->find($secondId)['document_number'] ?? ''));

$invoiceId = $service->create($userId, [
    'document_type' => 'invoice', 'title' => 'Invoice for milestone 1', 'currency' => 'INR',
    'client_name' => 'Rahul Verma', 'items' => [['description' => 'Milestone 1', 'quantity' => 1, 'rate' => 8000, 'tax_percent' => 18]],
]);
same('Invoices use their own series', 'INV-' . $year . '-0001', (string) ($documents->find($invoiceId)['document_number'] ?? ''));

foreach (['proposal' => 'PROP', 'estimate' => 'EST', 'purchase_order' => 'PO'] as $type => $prefix) {
    $id = $service->create($userId, [
        'document_type' => $type, 'title' => ucfirst($type) . ' test', 'currency' => 'INR',
        'client_name' => 'Rahul Verma', 'items' => [['description' => 'Scope of work', 'quantity' => 1, 'rate' => 1000]],
    ]);
    same($prefix . ' prefix used for ' . $type, $prefix . '-' . $year . '-0001', (string) ($documents->find($id)['document_number'] ?? ''));
}

$service->update($document, [
    'title' => 'Website Development Quotation (revised)',
    'status' => 'final',
    'currency' => 'INR',
    'issue_date' => date('Y-m-d'),
    'client_name' => 'Rahul Verma',
    'discount_type' => 'percent',
    'discount_value' => 10,
    'items' => $items,
]);
$document = $documents->find($documentId) ?? [];
same('Title updated', 'Website Development Quotation (revised)', (string) $document['title']);
same('Status updated', 'final', (string) $document['status']);
near('Totals recalculated on update', 16992.00, (float) $document['total']);
same('Document number unchanged by edits', 'QT-' . $year . '-0001', (string) $document['document_number']);

$duplicateId = $service->duplicate($document);
$duplicate = $documents->find($duplicateId) ?? [];
check('Duplicate created as a new draft', (string) ($duplicate['status'] ?? '') === 'draft');
check('Duplicate has its own number', (string) ($duplicate['document_number'] ?? '') !== (string) $document['document_number']);
same('Duplicate copies items', 2, count($documents->items($duplicateId)));

$filtered = $documents->paginateForUser($userId, ['type' => 'quotation'], 1, 5);
check('Type filter works', $filtered['total'] >= 3);
$searched = $documents->paginateForUser($userId, ['search' => 'INV-' . $year], 1, 5);
same('Search by document number', 1, (int) $searched['total']);
$statusFiltered = $documents->paginateForUser($userId, ['status' => 'final'], 1, 5);
same('Status filter works', 1, (int) $statusFiltered['total']);
$paged = $documents->paginateForUser($userId, [], 1, 2);
same('Pagination page size respected', 2, count($paged['data']));
check('Pagination reports more than one page', (int) $paged['last_page'] > 1);

/* -------------------------------------------------------------------------- */

section('Ownership & access control');

$otherId = $users->create([
    'name' => 'Other User',
    'email' => 'other+' . $suffix . '@example.test',
    'password' => Auth::hashPassword('Secret123'),
    'role' => 'user',
    'status' => 'active',
    'email_verified_at' => now(),
]);

$forbidden = false;
$forbiddenCode = 0;

try {
    $documents->findForUser($documentId, $otherId);
} catch (HttpException $e) {
    $forbidden = true;
    $forbiddenCode = $e->getStatusCode();
}

check('Another user cannot open the document', $forbidden);
same('Ownership violation returns 403', 403, $forbiddenCode);

$clientForbidden = false;
try {
    $clients->findForUser($clientId, $otherId);
} catch (HttpException $e) {
    $clientForbidden = $e->getStatusCode() === 403;
}
check('Another user cannot open the client', $clientForbidden);

$missing = false;
try {
    $documents->findForUser(999999, $userId);
} catch (HttpException $e) {
    $missing = $e->getStatusCode() === 404;
}
check('Unknown document returns 404', $missing);
same('Other user sees no documents', 0, (int) $documents->paginateForUser($otherId, [], 1, 10)['total']);

/* -------------------------------------------------------------------------- */

section('PDF generation (all three templates)');

$pdf = new PDFService();
$profile = $profiles->forUserOrEmpty($userId);

foreach (['modern', 'corporate', 'minimal'] as $slug) {
    $documents->updateById($documentId, ['template' => $slug]);
    $current = $documents->find($documentId) ?? [];

    $html = $pdf->html($current, $documents->items($documentId), $profile, ['for_pdf' => false]);
    check($slug . ': HTML contains the document number', str_contains($html, (string) $current['document_number']));
    check($slug . ': HTML contains the grand total', str_contains($html, number_format((float) $current['total'], 2)));
    check($slug . ': HTML contains GSTIN from the profile', str_contains($html, '29ABCDE1234F1Z5'));
    check($slug . ': HTML contains the signature block', str_contains($html, 'Priya Sharma'));

    $result = $pdf->generate($current, $documents->items($documentId), $profile);
    check($slug . ': PDF generated', $result['success'], (string) $result['error']);

    if ($result['success']) {
        $bytes = (string) file_get_contents($result['path']);
        check($slug . ': file is a valid PDF', str_starts_with($bytes, '%PDF-'));
        check($slug . ': PDF has real content (>8 KB)', strlen($bytes) > 8192, strlen($bytes) . ' bytes');
        $documents->updateById($documentId, ['pdf_path' => $result['filename'], 'pdf_generated_at' => now()]);
    }
}

$documents->updateById($documentId, ['template' => 'modern']);
$current = $documents->find($documentId) ?? [];
check('Stored PDF is discoverable', $pdf->existsFor($current));

$service->invalidatePdf($current);
check('Editing invalidates the stored PDF', $pdf->existsFor($documents->find($documentId) ?? []) === false);

// Rupee glyph availability in the bundled PDF font.
$fontFile = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
if (is_file($fontFile) && class_exists(\FontLib\Font::class)) {
    $font = \FontLib\Font::load($fontFile);
    $font->parse();
    $map = $font->getUnicodeCharMap() ?? [];
    check('PDF font includes the rupee sign (U+20B9)', isset($map[0x20B9]));
    $font->close();
}

/* -------------------------------------------------------------------------- */

section('Share links');

$shares = new ShareLink();
$link = $shares->enable($documentId, $userId);
check('Share link created', ($link['token'] ?? '') !== '');
check('Token is 48 hex characters', preg_match('/^[a-f0-9]{48}$/', (string) $link['token']) === 1);
check('Share link resolves by token', $shares->findByToken((string) $link['token']) !== null);
check('Unknown token does not resolve', $shares->findByToken(str_repeat('f', 48)) === null);

$again = $shares->enable($documentId, $userId);
same('Enabling twice reuses the same row', (string) $link['token'], (string) $again['token']);

$shares->registerView((int) $link['id']);
same('Views counted', 1, (int) ($shares->find((int) $link['id'])['views'] ?? 0));

$shares->disable($documentId);
same('Sharing can be disabled', 0, (int) ($shares->forDocument($documentId)['is_active'] ?? 1));

/* -------------------------------------------------------------------------- */

section('Email delivery & logging');

$emailLogs = new EmailLog();
$current = $documents->find($documentId) ?? [];
$pdfResult = $pdf->generate($current, $documents->items($documentId), $profile);
$documents->updateById($documentId, ['pdf_path' => $pdfResult['filename'], 'pdf_generated_at' => now()]);

$sent = $mailer->sendDocument(
    $documents->find($documentId) ?? [],
    [
        'email' => 'rahul@abctech.test',
        'subject' => 'Quotation QT-' . $year . '-0001 from Sharma Digital Studio',
        'message' => "Hi Rahul,\n\nPlease find the quotation attached.",
        'share_url' => url('documents/share/' . (string) $link['token']),
    ],
    (string) $pdfResult['path'],
    $profile
);

check('Document email dispatched', $sent['success'], $sent['message']);
$logs = $emailLogs->forDocument($documentId);
check('Email written to the log', $logs !== []);
same('Log records the document type', 'document', (string) ($logs[0]['type'] ?? ''));
same('Log records success', 'sent', (string) ($logs[0]['status'] ?? ''));
check('Log stores the attachment name', str_ends_with((string) ($logs[0]['attachment'] ?? ''), '.pdf'));

$invalid = $mailer->send('not-an-email', 'Subject', '<p>Body</p>', ['type' => 'test']);
check('Invalid recipient reported as failure', $invalid['success'] === false);
check('Failed attempts are logged too', $emailLogs->statistics()['failed'] >= 1);

$rendered = $mailer->render('document', [
    'message' => 'Hello there',
    'document' => $documents->find($documentId) ?? [],
    'profile' => $profile,
    'share_url' => '',
]);
check('Document email template renders', str_contains($rendered, 'QT-' . $year . '-0001'));
check('Email HTML is escaped', !str_contains($mailer->render('document', [
    'message' => '<script>alert(1)</script>',
    'document' => $documents->find($documentId) ?? [],
    'profile' => $profile,
    'share_url' => '',
]), '<script>'));

/* -------------------------------------------------------------------------- */

section('Plans, usage limits & subscriptions');

$usage = new UsageService();
$plans = new Plan();
$subscriptions = new Subscription();

$freePlan = $usage->currentPlan($userId);
same('New accounts start on Free', 'free', (string) $freePlan['slug']);
same('Free document limit', 5, (int) $freePlan['document_limit']);
check('Free plan cannot email documents', $usage->canEmailDocuments($userId)['allowed'] === false);
check('Free plan is limited to the basic template', $usage->canUseAllTemplates($userId) === false);
check('Basic template allowed on Free', (new DocumentTemplate())->isAllowed('modern', false));
check('Corporate template blocked on Free', (new DocumentTemplate())->isAllowed('corporate', false) === false);

$usageModel = new AiUsage();
$usageModel->increment($userId, 'documents_created', 5);
check('Document limit enforced at 5', $usage->canCreateDocument($userId)['allowed'] === false);
check('Limit message mentions the plan', str_contains($usage->canCreateDocument($userId)['message'], 'Free'));

$usageModel->increment($userId, 'ai_generations', 5);
check('AI limit enforced at 5', $usage->canUseAi($userId)['allowed'] === false);

$proPlan = $plans->findBySlug('pro');
$subscriptionId = $subscriptions->activate($userId, (int) $proPlan['id'], 1);
check('Subscription activated', $subscriptionId > 0);

$currentPlan = $usage->currentPlan($userId);
same('Plan upgraded to Pro', 'pro', (string) $currentPlan['slug']);
same('Pro document limit', 100, (int) $currentPlan['document_limit']);
check('Pro can create documents again', $usage->canCreateDocument($userId)['allowed']);
check('Pro can use AI again', $usage->canUseAi($userId)['allowed']);
check('Pro can email documents', $usage->canEmailDocuments($userId)['allowed']);
check('Pro unlocks all templates', $usage->canUseAllTemplates($userId));

$summary = $usage->summary($userId);
same('Usage summary counts documents', 5, (int) $summary['documents_used']);
same('Usage summary counts AI generations', 5, (int) $summary['ai_used']);
near('Usage percentage calculated', 5.0, (float) $summary['ai_percent']);
check('Renewal date set one month ahead', strtotime((string) $summary['renews_at']) > time() + (25 * 86400));

$subscriptions->cancel($subscriptionId);
same('Cancelling returns the account to Free', 'free', (string) $usage->currentPlan($userId)['slug']);
$subscriptions->activate($userId, (int) $proPlan['id'], 1);

/* -------------------------------------------------------------------------- */

section('PayU integration');

Settings::set('payu_merchant_key', 'gtKFFx', 'payu');
Settings::set('payu_merchant_salt', 'eCwWELxi', 'payu');
Settings::set('payu_mode', 'test', 'payu');

$payu = new PayUService();
check('PayU reports as configured', $payu->isConfigured());
same('Test mode uses the sandbox endpoint', 'https://test.payu.in/_payment', (string) $payu->config()['base_url']);

$txnid = $payu->newTransactionId();
check('Transaction id generated', str_starts_with($txnid, 'DP') && strlen($txnid) >= 16);

$request = $payu->buildRequest([
    'txnid' => $txnid,
    'amount' => 299.00,
    'productinfo' => 'DocuPilot AI Pro plan',
    'firstname' => 'Priya Sharma',
    'email' => $email,
    'phone' => '9999999999',
    'surl' => url('billing/payu/success'),
    'furl' => url('billing/payu/failure'),
    'udf1' => (string) $userId,
    'udf2' => (string) $proPlan['id'],
]);

$fields = $request['fields'];
same('Amount formatted to two decimals', '299.00', (string) $fields['amount']);

$expectedHash = strtolower(hash('sha512', implode('|', [
    'gtKFFx', $txnid, '299.00', $fields['productinfo'], $fields['firstname'], $email,
    (string) $userId, (string) $proPlan['id'], '', '', '', '', '', '', '', '', 'eCwWELxi',
])));
same('Request hash matches the PayU specification', $expectedHash, (string) $fields['hash']);

$callback = [
    'mihpayid' => '403993715513244460',
    'mode' => 'CC',
    'status' => 'success',
    'txnid' => $txnid,
    'amount' => '299.00',
    'productinfo' => $fields['productinfo'],
    'firstname' => $fields['firstname'],
    'email' => $email,
    'udf1' => (string) $userId,
    'udf2' => (string) $proPlan['id'],
    'udf3' => '', 'udf4' => '', 'udf5' => '',
    'key' => 'gtKFFx',
];
$callback['hash'] = strtolower(hash('sha512', implode('|', [
    'eCwWELxi', 'success', '', '', '', '', '', '', '', '',
    (string) $proPlan['id'], (string) $userId, $email, $fields['firstname'], $fields['productinfo'], '299.00', $txnid, 'gtKFFx',
])));

check('Valid response hash accepted', $payu->verifyResponseHash($callback));

$tampered = $callback;
$tampered['amount'] = '1.00';
check('Tampered amount fails hash verification', $payu->verifyResponseHash($tampered) === false);

$noHash = $callback;
unset($noHash['hash']);
check('Missing hash rejected', $payu->verifyResponseHash($noHash) === false);

$failedCallback = $callback;
$failedCallback['status'] = 'failure';
$failedCallback['hash'] = strtolower(hash('sha512', implode('|', [
    'eCwWELxi', 'failure', '', '', '', '', '', '', '', '',
    (string) $proPlan['id'], (string) $userId, $email, $fields['firstname'], $fields['productinfo'], '299.00', $txnid, 'gtKFFx',
])));
$failureConfirmation = $payu->confirm($failedCallback);
check('Failed status is never treated as paid', $failureConfirmation['paid'] === false);

$payments = new Payment();
$paymentId = $payments->create([
    'user_id' => $userId,
    'plan_id' => (int) $proPlan['id'],
    'gateway' => 'payu',
    'txnid' => $txnid,
    'amount' => 299.00,
    'currency' => 'INR',
    'status' => 'pending',
]);
check('Pending payment recorded before redirect', $paymentId > 0);
check('Payment found by transaction id', $payments->findByTxnId($txnid) !== null);

$payments->updateById($paymentId, [
    'status' => 'success',
    'gateway_payment_id' => (string) $callback['mihpayid'],
    'payment_mode' => 'CC',
    'verified_at' => now(),
    'paid_at' => now(),
]);
$stats = $payments->statistics();
check('Payment statistics count revenue', $stats['revenue'] >= 299.0);

/* -------------------------------------------------------------------------- */

section('Settings, validation & activity log');

Settings::set('site_name', 'DocuPilot QA', 'system');
Settings::flush();
same('Setting saved and read back', 'DocuPilot QA', Settings::string('site_name'));
check('Boolean settings cast correctly', Settings::bool('registration_enabled', false) === true);
Settings::set('site_name', 'DocuPilot AI', 'system');
Settings::flush();

Settings::set('openrouter_api_key', '', 'ai');
Settings::flush();
check('Empty setting falls back to config', Settings::string('openrouter_model') !== '');

$validator = Validator::make(
    ['email' => 'not-an-email', 'password' => 'short', 'name' => '', 'ok' => 'yes'],
    ['email' => 'required|email', 'password' => 'required|password', 'name' => 'required', 'ok' => 'nullable|max:10']
);
check('Validator catches all three errors', $validator->fails() && count($validator->errors()) === 3);
check('Validator reports a friendly first error', $validator->firstError() !== null);

$unique = Validator::make(['email' => $email], ['email' => 'required|email|unique:users,email']);
check('Unique rule detects an existing email', $unique->fails());

$uniqueIgnore = Validator::make(['email' => $email], ['email' => 'required|email|unique:users,email,' . $userId]);
check('Unique rule can ignore the current record', $uniqueIgnore->passes());

ActivityLog::record($userId, 'test.action', 'Smoke test entry', 'document', $documentId);
check('Activity log records entries', (new ActivityLog())->forUser($userId, 5) !== []);

$adminUsers = $users->paginateForAdmin('', '', '', 1, 10);
check('Admin user listing works', $adminUsers['total'] >= 2);
check('Admin listing includes document counts', isset($adminUsers['data'][0]['documents_count']));
check('Admin statistics available', $users->statistics()['total'] >= 2);

/* -------------------------------------------------------------------------- */

section('Deletion & cleanup');

$itemsBefore = count($documents->items($duplicateId));
$service->delete($documents->find($duplicateId) ?? []);
check('Document deleted', $documents->find($duplicateId) === null);
check('Items cascade-deleted with the document', $itemsBefore > 0 && $documents->items($duplicateId) === []);

// Re-attach the client so the ON DELETE SET NULL constraint is genuinely exercised.
Database::update('documents', ['client_id' => $clientId], 'id = :id', ['id' => $documentId]);
same('Client re-attached to the document', $clientId, (int) ($documents->find($documentId)['client_id'] ?? 0));

$clients->deleteById($clientId);
check('Client deleted', $clients->find($clientId) === null);

$afterDelete = $documents->find($documentId) ?? [];
check('Documents survive client deletion', $afterDelete !== []);
check(
    'Document client link set to NULL by the foreign key',
    array_key_exists('client_id', $afterDelete) && $afterDelete['client_id'] === null
);

@unlink($logoSource);

/* -------------------------------------------------------------------------- */

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
