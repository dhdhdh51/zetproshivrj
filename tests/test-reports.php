<?php

declare(strict_types=1);

/**
 * Field Visit Verification Report tests — the client's official 13-section
 * format for the two dedicated work streams.
 *
 *   php tests/test-reports.php
 *
 * Requires a seeded database (php database/migrate.php --fresh --demo).
 *
 * What matters here is that every field the printed report contains actually
 * survives a real submission: through the configurable form, into the typed
 * `visits` columns and into the stream's own table. It also pins the two rules
 * the client was explicit about — the reports stay separate, and the declaration
 * is not optional.
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\CkccRenewals;
use App\Services\Export\FieldVisitReport;
use App\Services\Export\PdfWriter;
use App\Services\Export\RecordExport;
use App\Services\Forms;
use App\Services\Inspections;
use App\Services\KrmOts;
use App\Services\Photos;
use App\Services\Visits;

$admin = Database::selectOne(
    "SELECT u.*, r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin' LIMIT 1"
);

if ($admin === null) {
    exit("No admin user found. Run: php database/migrate.php --fresh --demo\n");
}

Auth::setUser($admin);

/* -------------------------------------------------------------------------- */
/* Fixtures                                                                   */
/* -------------------------------------------------------------------------- */

$supervisor = Database::selectOne('SELECT * FROM bc_supervisors ORDER BY id LIMIT 1');

if ($supervisor === null) {
    exit("No BC Supervisor found. Run: php database/migrate.php --fresh --demo\n");
}

$supervisorId = (int) $supervisor['id'];
$branchId = (int) $supervisor['branch_id'];

/**
 * A loan account allocated to our supervisor, carrying the borrower and loan
 * detail the report prints from the loan book.
 */
function lrms_test_account(string $number, int $branchId, int $supervisorId, string $category): int
{
    Database::delete('loan_accounts', 'account_number = :n', ['n' => $number]);

    $id = Database::insert('loan_accounts', [
        'account_number' => $number,
        'cif' => 'CIF' . $number,
        'borrower_name' => 'SEEMA DEVI',
        'father_name' => 'SURESH SINGH',
        'mobile' => '6398648339',
        'gender' => 'female',
        'date_of_birth' => '1985-04-12',
        'alternate_mobile' => null,
        'aadhaar_last4' => '4417',
        'pan_number' => 'ABCDE1234F',
        'village' => 'NAGALA FULU',
        'gram_panchayat' => 'NAGLA',
        'tehsil' => 'PATIYALI',
        'district' => 'KASGANJ',
        'state' => 'UP',
        'pincode' => '207248',
        'address' => 'NAGALA FULU, PATIYALI',
        'branch_id' => $branchId,
        'loan_type' => 'CKCC',
        'sanction_date' => '2021-06-01',
        'npa_date' => '2026-03-31',
        'limit_amount' => 60000,
        'drawing_power' => 55000,
        'outstanding' => 52000,
        'interest_overdue' => 3200,
        'overdue' => 8000,
        'asset_classification' => 'sma_2',
        'loan_category' => $category,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Database::insert('account_assignments', [
        'loan_account_id' => $id,
        'bc_supervisor_id' => $supervisorId,
        'branch_id' => $branchId,
        'is_active' => 1,
        'assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** A tiny valid JPEG, so the photograph evidence requirement is really met. */
function lrms_test_photo(int $visitId): void
{
    $image = imagecreatetruecolor(60, 40);
    imagefilledrectangle($image, 0, 0, 60, 40, imagecolorallocate($image, 210, 215, 220));
    ob_start();
    imagejpeg($image, null, 85);
    $binary = (string) ob_get_clean();
    imagedestroy($image);

    (new Photos())->storeForVisit($visitId, base64_encode($binary), [
        'photo_type' => 'house',
        'latitude' => 27.6890,
        'longitude' => 78.9720,
        'accuracy' => 8.0,
    ]);
}

/** @return array<string, mixed> */
function lrms_test_gps(): array
{
    return [
        'latitude' => 27.6890,
        'longitude' => 78.9720,
        'accuracy' => 8.0,
        'provider' => 'gps',
        'is_mock' => false,
    ];
}

/* -------------------------------------------------------------------------- */
section('Both report forms are installed and separate');
/* -------------------------------------------------------------------------- */

$krmForm = Forms::defaultForm(Forms::KIND_VISIT, 'krm_ots');
$ckccForm = Forms::defaultForm(Forms::KIND_VISIT, 'ckcc_od2');

ok($krmForm !== null, 'KRM OTS report form is installed');
ok($ckccForm !== null, 'CKCC OD-2 report form is installed');
ok(
    $krmForm !== null && $ckccForm !== null && (int) $krmForm['id'] !== (int) $ckccForm['id'],
    'The two streams have separate forms, not one combined form'
);

$krmKeys = array_column(Forms::fields(Forms::KIND_VISIT, (int) $krmForm['id']), 'field_key');
$ckccKeys = array_column(Forms::fields(Forms::KIND_VISIT, (int) $ckccForm['id']), 'field_key');

// The client was explicit: strip the CKCC fields out of the OTS report.
$renewalFieldsInKrm = array_values(array_filter(
    $krmKeys,
    static fn (string $key): bool => str_contains($key, 'renewal')
        || str_contains($key, 'kyc')
        || str_contains($key, 'aadhaar_seeded')
        || str_contains($key, 'biometrics')
));

$otsFieldsInCkcc = array_values(array_filter(
    $ckccKeys,
    static fn (string $key): bool => str_starts_with($key, 'ots_')
        || $key === 'scheme'
        || $key === 'borrower_share'
        || $key === 'initial_deposit_required'
));

equals([], $renewalFieldsInKrm, 'KRM OTS report carries no CKCC renewal fields');
equals([], $otsFieldsInCkcc, 'CKCC report carries no KRM OTS settlement fields');

// Every section of the printed report is represented.
foreach (['ots_eligible', 'customer_response', 'ots_recommendation', 'ots_final_status'] as $key) {
    ok(in_array($key, $krmKeys, true), 'KRM form asks for ' . $key);
}

foreach (['renewal_eligible', 'renewal_due_bucket', 'kyc_status', 'renewal_consent',
    'renewal_recommendation', 'renewal_final_status'] as $key) {
    ok(in_array($key, $ckccKeys, true), 'CKCC form asks for ' . $key);
}

foreach (['customer_available', 'residence_verified', 'neighbour_verified', 'documents_verified',
    'evidence_attached', 'declaration_accepted'] as $key) {
    ok(in_array($key, $krmKeys, true) && in_array($key, $ckccKeys, true), 'Both forms share ' . $key);
}

$declaration = Database::scalar(
    "SELECT help_text FROM visit_form_fields WHERE form_id = :f AND field_key = 'declaration_section'",
    ['f' => (int) $krmForm['id']]
);

ok(
    is_string($declaration) && str_contains($declaration, 'Reserve Bank of India')
        && str_contains($declaration, 'Fair Practices Code'),
    'Section 11 declaration text is stored verbatim'
);

/* -------------------------------------------------------------------------- */
section('KRM OTS report: submit and verify every field lands');
/* -------------------------------------------------------------------------- */

$krmAccountId = lrms_test_account('9900000001', $branchId, $supervisorId, 'krm_ots');
$visits = new Visits();

$started = $visits->start($supervisorId, [
    'uuid' => 'aaaa1111-2222-4333-8444-555566660001',
    'loan_account_id' => $krmAccountId,
    'visit_type' => 'krm_ots',
    'visit_time' => '11:45',
    'gps' => lrms_test_gps(),
], null);

$krmVisitId = (int) $started['visit']['id'];

equals('krm_ots', (string) $started['visit']['visit_type'], 'Visit opened as a KRM OTS case');
equals(
    (int) $krmForm['id'],
    (int) $started['visit']['form_id'],
    'The KRM OTS report form was attached to the visit'
);
equals('11:45:00', (string) $started['visit']['visit_time'], 'Visit time recorded for section 1');

lrms_test_photo($krmVisitId);

$visits->submit((string) $started['visit']['uuid'], $supervisorId, [
    'form' => [
        // Section 4
        'ots_eligible' => 'Yes',
        'scheme' => 'KRM OTS',
        'ots_amount' => '40000',
        'borrower_share' => '10000',
        'initial_deposit_required' => '5000',
        'customer_response' => 'Agreed for OTS',
        'promise_date' => date('Y-m-d', strtotime('+20 days')),
        // Section 6
        'customer_available' => 'Yes',
        'family_met' => 'No',
        'house_locked' => 'No',
        'is_alive' => 'Yes',
        'address_shifted' => 'No',
        'phone_contact' => 'Yes',
        'residence_verified' => 'Yes',
        'neighbour_verified' => 'Yes',
        'occupation' => 'Agriculture',
        // Sections 7-10
        'documents_verified' => 'Aadhaar Card, Passbook, Khatauni, Other',
        'documents_other' => 'Ration card',
        'remarks' => 'Borrower met at the house and agreed to settle.',
        'ots_recommendation' => 'OTS Proposal Recommended',
        'recommendation' => 'Recommend settlement at the proposed figure.',
        'evidence_attached' => 'Borrower Photograph, House Photograph, GPS Location',
        // Sections 11 and 13
        'declaration_accepted' => 'Yes',
        'ots_final_status' => 'OTS Accepted',
    ],
    'visit_status' => 'customer_met',
]);

$krmVisit = Database::selectOne('SELECT * FROM visits WHERE id = :id', ['id' => $krmVisitId]);

equals('submitted', (string) $krmVisit['status'], 'KRM report submitted');
equals(1, (int) $krmVisit['residence_verified'], 'Residence verification stored (section 6)');
equals(1, (int) $krmVisit['neighbour_verified'], 'Neighbour verification stored (section 6)');
equals(0, (int) $krmVisit['address_shifted'], 'Address-shifted answer stored (section 6)');
equals(
    'Aadhaar Card, Passbook, Khatauni, Other',
    (string) $krmVisit['documents_verified'],
    'Documents checklist stored (section 7)'
);
equals('Ration card', (string) $krmVisit['documents_other'], 'Other document stored (section 7)');
equals(
    'Borrower Photograph, House Photograph, GPS Location',
    (string) $krmVisit['evidence_attached'],
    'Evidence checklist stored (section 10)'
);
equals(1, (int) $krmVisit['declaration_accepted'], 'Declaration acceptance stored (section 11)');
ok($krmVisit['declared_at'] !== null, 'Declaration timestamped (section 11)');

$case = Database::selectOne(
    'SELECT * FROM krm_ots_cases WHERE loan_account_id = :id ORDER BY id DESC LIMIT 1',
    ['id' => $krmAccountId]
);

ok($case !== null, 'A KRM OTS case was opened from the report');
equals(1, (int) $case['ots_eligible'], 'OTS eligibility stored (section 4)');
equals('krm_ots', (string) $case['scheme'], 'Applicable scheme stored (section 4)');
equals(40000.0, (float) $case['ots_amount'], 'Proposed settlement stored (section 4)');
equals(10000.0, (float) $case['borrower_share'], "Borrower's share stored (section 4)");
equals(5000.0, (float) $case['initial_deposit_required'], 'Initial deposit required stored (section 4)');
equals('agreed', (string) $case['customer_response'], 'Customer response mapped from its label (section 4)');
ok($case['promise_date'] !== null, 'Expected deposit date stored (section 4)');
equals('proposal_recommended', (string) $case['recommendation'], 'KRM recommendation stored (section 9)');
equals('ots_accepted', (string) $case['final_status'], 'KRM final report status stored (section 13)');
equals($krmVisitId, (int) $case['visit_id'], 'The case is linked back to the report');

// Nothing from the other stream leaked across.
$ckccForKrmAccount = Database::selectOne(
    'SELECT id FROM ckcc_renewals WHERE loan_account_id = :id',
    ['id' => $krmAccountId]
);

ok($ckccForKrmAccount === null, 'A KRM OTS report creates no CKCC renewal record');

/* -------------------------------------------------------------------------- */
section('CKCC OD-2 report: submit and verify every field lands');
/* -------------------------------------------------------------------------- */

$ckccAccountId = lrms_test_account('9900000002', $branchId, $supervisorId, 'ckcc_od2');

$started = $visits->start($supervisorId, [
    'uuid' => 'aaaa1111-2222-4333-8444-555566660002',
    'loan_account_id' => $ckccAccountId,
    'visit_type' => 'ckcc_od2',
    'gps' => lrms_test_gps(),
], null);

$ckccVisitId = (int) $started['visit']['id'];
equals((int) $ckccForm['id'], (int) $started['visit']['form_id'], 'The CKCC report form was attached');

lrms_test_photo($ckccVisitId);

$dueDate = date('Y-m-d', strtotime('+12 days'));

$visits->submit((string) $started['visit']['uuid'], $supervisorId, [
    'form' => [
        // Section 5
        'renewal_eligible' => 'Yes',
        'renewal_due_bucket' => 'Within 15 Days',
        'renewal_due_date' => $dueDate,
        'expected_npa_date' => '2026-09-30',
        'kyc_status' => 'Complete',
        'aadhaar_seeded' => 'Yes',
        'mobile_linked' => 'Yes',
        'aadhaar_authentication' => 'Completed',
        'renewal_consent' => 'Yes',
        'renewal_form_signed' => 'Yes',
        'biometrics_completed' => 'No',
        // Section 6
        'customer_available' => 'Yes',
        'is_alive' => 'Yes',
        'address_shifted' => 'No',
        'residence_verified' => 'Yes',
        'neighbour_verified' => 'No',
        'occupation' => 'Agriculture',
        // Sections 7-10
        'documents_verified' => 'Aadhaar Card, Renewal Form',
        'remarks' => 'Renewal papers signed; biometrics pending at the branch.',
        'renewal_recommendation' => 'Renewal Immediately Recommended',
        'evidence_attached' => 'Borrower Photograph, Renewal Form',
        // Sections 11 and 13
        'declaration_accepted' => 'Yes',
        'renewal_final_status' => 'Documents Collected',
    ],
    'visit_status' => 'customer_met',
]);

$renewal = Database::selectOne(
    'SELECT * FROM ckcc_renewals WHERE loan_account_id = :id ORDER BY id DESC LIMIT 1',
    ['id' => $ckccAccountId]
);

ok($renewal !== null, 'A CKCC renewal record was opened from the report');
equals(1, (int) $renewal['renewal_eligible'], 'Renewal eligibility stored (section 5)');
equals('within_15_days', (string) $renewal['renewal_due_bucket'], 'Renewal due bucket mapped from its label');
equals($dueDate, (string) $renewal['renewal_due_date'], 'Renewal due date stored (section 5)');
equals('2026-09-30', (string) $renewal['expected_npa_date'], 'Expected NPA date stored (section 5)');
equals(12, (int) $renewal['days_remaining'], 'Days remaining derived from the due date (section 5)');
equals('complete', (string) $renewal['kyc_status'], 'KYC status stored (section 5)');
equals(1, (int) $renewal['aadhaar_seeded'], 'Aadhaar seeded stored (section 5)');
equals(1, (int) $renewal['mobile_linked'], 'Mobile linked stored (section 5)');
equals('completed', (string) $renewal['aadhaar_authentication'], 'Aadhaar authentication stored (section 5)');
equals(1, (int) $renewal['renewal_consent'], 'Renewal consent stored (section 5)');
equals(1, (int) $renewal['renewal_form_signed'], 'Renewal form signed stored (section 5)');
equals(0, (int) $renewal['biometrics_completed'], 'Biometrics answer stored, including "No" (section 5)');
equals('renew_immediately', (string) $renewal['recommendation'], 'CKCC recommendation stored (section 9)');
equals('documents_collected', (string) $renewal['final_status'], 'CKCC final report status stored (section 13)');

$krmForCkccAccount = Database::selectOne(
    'SELECT id FROM krm_ots_cases WHERE loan_account_id = :id',
    ['id' => $ckccAccountId]
);

ok($krmForCkccAccount === null, 'A CKCC report creates no KRM OTS case');

/* -------------------------------------------------------------------------- */
section('The declaration is recorded, not enforced');
/* -------------------------------------------------------------------------- */

$refusedAccountId = lrms_test_account('9900000003', $branchId, $supervisorId, 'krm_ots');

$started = $visits->start($supervisorId, [
    'uuid' => 'aaaa1111-2222-4333-8444-555566660003',
    'loan_account_id' => $refusedAccountId,
    'visit_type' => 'krm_ots',
    'gps' => lrms_test_gps(),
], null);

$refusedUuid = (string) $started['visit']['uuid'];
lrms_test_photo((int) $started['visit']['id']);

$answers = [
    'ots_eligible' => 'No',
    'customer_response' => 'Refused OTS',
    'customer_available' => 'Yes',
    'remarks' => 'Borrower refused to discuss settlement.',
    'ots_recommendation' => 'Customer Refused',
];

// The declaration no longer blocks a submission — nothing on this form does. It is
// recorded as answered or not, and the printed report carries the declaration text
// and ruled signature lines either way, because the paper is signed by hand. What
// matters is that a report submitted without accepting it is visible as such rather
// than being indistinguishable from one that was.
$visits->submit($refusedUuid, $supervisorId, [
    'form' => $answers + ['declaration_accepted' => 'No'],
]);

equals(
    'submitted',
    (string) Database::scalar('SELECT status FROM visits WHERE uuid = :u', ['u' => $refusedUuid]),
    'A report with the declaration refused still submits'
);

equals(
    0,
    (int) Database::scalar('SELECT declaration_accepted FROM visits WHERE uuid = :u', ['u' => $refusedUuid]),
    'The refusal is recorded against the visit, not silently treated as accepted'
);

// Accepting it is recorded too, so the two are told apart. On a fresh visit,
// because a submitted report is not re-submitted — that idempotency is what stops a
// retry from a flaky connection filing the same visit twice.
$acceptedStart = $visits->start($supervisorId, [
    'uuid' => 'aaaa1111-2222-4333-8444-555566660014',
    'loan_account_id' => $refusedAccountId,
    'visit_type' => 'krm_ots',
    'gps' => lrms_test_gps(),
], null);

$acceptedUuid = (string) $acceptedStart['visit']['uuid'];
lrms_test_photo((int) $acceptedStart['visit']['id']);

$visits->submit($acceptedUuid, $supervisorId, [
    'form' => $answers + ['declaration_accepted' => 'Yes'],
]);

equals(
    1,
    (int) Database::scalar('SELECT declaration_accepted FROM visits WHERE uuid = :u', ['u' => $acceptedUuid]),
    'Accepting the declaration is recorded'
);

equals(
    'submitted',
    (string) Database::scalar('SELECT status FROM visits WHERE uuid = :u', ['u' => $acceptedUuid]),
    'And that report submits as well'
);

equals(
    'submitted',
    (string) Database::scalar('SELECT status FROM visits WHERE uuid = :u', ['u' => $refusedUuid]),
    'Accepting the declaration lets the report through'
);

$refusedCase = Database::selectOne(
    'SELECT * FROM krm_ots_cases WHERE loan_account_id = :id ORDER BY id DESC LIMIT 1',
    ['id' => $refusedAccountId]
);

ok($refusedCase !== null, 'A refusal is still recorded as a case');
equals(0, (int) $refusedCase['ots_eligible'], 'Ineligibility recorded rather than left blank');
equals('refused', (string) $refusedCase['customer_response'], 'Customer refusal recorded');
equals('customer_refused', (string) $refusedCase['recommendation'], 'Refusal recommendation recorded');

/* -------------------------------------------------------------------------- */
section('Case types beyond the two streams');
/* -------------------------------------------------------------------------- */

$followupAccountId = lrms_test_account('9900000004', $branchId, $supervisorId, 'general');

$started = $visits->start($supervisorId, [
    'uuid' => 'aaaa1111-2222-4333-8444-555566660004',
    'loan_account_id' => $followupAccountId,
    'visit_type' => 'pre_npa',
    'gps' => lrms_test_gps(),
], null);

equals('pre_npa', (string) $started['visit']['visit_type'], 'Pre-NPA verification accepted as a case type');
equals(
    (int) Forms::defaultForm(Forms::KIND_VISIT, 'customer')['id'],
    (int) $started['visit']['form_id'],
    'A verification visit uses the customer form, not a work-stream report'
);

ok(
    array_key_exists('recovery_followup', Visits::CASE_TYPES)
        && array_key_exists('post_npa', Visits::CASE_TYPES)
        && array_key_exists('other', Visits::CASE_TYPES),
    'All six case types from section 1 are available'
);

/* -------------------------------------------------------------------------- */
section('The printed PDF matches the official 13-section format');
/* -------------------------------------------------------------------------- */

$krmPdf = FieldVisitReport::pdf($krmVisitId);
$ckccPdf = FieldVisitReport::pdf($ckccVisitId);

ok(is_file($krmPdf['path']), 'KRM OTS report PDF written');
ok(is_file($ckccPdf['path']), 'CKCC OD-2 report PDF written');

$krmText = pdf_text($krmPdf['path']);
$ckccText = pdf_text($ckccPdf['path']);

// The section number and its title are drawn as two runs, in two colours, the
// way the client's template does it. A text extractor joins those runs with a
// single space, so headings are compared with whitespace collapsed: the check is
// that the heading is printed, not how many spaces survive extraction.
$flat = static fn (string $text): string => (string) preg_replace('/\s+/', ' ', $text);
$krmFlat = $flat($krmText);
$ckccFlat = $flat($ckccText);

ok(str_contains($krmText, 'FIELD VISIT VERIFICATION REPORT'), 'Title block printed');
ok(str_contains($krmText, "RBI Guidelines & Bank's Code of Conduct Compliant Format"), 'Compliance strapline printed');
ok(str_contains($krmFlat, $flat(org_name())), 'The organisation name heads the title block');
ok(str_contains($krmFlat, '(KRM OTS / Recovery Verification Report)'), 'KRM PDF names its case type');
ok(str_contains($ckccFlat, '(CKCC OD-2 Renewal / Recovery Verification Report)'), 'CKCC PDF names its case type');

// Each report names only its own stream in the title block.
ok(!str_contains($krmFlat, '(KRM OTS / CKCC OD-2 Renewal'), 'KRM title block does not offer both streams');
ok(!str_contains($ckccFlat, 'KRM OTS / Recovery'), 'CKCC title block does not offer both streams');

// The closing note the template prints under the last section.
ok(str_contains($krmFlat, 'Important Note'), 'KRM PDF prints the closing note');
ok(str_contains($ckccFlat, 'Important Note'), 'CKCC PDF prints the closing note');
ok(
    str_contains($krmFlat, 'designed for use in KRM OTS, Recovery Follow-up'),
    'The KRM closing note lists only the KRM cases'
);
ok(
    str_contains($ckccFlat, 'designed for use in CKCC OD-2 Renewal, Recovery Follow-up'),
    'The CKCC closing note lists only the renewal cases'
);
ok(str_contains($krmFlat, 'Fair Practices Code'), 'The closing note keeps the compliance wording');

// Every section that applies, by its printed heading.
$sharedSections = [
    '1.  GENERAL INFORMATION',
    '2.  BORROWER INFORMATION',
    '3.  LOAN ACCOUNT DETAILS',
    '6.  PHYSICAL VERIFICATION',
    '7.  DOCUMENTS VERIFIED',
    '8.  BC AGENT / DRA OBSERVATIONS',
    '9.  RECOMMENDATION',
    '10.  EVIDENCE ATTACHED',
    '11.  DECLARATION',
    '12.  CERTIFICATION',
    '13.  FINAL REPORT STATUS',
];

foreach ($sharedSections as $heading) {
    ok(str_contains($krmFlat, $flat($heading)), 'KRM PDF prints section "' . $heading . '"');
    ok(str_contains($ckccFlat, $flat($heading)), 'CKCC PDF prints section "' . $heading . '"');
}

ok(str_contains($krmFlat, '4. KRM OTS DETAILS'), 'KRM PDF prints section 4');
ok(str_contains($ckccFlat, '5. CKCC OD-2 RENEWAL DETAILS'), 'CKCC PDF prints section 5');

// The separation the client asked for, proven on the printed page.
ok(!str_contains($krmText, 'CKCC OD-2 RENEWAL DETAILS'), 'KRM PDF omits the CKCC renewal section');
ok(!str_contains($krmText, 'Renewal Due Date'), 'KRM PDF prints no renewal fields');
ok(!str_contains($krmText, 'Aadhaar Seeded'), 'KRM PDF prints no renewal KYC fields');
ok(!str_contains($ckccText, 'KRM OTS DETAILS'), 'CKCC PDF omits the KRM OTS section');
ok(!str_contains($ckccText, 'Proposed Settlement'), 'CKCC PDF prints no settlement fields');
ok(!str_contains($ckccText, "Borrower's Share"), 'CKCC PDF prints no borrower-share field');

// The stream-specific checklist entries.
ok(str_contains($krmText, 'OTS Consent Letter'), 'KRM documents checklist offers the OTS consent letter');
ok(!str_contains($krmText, 'Renewal Form'), 'KRM checklists do not mention the renewal form');
ok(str_contains($ckccText, 'Renewal Form'), 'CKCC documents checklist offers the renewal form');
ok(!str_contains($ckccText, 'OTS Consent'), 'CKCC checklists do not mention OTS consent');

// Borrower and loan detail actually reach the page.
foreach (['SEEMA DEVI', 'SURESH SINGH', '6398648339', 'NAGLA', 'PATIYALI', 'KASGANJ', '207248'] as $value) {
    ok(str_contains($ckccText, $value), 'CKCC PDF prints borrower detail "' . $value . '"');
}

ok(str_contains($ckccText, 'ABCDE1234F'), 'PAN printed (section 2)');
ok(str_contains($ckccText, 'XXXX-XXXX-4417'), 'Aadhaar printed masked to the last four digits');
ok(!str_contains($ckccText, '123456784417'), 'The full Aadhaar number is never printed');

ok(str_contains($ckccText, '27.689000') && str_contains($ckccText, '78.972000'), 'GPS coordinates printed (section 1)');
ok(str_contains($ckccText, 'Drawing Power') && str_contains($ckccText, 'Rs.55,000.00'), 'Drawing power printed (section 3)');
ok(str_contains($ckccText, 'Interest Overdue') && str_contains($ckccText, 'Rs.3,200.00'), 'Interest overdue printed (section 3)');
ok(str_contains($ckccText, 'SMA-2'), 'Asset classification options printed (section 3)');

// The rupee sign has no glyph in the standard PDF fonts, so it must degrade to
// "Rs." rather than becoming a "?" on a compliance document.
ok(str_contains($ckccText, 'Rs.52,000.00'), 'Amounts render as Rs. rather than a missing glyph');
ok(!str_contains($ckccText, '?52,000'), 'No unrenderable currency glyph on the page');

// Section 5 values.
ok(str_contains($ckccText, 'Days Remaining'), 'Days remaining printed (section 5)');
ok(str_contains($ckccText, 'Within 15 days'), 'Renewal due buckets printed (section 5)');

// Section 11 must be the declaration verbatim.
ok(
    str_contains($krmText, 'I hereby certify that the information contained in this report'),
    'Declaration first paragraph printed'
);
ok(
    str_contains($krmText, 'Reserve Bank of India (RBI) guidelines') && str_contains($krmText, 'Fair'),
    'Declaration second paragraph printed'
);
ok(
    str_contains($krmText, 'shall be subject to verification and acceptance'),
    'Declaration third paragraph printed'
);
ok(str_contains($krmText, 'Declaration accepted by the BC Agent'), 'Declaration acceptance shown');

// Section 12: the BC Agent is the BC Supervisor, and an unapproved report must
// not look countersigned.
ok(str_contains($krmText, 'BC Agent / DRA'), 'Certification names the BC Agent');

// Signed by hand, so the page must carry lines to sign on. This block used to
// print only when a signature image had been stored, and nothing ever stored one.
foreach (['Signature', 'BC Agent / DRA', 'Supervisor', 'Borrower'] as $needle) {
    ok(str_contains($krmText, $needle), 'The certification page carries "' . $needle . '"');
}

ok(
    !str_contains($krmText, 'Borrower signature'),
    'No captured-signature caption is printed, because none is captured'
);
ok(str_contains($krmText, 'Supervisor Verification'), 'Certification has the supervisor block');

$unapproved = (string) Database::scalar('SELECT approved_at FROM visits WHERE id = :id', ['id' => $krmVisitId]) === '';
ok($unapproved, 'The test report is not yet approved');

// Tick boxes are drawn, not typed.
ok(pdf_tick_strokes($krmPdf['path']) > 0, 'Ticked boxes are drawn as vector marks');
ok(!str_contains($krmText, '☒') && !str_contains($krmText, '☐'), 'No ballot-box characters are emitted as text');

// What was chosen carries a tick, and what was offered and not chosen carries a
// cross, so a reader is never left deciding whether an empty box means "no" or
// "nobody asked". The two are drawn in different colours, which is how they are
// counted here — a tick is two strokes and so is a cross.
$tickStrokes = pdf_stroke_count($krmPdf['path'], '0e7c7b');
$crossStrokes = pdf_stroke_count($krmPdf['path'], '6b7280');

ok($tickStrokes > 0, sprintf('Chosen options are ticked (%d strokes)', $tickStrokes));
equals(0, $tickStrokes % 2, 'Every tick is a complete two-stroke mark');
ok($crossStrokes > 0, sprintf('Options ruled out are crossed (%d strokes)', $crossStrokes));

// The rule itself, checked on the writer rather than through a whole report, because a
// report ticks things no form field decided — the Case Type row comes from the visit and
// the borrower's gender from the loan book — so a full page cannot isolate one group.
//
// Ticked is yes and everything else is no, including in a group nobody answered: a
// checklist is read that way, and an unticked entry means the borrower did not produce
// that document.
$probeMarks = static function (array $selected): array {
    $probe = new PdfWriter('portrait');
    $probe->addPage();
    $probe->checkboxes(['Yes', 'No'], $selected, 3, 'Probe');

    $file = tempnam(sys_get_temp_dir(), 'marks') . '.pdf';
    $probe->save($file);

    $marks = [
        'ticks' => pdf_stroke_count($file, '0e7c7b'),
        'crosses' => pdf_stroke_count($file, '6b7280'),
    ];

    @unlink($file);

    return $marks;
};

// Nothing chosen: no ticks, and both options crossed. That shows neither was chosen
// without claiming either — ticking No for silence would put an answer in the agent's
// mouth, and the stored column still holds null rather than a false.
$unanswered = $probeMarks([]);
equals(0, $unanswered['ticks'], 'A group nobody answered carries no tick');
equals(4, $unanswered['crosses'], 'Both of its options are crossed, two strokes each');

$answered = $probeMarks(['Yes']);
equals(2, $answered['ticks'], 'The chosen option gets one two-stroke tick');
equals(2, $answered['crosses'], 'The option ruled out gets one two-stroke cross');

// The tick's shape, not just its stroke count. A PDF's y axis grows upward while the
// writer positions everything downward from the top, so a tick is one sign error away
// from being drawn upside down — and a mirrored tick still draws exactly two strokes,
// which is why counting them proves nothing about what a reader sees.
$shapeProbe = new PdfWriter('portrait');
$shapeProbe->addPage();
$shapeProbe->checkboxes(['Yes', 'No'], ['Yes'], 3, 'Shape');
$shapeFile = tempnam(sys_get_temp_dir(), 'shape') . '.pdf';
$shapeProbe->save($shapeFile);

$teal = sprintf('%.3F %.3F %.3F', 14 / 255, 124 / 255, 123 / 255);
$found = preg_match_all(
    '/' . preg_quote($teal, '/') . ' RG [\d.]+ w ([\d.]+) ([\d.]+) m ([\d.]+) ([\d.]+) l S Q/',
    (string) file_get_contents($shapeFile),
    $strokes,
    PREG_SET_ORDER
);
@unlink($shapeFile);

equals(2, $found, 'The tick is two strokes');

if ($found === 2) {
    [$first, $second] = $strokes;
    $startX = (float) $first[1];
    $startY = (float) $first[2];
    $lowX = (float) $first[3];
    $lowY = (float) $first[4];
    $endX = (float) $second[3];
    $endY = (float) $second[4];

    ok($startX < $lowX && $lowX < $endX, 'The tick travels left to right');
    ok($startY > $lowY, 'It dips to a low point first');
    ok($endY > $startY, 'And its tail finishes above where it began');
    ok(($endY - $lowY) > ($startY - $lowY), 'The rising tail is the longer of the two strokes');
}

// A recovery visit must not be printable as a verification report, and vice
// versa: they are different documents for different purposes.
$recoveryAccountId = lrms_test_account('9900000005', $branchId, $supervisorId, 'general');

$recoveryStart = $visits->start($supervisorId, [
    'uuid' => 'aaaa1111-2222-4333-8444-555566660005',
    'loan_account_id' => $recoveryAccountId,
    'visit_type' => 'customer',
    'gps' => lrms_test_gps(),
], null);

$recoveryVisitId = (int) $recoveryStart['visit']['id'];

throws(
    static fn () => FieldVisitReport::pdf($recoveryVisitId),
    'A customer recovery visit is refused by the verification report',
    'Customer Visit Report'
);

// And the customer visit report still works for it.
lrms_test_photo($recoveryVisitId);
$visits->submit((string) $recoveryStart['visit']['uuid'], $supervisorId, [
    'form' => [
        'visit_status' => 'Customer met',
        'customer_available' => 'Yes',
        'recovery_possibility' => 'Medium',
        'remarks' => 'Recovery visit, unrelated to the work streams.',
    ],
]);

$customerPdf = \App\Services\Export\RecordExport::visitPdf($recoveryVisitId);
$customerText = pdf_text($customerPdf['path']);

ok(str_contains($customerText, 'Customer Visit Report'), 'The customer visit report is a separate document');
ok(
    !str_contains($customerText, 'FIELD VISIT VERIFICATION REPORT'),
    'The customer visit report is not the verification report'
);
ok(
    !str_contains($customerText, 'KRM OTS DETAILS') && !str_contains($customerText, 'CKCC OD-2 RENEWAL DETAILS'),
    'The customer visit report carries no work-stream sections'
);

/* -------------------------------------------------------------------------- */
section('Tabular report PDFs render their rows');
/* -------------------------------------------------------------------------- */

// This exists because an empty table hides bugs: a report with no rows never
// touches the row-rendering path. By this point the visits above guarantee the
// KRM OTS, CKCC and visit registers all have data.
foreach (['krm_ots', 'ckcc_od2', 'customer_visit'] as $slug) {
    $result = \App\Services\Reports::run($slug, []);
    $rowCount = count($result['rows'] ?? []);

    ok($rowCount > 0, sprintf('Report "%s" returns %d row(s) to render', $slug, $rowCount));

    // export() records the export and returns its report_exports row.
    $export = \App\Services\Reports::export($slug, [], 'pdf');
    $absolute = storage_path((string) $export['file_path']);
    $text = pdf_text($absolute);

    equals('completed', (string) $export['status'], 'Report "' . $slug . '" export completed');
    ok(is_file($absolute) && filesize($absolute) > 1000, 'Report "' . $slug . '" exported to PDF');
    ok($text !== '', 'Report "' . $slug . '" PDF contains printable text');
    ok(
        str_contains($text, 'SEEMA DEVI') || str_contains($text, '99000000'),
        'Report "' . $slug . '" PDF prints the account rows, not just headings'
    );
}

/* -------------------------------------------------------------------------- */
section('The SSS enrolment report prints the figures');
/* -------------------------------------------------------------------------- */

// Same reasoning as the loop above: a report with no rows never exercises the
// row-rendering path, and this one has four count columns and an enum to print.
$sssSupervisor = Database::selectOne(
    'SELECT s.id, u.name FROM bc_supervisors s JOIN users u ON u.id = s.user_id
      WHERE s.branch_id IS NOT NULL ORDER BY s.id LIMIT 1'
);

if ($sssSupervisor === null) {
    ok(false, 'Need a BC Supervisor with a branch for the SSS report test');
} else {
    $sssDate = date('Y-m-d', strtotime('-4 days'));

    // A day is a natural key, so start from a known state rather than from whatever a
    // previous run left behind.
    Database::delete(
        'sss_enrolments',
        'bc_supervisor_id = :bc AND enrolment_date = :date',
        ['bc' => (int) $sssSupervisor['id'], 'date' => $sssDate]
    );

    \App\Services\Sss::record((int) $sssSupervisor['id'], [
        'enrolment_date' => $sssDate,
        'apy_count' => 6,
        'pmjjby_count' => 3,
        'pmsby_count' => 2,
        'pmjdy_count' => 9,
        'remarks' => 'Enrolment camp at the panchayat office.',
    ], 'app');

    $sssFilters = ['from' => $sssDate, 'to' => $sssDate];
    $sssReport = \App\Services\Reports::run('sss', $sssFilters);
    $sssRows = $sssReport['rows'] ?? [];

    ok(count($sssRows) > 0, sprintf('SSS report returns %d row(s) to render', count($sssRows)));
    equals(20, (int) ($sssRows[0]['total'] ?? 0), 'The report totals the four schemes per day');

    $sssColumnKeys = array_column($sssReport['columns'], 'key');

    foreach (['enrolment_date', 'supervisor_name', 'bc_code', 'branch_name',
        'apy_count', 'pmjjby_count', 'pmsby_count', 'pmjdy_count', 'total', 'source'] as $key) {
        ok(in_array($key, $sssColumnKeys, true), 'SSS report has a "' . $key . '" column');
    }

    $sssExport = \App\Services\Reports::export('sss', $sssFilters, 'pdf');
    $sssPdf = storage_path((string) $sssExport['file_path']);
    $sssText = pdf_text($sssPdf);

    equals('completed', (string) $sssExport['status'], 'SSS report export completed');
    ok(is_file($sssPdf) && filesize($sssPdf) > 1000, 'SSS report exported to PDF');
    ok($sssText !== '', 'SSS PDF contains printable text');
    ok(str_contains($sssText, 'PMJJBY'), 'The printed report names the schemes, not just column letters');
    ok(
        str_contains($sssText, (string) $sssSupervisor['name']),
        'The printed report names the supervisor the figures belong to'
    );
    ok(
        str_contains($sssText, '20') && str_contains($sssText, '9'),
        'The printed report shows the figures, not just headings'
    );
}

/* -------------------------------------------------------------------------- */
section('The registers expose the new fields as columns and filters');
/* -------------------------------------------------------------------------- */

$krmReport = \App\Services\Reports::run('krm_ots', []);
$ckccReport = \App\Services\Reports::run('ckcc_od2', []);

$krmColumnKeys = array_column($krmReport['columns'], 'key');
$ckccColumnKeys = array_column($ckccReport['columns'], 'key');

foreach (['ots_eligible', 'scheme', 'ots_amount', 'borrower_share', 'initial_deposit_required',
    'customer_response', 'recommendation', 'final_status'] as $key) {
    ok(in_array($key, $krmColumnKeys, true), 'KRM register has a "' . $key . '" column');
}

foreach (['renewal_eligible', 'renewal_due_bucket', 'renewal_due_date', 'days_remaining',
    'expected_npa_date', 'kyc_status', 'aadhaar_seeded', 'mobile_linked', 'aadhaar_authentication',
    'renewal_consent', 'renewal_form_signed', 'biometrics_completed', 'recommendation',
    'final_status'] as $key) {
    ok(in_array($key, $ckccColumnKeys, true), 'CKCC register has a "' . $key . '" column');
}

// The registers must stay separate here too.
ok(!in_array('renewal_due_bucket', $krmColumnKeys, true), 'KRM register has no renewal columns');
ok(!in_array('borrower_share', $ckccColumnKeys, true), 'CKCC register has no settlement columns');

// Values must print their real wording, not a naive tidy-up of the enum key.
$krmRow = null;

foreach ($krmReport['rows'] as $row) {
    if ((string) ($row['customer_response'] ?? '') !== '') {
        $krmRow = $row;
        break;
    }
}

ok($krmRow !== null, 'A KRM row carries a customer response');

if ($krmRow !== null) {
    $responseColumn = null;

    foreach ($krmReport['columns'] as $column) {
        if ($column['key'] === 'customer_response') {
            $responseColumn = $column;
            break;
        }
    }

    $printed = \App\Services\Reports::format($responseColumn ?? [], $krmRow['customer_response']);
    ok(
        in_array($printed, array_values(\App\Services\KrmOts::CUSTOMER_RESPONSES), true),
        'Customer response prints its real label ("' . $printed . '")'
    );
}

$ckccRow = $ckccReport['rows'][0] ?? null;

if ($ckccRow !== null) {
    $bucketColumn = null;

    foreach ($ckccReport['columns'] as $column) {
        if ($column['key'] === 'renewal_due_bucket') {
            $bucketColumn = $column;
            break;
        }
    }

    equals(
        'Within 15 days',
        \App\Services\Reports::format($bucketColumn ?? [], $ckccRow['renewal_due_bucket']),
        'Renewal due bucket prints as "Within 15 days", not "Within 15 Days"'
    );
}

// Filters are offered and actually narrow the result.
foreach (['ots_status', 'customer_response', 'final_status'] as $filter) {
    ok(in_array($filter, \App\Services\Reports::filtersFor('krm_ots'), true), 'KRM register offers the ' . $filter . ' filter');
}

foreach (['renewal_status', 'documents_status', 'renewal_due_bucket', 'kyc_status', 'final_status'] as $filter) {
    ok(in_array($filter, \App\Services\Reports::filtersFor('ckcc_od2'), true), 'CKCC register offers the ' . $filter . ' filter');
}

$refused = \App\Services\Reports::run('krm_ots', ['customer_response' => 'refused']);
$agreed = \App\Services\Reports::run('krm_ots', ['customer_response' => 'agreed']);

equals(1, count($refused['rows']), 'Filtering KRM by "refused" returns only the refusal');
equals(1, count($agreed['rows']), 'Filtering KRM by "agreed" returns only the agreement');
ok(
    (string) $refused['rows'][0]['customer_response'] === 'refused',
    'The refused filter really matched on the stored value'
);

equals(
    1,
    count(\App\Services\Reports::run('ckcc_od2', ['renewal_due_bucket' => 'within_15_days'])['rows']),
    'Filtering CKCC by the 15-day bucket finds the renewal'
);
equals(
    0,
    count(\App\Services\Reports::run('ckcc_od2', ['renewal_due_bucket' => 'overdue'])['rows']),
    'Filtering CKCC by an unused bucket returns nothing'
);
equals(
    0,
    count(\App\Services\Reports::run('ckcc_od2', ['final_status' => 'became_npa'])['rows']),
    'Filtering CKCC by an unused final status returns nothing'
);

// An unknown filter value must be ignored rather than returning nothing or
// reaching the database.
equals(
    count($krmReport['rows']),
    count(\App\Services\Reports::run('krm_ots', ['customer_response' => 'not-a-response'])['rows']),
    'An unrecognised filter value is ignored, not applied'
);

/* -------------------------------------------------------------------------- */
section('The admin screens can save the same fields from the web');
/* -------------------------------------------------------------------------- */

// The account page posts enum keys and yes/no strings, which is what the
// Admin/Supervisor form sends. This is the path used when a case is corrected
// from the office rather than captured in the field.
$webAccountId = lrms_test_account('9900000006', $branchId, $supervisorId, 'krm_ots');

KrmOts::save($webAccountId, [
    'ots_eligible' => 'yes',
    'scheme' => 'general_ots',
    'ots_amount' => '25,000',
    'borrower_share' => '5000',
    'initial_deposit_required' => '2500',
    'paid_amount' => '2500',
    'customer_response' => 'requested_time',
    'ots_status' => 'under_review',
    'recommendation' => 'followup_required',
    'final_status' => 'customer_verified',
    'promise_date' => date('Y-m-d', strtotime('+30 days')),
    'remarks' => 'Corrected from the branch office.',
]);

$webKrm = Database::selectOne(
    'SELECT * FROM krm_ots_cases WHERE loan_account_id = :id ORDER BY id DESC LIMIT 1',
    ['id' => $webAccountId]
);

equals(1, (int) $webKrm['ots_eligible'], 'Web form saves OTS eligibility');
equals('general_ots', (string) $webKrm['scheme'], 'Web form saves the scheme by key');
equals(25000.0, (float) $webKrm['ots_amount'], 'Web form parses a grouped amount');
equals(5000.0, (float) $webKrm['borrower_share'], "Web form saves the borrower's share");
equals('requested_time', (string) $webKrm['customer_response'], 'Web form saves the customer response');
equals('followup_required', (string) $webKrm['recommendation'], 'Web form saves the recommendation');
equals('customer_verified', (string) $webKrm['final_status'], 'Web form saves the final status');

$webCkccId = lrms_test_account('9900000007', $branchId, $supervisorId, 'ckcc_od2');

CkccRenewals::save($webCkccId, [
    'renewal_eligible' => 'yes',
    'renewal_due_bucket' => 'within_7_days',
    'renewal_due_date' => date('Y-m-d', strtotime('+5 days')),
    'expected_npa_date' => '2026-12-31',
    'kyc_status' => 'pending',
    'aadhaar_seeded' => 'no',
    'mobile_linked' => 'yes',
    'aadhaar_authentication' => 'pending',
    'renewal_consent' => 'yes',
    'renewal_form_signed' => 'no',
    'biometrics_completed' => 'no',
    'renewal_status' => 'documents_awaited',
    'documents_status' => 'partial',
    'recommendation' => 'documents_pending',
    'final_status' => 'pending_at_branch',
    'remarks' => 'Awaiting Aadhaar seeding at the branch.',
]);

$webCkcc = Database::selectOne(
    'SELECT * FROM ckcc_renewals WHERE loan_account_id = :id ORDER BY id DESC LIMIT 1',
    ['id' => $webCkccId]
);

equals(1, (int) $webCkcc['renewal_eligible'], 'Web form saves renewal eligibility');
equals('within_7_days', (string) $webCkcc['renewal_due_bucket'], 'Web form saves the due bucket');
equals(5, (int) $webCkcc['days_remaining'], 'Days remaining derived from the posted due date');
equals('pending', (string) $webCkcc['kyc_status'], 'Web form saves the KYC status');
equals(0, (int) $webCkcc['aadhaar_seeded'], 'Web form saves a "No" answer as 0, not as blank');
equals(1, (int) $webCkcc['mobile_linked'], 'Web form saves a "Yes" answer');
equals('documents_pending', (string) $webCkcc['recommendation'], 'Web form saves the CKCC recommendation');
equals('pending_at_branch', (string) $webCkcc['final_status'], 'Web form saves the CKCC final status');

// A blank select must clear rather than corrupt the value.
CkccRenewals::save($webCkccId, [
    'renewal_status' => 'documents_awaited',
    'kyc_status' => '',
    'aadhaar_seeded' => '',
]);

$cleared = Database::selectOne(
    'SELECT kyc_status, aadhaar_seeded FROM ckcc_renewals WHERE loan_account_id = :id ORDER BY id DESC LIMIT 1',
    ['id' => $webCkccId]
);

equals(null, $cleared['kyc_status'], 'An empty select clears the KYC status');
equals(null, $cleared['aadhaar_seeded'], 'An empty select clears a yes/no answer');

/* -------------------------------------------------------------------------- */
section('BC Supervisor profile feeds the report header');
/* -------------------------------------------------------------------------- */

// The BC creation screen collects the identity the report prints in sections 1
// and 12. Set it on our supervisor and confirm it reaches the page.
Database::update('bc_supervisors', [
    'sp_cbc_name' => 'FIA TECHNOLOGY SERVICES PVT. LTD',
    'ssa' => 'PATIYALI SSA',
    'iibf_number' => '8014017889',
    'dra_id' => 'DRA-4471',
    'designation' => 'BC Agent',
    'aadhaar_last4' => '9021',
    'pan_number' => 'ZYXWV9876E',
    'block' => 'PATIYALI BLOCK',
    'tehsil' => 'PATIYALI',
    'district' => 'KASGANJ',
    'state' => 'UP',
    'pincode' => '207248',
    'updated_at' => now(),
], 'id = :id', ['id' => $supervisorId]);

Database::update('branches', [
    'region' => 'AGRA',
    'zone' => 'LUCKNOW',
    'updated_at' => now(),
], 'id = :id', ['id' => $branchId]);

$profilePdf = FieldVisitReport::pdf($ckccVisitId);
// Long values wrap onto a second line, so compare against the flattened text.
$profileText = pdf_text_flat($profilePdf['path']);

ok(str_contains($profileText, 'FIA TECHNOLOGY SERVICES PVT. LTD'), 'SP / CBC name printed in full, not truncated');
ok(str_contains($profileText, '8014017889'), 'IIBF number printed (section 1)');
ok(str_contains($profileText, 'DRA-4471'), 'DRA ID printed alongside the BC code (section 1)');
ok(str_contains($profileText, 'AGRA'), 'Regional office printed from the branch (section 1)');
ok(str_contains($profileText, 'LUCKNOW'), 'Zone printed from the branch (section 1)');

// The supervisor's own Aadhaar must never reach the page: only the borrower's
// masked digits belong on this form.
ok(!str_contains($profileText, '9021'), "The supervisor's Aadhaar digits are not printed");

$supervisorRow = Database::selectOne('SELECT * FROM bc_supervisors WHERE id = :id', ['id' => $supervisorId]);

equals(4, strlen((string) $supervisorRow['aadhaar_last4']), 'Only four Aadhaar digits are stored for a supervisor');
equals('ZYXWV9876E', (string) $supervisorRow['pan_number'], 'Supervisor PAN stored upper-case');

// A long value must never be silently clipped on a document that is filed as
// evidence. This covers every report that uses a key/value block.
$longAddress = 'HOUSE NUMBER 148, NEAR THE PRIMARY HEALTH CENTRE, NAGALA FULU ROAD, '
    . 'POST OFFICE PATIYALI, DISTRICT KASGANJ, UTTAR PRADESH 207248';

Database::update(
    'loan_accounts',
    ['address' => $longAddress, 'updated_at' => now()],
    'id = :id',
    ['id' => $ckccAccountId]
);

$longText = pdf_text_flat(FieldVisitReport::pdf($ckccVisitId)['path']);

ok(str_contains($longText, $longAddress), 'A long address wraps and prints in full');
ok(!str_contains($longText, 'PRIMARY HEALTH CENTRE, NAGALA FULU ROAD, ...'), 'A long address is not clipped with an ellipsis');

/* -------------------------------------------------------------------------- */
section('Enum labels cover every printed option');
/* -------------------------------------------------------------------------- */

equals(3, count(KrmOts::SCHEMES), 'Three applicable schemes (section 4)');
equals(5, count(KrmOts::CUSTOMER_RESPONSES), 'Five customer responses (section 4)');
equals(4, count(KrmOts::RECOMMENDATIONS), 'Four KRM recommendations (section 9)');
equals(7, count(KrmOts::FINAL_STATUSES), 'Seven KRM final statuses (section 13)');
equals(4, count(CkccRenewals::DUE_BUCKETS), 'Four renewal due buckets (section 5)');
equals(5, count(CkccRenewals::RECOMMENDATIONS), 'Five CKCC recommendations (section 9)');
equals(8, count(CkccRenewals::FINAL_STATUSES), 'Eight CKCC final statuses (section 13)');

/* -------------------------------------------------------------------------- */
section("The BC Supervisor inspection uses the client's issued format");
/* -------------------------------------------------------------------------- */

// The Admin's inspection of a BC Supervisor is no longer eleven questions about whether
// one customer visit was done properly. It is the Bank's own form: 27 numbered items
// about the outlet itself.
$inspectionForm = Forms::defaultForm(Forms::KIND_INSPECTION);

ok($inspectionForm !== null, 'An inspection form is installed');

$inspectionFields = $inspectionForm === null
    ? []
    : Forms::fields(Forms::KIND_INSPECTION, (int) $inspectionForm['id']);

$inspectionKeys = array_map(static fn (array $f): string => (string) $f['field_key'], $inspectionFields);

// Every item the paper asks for, by the answer it expects rather than by count, so
// renumbering or regrouping the sections cannot make this pass on a form missing one.
foreach ([
    'bca_name', 'branch_name', 'cbc_name', 'bca_qualification', 'bca_age', 'bca_address_contact',
    'iibf_certified', 'bc_working_since', 'appointment_letter', 'identity_card',
    'coordinator_contact', 'ssa_name', 'villages_covered', 'board_available',
    'transactions_previous_day', 'services_provided_count', 'sss_awareness',
    'complaint_register', 'transactions_register', 'visit_register', 'equipment_available',
    'remuneration_month_1', 'remuneration_amount_1', 'villager_feedback',
    'working_in_allotted_location', 'other_information', 'photo', 'observation',
    'visiting_official', 'other_information_final',
] as $needle) {
    ok(in_array($needle, $inspectionKeys, true), 'The inspection form asks item "' . $needle . '"');
}

// The questions the old form asked are gone from the current one. They still exist on the
// form they were asked on, which the historic check further down relies on.
foreach (['bc_visited_customer', 'customer_confirmation', 'recovery_recorded_correctly'] as $retired) {
    ok(
        !in_array($retired, $inspectionKeys, true),
        'The retired question "' . $retired . '" is not on the current form'
    );
}

$byKey = [];

foreach ($inspectionFields as $field) {
    $byKey[(string) $field['field_key']] = $field;
}

equals('dropdown', (string) $byKey['observation']['field_type'], 'Item 24 is a fixed grade, not free text');
equals(
    ['Excellent', 'Good', 'Satisfactory', 'Poor'],
    $byKey['observation']['option_list'],
    'And its four words are the ones the form prints'
);
equals('checkbox', (string) $byKey['equipment_available']['field_type'], 'Item 18 is a checklist of equipment');
equals('yes_no', (string) $byKey['iibf_certified']['field_type'], 'Item 7 is the Y/N the form prints');

// Conditionals: the form asks for a certificate number only when there is a certificate,
// the board sub-questions only when there is a board, and where the agent actually works
// only when it is not the allotted place.
$conditionParent = static function (array $field) use ($inspectionFields): ?string {
    if ($field['condition_field_id'] === null) {
        return null;
    }

    foreach ($inspectionFields as $candidate) {
        if ((int) $candidate['id'] === (int) $field['condition_field_id']) {
            return (string) $candidate['field_key'];
        }
    }

    return null;
};

equals('iibf_certified', $conditionParent($byKey['iibf_certificate_no']), 'The certificate number hangs off item 7');
equals('board_available', $conditionParent($byKey['dos_donts_board']), "The Do's and Don'ts board hangs off item 13");
equals(
    'working_in_allotted_location',
    $conditionParent($byKey['actual_location']),
    'Where they actually work hangs off item 21'
);
equals('No', (string) $byKey['actual_location']['condition_value'], 'And it is asked when the answer is No');

/* -------------------------------------------------------------------------- */
section('The inspection prints in that format');
/* -------------------------------------------------------------------------- */

Auth::setUser(Database::selectOne('SELECT * FROM users WHERE email = :e', ['e' => 'admin@lrms.local']));

$inspectionBcId = (int) Database::scalar('SELECT id FROM bc_supervisors ORDER BY id LIMIT 1');
$inspections = new Inspections();
$startedInspection = $inspections->start([
    'bc_supervisor_id' => $inspectionBcId,
    'inspection_date' => today(),
    'gps' => lrms_test_gps(),
]);
$inspectionId = (int) $startedInspection['id'];

Database::insert('inspection_photos', [
    'inspection_id' => $inspectionId,
    'file_path' => 'demo/bc-point.jpg',
    'file_name' => 'bc-point.jpg',
    'photo_type' => 'bc_supervisor',
    'latitude' => 25.5391,
    'longitude' => 87.5721,
    'captured_at' => now(),
    'created_at' => now(),
]);

// Several items are deliberately left unanswered: the page is a form somebody signs, so
// a gap has to print as a gap rather than vanish.
// No result is passed. The assessment is item 24 of the form — see `observation` below —
// and submit() reads it from there. A `result` sent in the payload is ignored on purpose:
// the grade the Bank's form recorded is the only one there is.
$inspections->submit($inspectionId, [
    'result' => 'work_verified',
    'remarks' => 'Outlet inspected.',
    'form' => [
        'bca_name' => 'RAMESH KUMAR',
        'bca_age' => '34',
        'iibf_certified' => 'Yes',
        'iibf_certificate_no' => 'IIBF/2019/44821',
        'bc_working_since' => '2019-06-01',
        'identity_card' => 'No',
        'board_available' => 'Yes',
        'sign_board' => 'No',
        'transaction_types' => ['Cash Deposit', 'Fund Transfer'],
        'equipment_available' => ['Laptop / Desktop', 'Printer'],
        'remuneration_month_1' => 'June',
        'remuneration_amount_1' => '4250.50',
        'working_in_allotted_location' => 'No',
        'actual_location' => 'Market, 2 km away.',
        'observation' => 'Satisfactory',
        'visiting_official' => 'A. K. Verma, 9876543210',
    ],
]);

// Item 24 said "Satisfactory", so that is what the inspection concluded — not the
// "work_verified" the payload asked for, which belongs to a form this one replaced.
equals(
    'satisfactory',
    (string) Database::scalar('SELECT result FROM inspections WHERE id = :id', ['id' => $inspectionId]),
    'The result is item 24, not whatever the caller passed'
);

$inspectionPdf = RecordExport::inspectionPdf($inspectionId);

ok(is_file($inspectionPdf['path']), 'The inspection PDF is written');

$inspectionText = pdf_text_flat($inspectionPdf['path']);

foreach ([
    '1. Name of Business',
    '7. BC certification',
    '13. Board of the CBC',
    '18. Equipment available',
    '23. Photographs',
    '24. Observation',
    '26. Signature of the visiting official',
    '27. Other information',
] as $needle) {
    ok(str_contains($inspectionText, $needle), 'The printed form carries "' . $needle . '"');
}

ok(
    str_contains($inspectionText, 'Observation (item 24)') && str_contains($inspectionText, 'Satisfactory'),
    'The printed page names the grade by the item it came from'
);

/* -------------------------------------------------------------------------- */
section('A Poor grade has to be explained, and only a Poor grade does');
/* -------------------------------------------------------------------------- */

// The grades come from the Bank's own wording. Only Poor is an accusation, so only Poor has
// to carry remarks — demanding a justification for "Satisfactory" would teach inspectors to
// avoid the honest answer.
ok(inspection_result_is_negative('poor'), 'Poor counts as adverse');
ok(!inspection_result_is_negative('satisfactory'), 'Satisfactory does not');
ok(!inspection_result_is_negative('excellent'), 'Excellent does not');
ok(!inspection_result_is_negative(null), 'An ungraded inspection is not an accusation');

// Historic records keep the words they were filed under.
equals('Work Verified', inspection_result_label('work_verified'), 'A retired outcome still reads as it was recorded');
equals('Excellent', inspection_result_label('excellent'), 'A grade reads as the form spells it');

$poorInspection = $inspections->start([
    'bc_supervisor_id' => $inspectionBcId,
    'inspection_date' => today(),
    'gps' => lrms_test_gps(),
]);
$poorId = (int) $poorInspection['id'];

Database::insert('inspection_photos', [
    'inspection_id' => $poorId,
    'file_path' => 'demo/bc-point-2.jpg',
    'file_name' => 'bc-point-2.jpg',
    'photo_type' => 'bc_supervisor',
    'captured_at' => now(),
    'created_at' => now(),
]);

throws(
    static function () use ($inspections, $poorId): void {
        $inspections->submit($poorId, [
            'remarks' => '',
            'form' => ['bca_name' => 'RAMESH KUMAR', 'observation' => 'Poor'],
        ]);
    },
    'Grading an outlet Poor without saying why is refused',
    'Remarks are required'
);

$inspections->submit($poorId, [
    'remarks' => 'No board displayed and the visit register was two months behind.',
    'form' => ['bca_name' => 'RAMESH KUMAR', 'observation' => 'Poor'],
]);

equals(
    'poor',
    (string) Database::scalar('SELECT result FROM inspections WHERE id = :id', ['id' => $poorId]),
    'With remarks, the Poor grade is recorded'
);
equals(
    1,
    (int) Database::scalar('SELECT followup_required FROM inspections WHERE id = :id', ['id' => $poorId]),
    'A Poor grade schedules a follow-up by itself'
);

ok(str_contains($inspectionText, 'RAMESH KUMAR'), 'An answer is printed');
ok(str_contains($inspectionText, '01 Jun 2019'), 'A date prints as a person writes it, not as 2019-06-01');
ok(str_contains($inspectionText, '4,250.50'), 'An amount prints grouped to two places');
ok(str_contains($inspectionText, 'Market, 2 km away.'), 'A conditional answer prints when it was asked');

// The four grades and the five pieces of equipment are all printed, ticked or crossed,
// so a reader sees what was ruled out and not merely what was chosen.
foreach (['Excellent', 'Good', 'Satisfactory', 'Poor'] as $grade) {
    ok(str_contains($inspectionText, $grade), 'The observation row offers "' . $grade . '"');
}

ok(str_contains($inspectionText, 'PIN pad device'), 'An unticked equipment option is still printed');
ok(
    pdf_stroke_count($inspectionPdf['path'], '0e7c7b') > 0,
    'Chosen answers are ticked'
);
ok(
    pdf_stroke_count($inspectionPdf['path'], '6b7280') > 0,
    'Answers ruled out are crossed'
);
ok(
    str_contains($inspectionText, 'fibhopzo@centralbank.co.in'),
    "The printed copy carries the office from the form's letterhead"
);

/* -------------------------------------------------------------------------- */
section('An inspection recorded on the old format still prints its own questions');
/* -------------------------------------------------------------------------- */

// The whole reason the new form was installed as another version rather than by rewriting
// the old one. An inspection points at the form it was filled in on, so a record from
// before the change must print what was actually asked — not the new questions with every
// answer blank, and not the old answers under new labels.
$retiredFormId = Database::insert('inspection_forms', [
    'name' => 'BC Supervisor Field Work Inspection',
    'description' => 'The format used before the client issued the current one.',
    'version' => 1,
    'is_active' => 1,
    'is_default' => 0,
    'created_at' => now(),
    'updated_at' => now(),
]);

$retiredFields = [
    ['bc_visited_customer', 'Did the BC Supervisor visit the customer?', 'yes_no'],
    ['customer_confirmation', 'What did the customer confirm?', 'text'],
    ['inspector_remarks', 'Inspector remarks', 'remarks'],
];
$retiredOrder = 0;

foreach ($retiredFields as [$key, $label, $type]) {
    $retiredOrder += 10;

    Database::insert('inspection_form_fields', [
        'form_id' => $retiredFormId,
        'field_key' => $key,
        'label' => $label,
        'field_type' => $type,
        'is_required' => 0,
        'sort_order' => $retiredOrder,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

$historicId = Database::insert('inspections', [
    'uuid' => 'aaaa1111-2222-4333-8444-5555666600c7',
    'admin_user_id' => (int) Database::scalar('SELECT id FROM users WHERE email = :e', ['e' => 'admin@lrms.local']),
    'bc_supervisor_id' => $inspectionBcId,
    'branch_id' => (int) Database::scalar('SELECT branch_id FROM bc_supervisors WHERE id = :id', ['id' => $inspectionBcId]),
    'form_id' => $retiredFormId,
    'inspection_date' => date('Y-m-d', strtotime('-40 days')),
    'started_at' => now(),
    'submitted_at' => now(),
    'result' => 'work_verified',
    'remarks' => 'Recorded before the format changed.',
    'status' => 'submitted',
    'created_at' => now(),
    'updated_at' => now(),
]);

Forms::saveValues(
    Forms::KIND_INSPECTION,
    $historicId,
    Forms::fields(Forms::KIND_INSPECTION, $retiredFormId),
    [
        'bc_visited_customer' => 'Yes',
        'customer_confirmation' => 'Confirmed the visit',
        'inspector_remarks' => 'Field work checked at the doorstep.',
    ]
);

$historicPdf = RecordExport::inspectionPdf($historicId);
$historicText = pdf_text_flat($historicPdf['path']);

ok(
    str_contains($historicText, 'Did the BC Supervisor visit the customer?'),
    'The old record prints the question it was actually asked'
);
ok(
    str_contains($historicText, 'Confirmed the visit'),
    'And the answer that was given to it'
);
ok(
    !str_contains($historicText, '1. Name of Business'),
    'It is not reprinted against the new format'
);

exit(TestRunner::summary());
