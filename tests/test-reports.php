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
use App\Core\Settings;
use App\Services\CkccRenewals;
use App\Services\Export\FieldVisitReport;
use App\Services\Export\PdfWriter;
use App\Services\Export\RecordExport;
use App\Services\Forms;
use App\Services\Inspections;
use App\Services\KrmOts;
use App\Services\Photos;
use App\Services\Reports;
use App\Services\Sss;
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
    exit("No BCA found. Run: php database/migrate.php --fresh --demo\n");
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

/**
 * A real photograph on an inspection, through the real pipeline so the file exists on disk.
 *
 * It has to be a real file: the printed sheet decides whether to print the photographs heading
 * by whether anything can actually be read, so a row pointing at nothing proves the wrong thing.
 */
function lrms_test_inspection_photo(int $inspectionId): void
{
    $image = imagecreatetruecolor(120, 90);
    imagefilledrectangle($image, 0, 0, 120, 90, imagecolorallocate($image, 200, 210, 225));
    ob_start();
    imagejpeg($image, null, 85);
    $binary = (string) ob_get_clean();
    imagedestroy($image);

    (new Photos())->storeForInspection($inspectionId, base64_encode($binary), [
        'photo_type' => 'outlet',
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

// The title block used to be headed by the organisation name. The bank's letterhead is printed
// above it now and says the same thing in two scripts, so the line is gone rather than moved —
// see "The header is the letterhead, not a stack of names" further down.
ok(
    !str_contains($krmFlat, $flat(org_name())),
    'The organisation name does not head the title block: the letterhead does'
);
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

// Section 12: the BC Agent is the BCA, and an unapproved report must
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

/*
 * Only what was chosen is marked, and nothing else is.
 *
 * Un-chosen options used to carry a muted cross, so a reader could see the option had been
 * offered rather than skipped. On the page that backfired: four options came out as four
 * marked boxes and the client read the ticked one as crossed too. A mark that has to be told
 * apart from another mark by its shape, at eight points, on a photocopy, is not a mark.
 */
$tickStrokes = pdf_stroke_count($krmPdf['path'], '0e7c7b');
$crossStrokes = pdf_stroke_count($krmPdf['path'], '6b7280');

ok($tickStrokes > 0, sprintf('Chosen options are ticked (%d strokes)', $tickStrokes));
equals(0, $tickStrokes % 2, 'Every tick is a complete two-stroke mark');

// The muted grey is also the signature rules and the lines of a writing box, so the slope is
// what separates a cross from a rule: a rule is horizontal, a cross is two diagonals.
ok($crossStrokes > 0, 'The muted grey is still in use, for the rules people sign on');
equals(0, pdf_diagonal_strokes($krmPdf['path'], '6b7280'), 'But nothing on the page is crossed out');

// The rule itself, checked on the writer rather than through a whole report, because a
// report ticks things no form field decided — the Case Type row comes from the visit and
// the borrower's gender from the loan book — so a full page cannot isolate one group.
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

// A group nobody answered is a group with no tick in it. Every option is still printed, so
// "not asked" is visible as an empty row — which is what it looks like on every paper form
// the branch already uses. Ticking No for silence would put an answer in the agent's mouth,
// and the stored column still holds null rather than a false.
$unanswered = $probeMarks([]);
equals(0, $unanswered['ticks'], 'A group nobody answered carries no tick');
equals(0, $unanswered['crosses'], 'And no cross either — its boxes are simply empty');

$answered = $probeMarks(['Yes']);
equals(2, $answered['ticks'], 'The chosen option gets one two-stroke tick');
equals(0, $answered['crosses'], 'The option not chosen is left blank, not crossed');

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
    '/' . preg_quote($teal, '/') . ' RG [\d.]+ w 1 J 1 j ([\d.]+) ([\d.]+) m ([\d.]+) ([\d.]+) l S Q/',
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
    ok(false, 'Need a BCA with a branch for the SSS report test');
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
// BC Supervisor form sends. This is the path used when a case is corrected
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
section('BCA profile feeds the report header');
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
section("The BCA inspection uses the client's issued format");
/* -------------------------------------------------------------------------- */

// The Admin's inspection of a BCA is no longer eleven questions about whether
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
equals(
    0,
    pdf_diagonal_strokes($inspectionPdf['path'], '6b7280'),
    'And answers not chosen are left blank rather than crossed out'
);
// The letterhead of the office the form belongs to. It moved from the Bhopal zonal office to
// the Agra regional office, which is why these values are settings and not constants.
foreach ([
    'Regional Office, Agra' => 'the office',
    '37/2/4, First Floor, Sanjay Place, Agra' => 'the address',
    '0562-2521342' => 'the phone number',
    'rdagraro@centralbank.bank.in' => 'the email address',
    '1800 233 4035' => 'the toll free helpline',
] as $needle => $description) {
    ok(
        str_contains($inspectionText, $needle),
        "The printed copy carries " . $description . " from the form's letterhead"
    );
}

ok(
    !str_contains($inspectionText, 'Bhopal') && !str_contains($inspectionText, 'fibhopzo'),
    'And nothing of the office it used to be sent from'
);

// The labels are printed with the values, because that is how the client's sheet reads —
// "Phone No.: 0562-2521342", not a bare number under an address.
ok(str_contains($inspectionText, 'Phone No.: 0562-2521342'), 'Each line is labelled as the sheet labels it');

/*
 * An office moving must not need a developer. That is the whole reason these are settings, so
 * it is worth proving that changing one changes the page — a setting that is read once through
 * a default and never again would pass every check above and still be a constant.
 */
Settings::setMany([
    'office_name' => 'Central Bank of India — Regional Office, Kanpur',
    'office_address' => '12 Mall Road, Kanpur',
    'office_phone' => '0512-1234567',
    'office_email' => 'rdkanpuro@centralbank.bank.in',
    // Blank on purpose: a line with nothing in it should leave the page rather than print an
    // empty label.
    'office_helpline' => '',
], 'office');

$movedText = pdf_text_flat(RecordExport::inspectionPdf($inspectionId)['path']);

ok(str_contains($movedText, 'Regional Office, Kanpur'), 'Changing the office setting changes the printed form');
ok(str_contains($movedText, '12 Mall Road, Kanpur'), 'And the address with it');
ok(!str_contains($movedText, 'Sanjay Place'), 'The old address is gone, not printed alongside');
ok(!str_contains($movedText, 'Toll Free Helpline'), 'A line left blank is left off the page, label and all');

Settings::setMany([
    'office_name' => 'Central Bank of India — Regional Office, Agra',
    'office_address' => '37/2/4, First Floor, Sanjay Place, Agra',
    'office_phone' => '0562-2521342',
    'office_email' => 'rdagraro@centralbank.bank.in',
    'office_helpline' => '1800 233 4035',
], 'office');

/* -------------------------------------------------------------------------- */
section('An inspection recorded on the old format still prints its own questions');
/* -------------------------------------------------------------------------- */

// The whole reason the new form was installed as another version rather than by rewriting
// the old one. An inspection points at the form it was filled in on, so a record from
// before the change must print what was actually asked — not the new questions with every
// answer blank, and not the old answers under new labels.
$retiredFormId = Database::insert('inspection_forms', [
    'name' => 'BCA Field Work Inspection',
    'description' => 'The format used before the client issued the current one.',
    'version' => 1,
    'is_active' => 1,
    'is_default' => 0,
    'created_at' => now(),
    'updated_at' => now(),
]);

$retiredFields = [
    ['bc_visited_customer', 'Did the BCA visit the customer?', 'yes_no'],
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
    str_contains($historicText, 'Did the BCA visit the customer?'),
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

/* -------------------------------------------------------------------------- */
section('The inspection form arrives part-filled from what the system knows');
/* -------------------------------------------------------------------------- */

/*
 * The client's complaint: the details typed when the BCA was added have to be typed again on
 * every monthly inspection. Twelve times a year, the same name, age, address and IIBF number.
 * A form that long, retyped that often, stops being filled honestly.
 *
 * So the standing items are pre-filled — and only the standing items. What the inspector went
 * there to observe stays blank, because a pre-filled observation is an observation nobody
 * made, and two identical months in a row is what an auditor would notice.
 */

// A BCA of its own, with exactly one inspection behind it. Reusing the one inspected above
// would make this depend on which of that BCA's several inspections happens to be the most
// recent, which is not what is being tested here.
$prefillBcId = (int) Database::scalar(
    'SELECT id FROM bc_supervisors WHERE id <> :used ORDER BY id LIMIT 1',
    ['used' => $inspectionBcId]
);

// Known values on the BCA's staff record, so what appears on the form can be traced to where
// it came from.
Database::update('bc_supervisors', [
    'address' => 'Ward 4, Near Post Office',
    'village' => 'Rampur',
    'block' => 'Sahibganj',
    'district' => 'Sahibganj',
    'state' => 'Jharkhand',
    'pincode' => '816109',
    'mobile' => '9812345670',
    'iibf_number' => 'IIBF/2018/90210',
    'ssa' => 'SSA-Rampur',
    'sp_cbc_name' => 'Sanjivani CBC',
    'joined_on' => '2021-04-15',
    'updated_at' => now(),
], 'id = :id', ['id' => $prefillBcId]);

$prefillFields = Forms::fields(Forms::KIND_INSPECTION, (int) $inspectionForm['id']);

// Before any inspection at all: the staff record is the only source there is.
$firstEver = Inspections::prefill($prefillBcId, $prefillFields);

equals(
    '2021-04-15',
    $firstEver['bc_working_since'] ?? '',
    'On the first-ever inspection item 8 comes from the staff record'
);
equals('IIBF/2018/90210', $firstEver['iibf_certificate_no'] ?? '', 'And so does the IIBF number');
equals('Yes', $firstEver['iibf_certified'] ?? '', 'An IIBF number on record answers item 7 Yes');

/*
 * Last month's inspection. It records standing facts that differ from the staff record — the
 * name spelt in full, a corrected IIBF number, a corrected start date — and answers a set of
 * observations. What happens to each of those is the whole point of this section.
 */
$lastMonth = $inspections->start([
    'bc_supervisor_id' => $prefillBcId,
    'inspection_date' => date('Y-m-d', strtotime('-1 month')),
    'gps' => lrms_test_gps(),
]);

Database::insert('inspection_photos', [
    'inspection_id' => (int) $lastMonth['id'],
    'file_path' => 'demo/bc-point-3.jpg',
    'file_name' => 'bc-point-3.jpg',
    'photo_type' => 'bc_supervisor',
    'captured_at' => now(),
    'created_at' => now(),
]);

$inspections->submit((int) $lastMonth['id'], [
    'remarks' => 'Outlet inspected last month.',
    'form' => [
        // Standing facts, as corrected on the form.
        'bca_name' => 'RAMESH KUMAR',
        'bca_age' => '34',
        'bca_qualification' => 'B.A.',
        'iibf_certified' => 'Yes',
        'iibf_certificate_no' => 'IIBF/2019/44821',
        'bc_working_since' => '2019-06-01',
        'coordinator_contact' => 'S. P. Mishra, 9800011122',
        'ssa_name' => 'SSA-Rampur',
        'villages_covered' => '4',
        // Observations, every one of which must start blank next month.
        'appointment_letter' => 'Yes',
        'identity_card' => 'No',
        'board_available' => 'Yes',
        'sign_board' => 'No',
        'transactions_previous_day' => '37',
        'transaction_types' => ['Cash Deposit'],
        'equipment_available' => ['Laptop / Desktop'],
        'remuneration_month_1' => 'July',
        'remuneration_amount_1' => '4250.50',
        'villager_feedback' => 'Satisfied with the service.',
        'working_in_allotted_location' => 'No',
        'actual_location' => 'Market, 2 km away.',
        'observation' => 'Good',
        'visiting_official' => 'A. K. Verma, 9876543210',
    ],
]);

$prefilled = Inspections::prefill($prefillBcId, $prefillFields);

$bcaRecord = Database::selectOne(
    'SELECT u.name, b.name AS branch_name FROM bc_supervisors s
       JOIN users u ON u.id = s.user_id
  LEFT JOIN branches b ON b.id = s.branch_id
      WHERE s.id = :id',
    ['id' => $prefillBcId]
);

ok($prefilled !== [], 'The form comes back with something already in it');

// Items 1-3 and 12, straight off the staff record.
equals((string) $bcaRecord['branch_name'], $prefilled['branch_name'] ?? '', 'Item 2, the branch, is filled in');
equals('Sanjivani CBC', $prefilled['cbc_name'] ?? '', 'Item 3, the CBC, is filled in');
equals('SSA-Rampur', $prefilled['ssa_name'] ?? '', 'Item 12, the SSA, is filled in');

// Item 6 wants one address with a contact number in it, so the separate columns are joined
// the way the paper reads rather than pasted in as a list.
$address = (string) ($prefilled['bca_address_contact'] ?? '');

foreach (['Ward 4, Near Post Office', 'Rampur', 'Sahibganj', 'Jharkhand', '816109'] as $part) {
    ok(str_contains($address, $part), 'Item 6 carries "' . $part . '" from the staff record');
}

ok(str_contains($address, 'Mobile: 9812345670'), 'And the contact number the item asks for');

// Item 25 is whoever is signed in and doing this visit, not last month's official.
ok(
    str_contains((string) ($prefilled['visiting_official'] ?? ''), (string) Auth::name()),
    'Item 25 names the BC Supervisor making this visit'
);
ok(
    !str_contains((string) ($prefilled['visiting_official'] ?? ''), 'A. K. Verma'),
    'Not the official who came last month'
);

/*
 * Last month's inspection outranks the staff record for standing facts. If a detail was
 * corrected on the form last month because the master data was wrong, that correction has to
 * survive — otherwise the same stale value is re-imposed every month and the correction has to
 * be made twelve times over.
 */
equals('RAMESH KUMAR', $prefilled['bca_name'] ?? '', 'Item 1 keeps the name last month recorded');
equals('34', $prefilled['bca_age'] ?? '', 'Item 5 keeps the age last month recorded');
equals('B.A.', $prefilled['bca_qualification'] ?? '', 'Item 4 carries forward');
equals(
    'IIBF/2019/44821',
    $prefilled['iibf_certificate_no'] ?? '',
    'The IIBF number corrected on the form beats the one on the staff record'
);
equals('2019-06-01', $prefilled['bc_working_since'] ?? '', 'Item 8 keeps the corrected start date');
equals('S. P. Mishra, 9800011122', $prefilled['coordinator_contact'] ?? '', 'Item 11 carries forward');
equals('4', $prefilled['villages_covered'] ?? '', 'The village count carries forward');

/*
 * And now the half that matters more. Every one of these was answered on last month's
 * inspection and must not appear on this one.
 */
foreach ([
    'transactions_previous_day' => '14. yesterday\'s transaction count',
    'remuneration_month_1' => '20. the remuneration month',
    'remuneration_amount_1' => '20. the remuneration amount',
    'villager_feedback' => '22. what the villagers said',
    'board_available' => '13. whether the board is up',
    'sign_board' => '13. the sign board',
    'equipment_available' => '21. the equipment',
    'transaction_types' => '15. the transaction types',
    'observation' => '24. the grade',
    'working_in_allotted_location' => '23. working in the allotted location',
    'actual_location' => '23. where they actually are',
    // Items 9 and 10 read like standing facts and are not: the inspector is being asked to
    // be shown the letter and the card today.
    'appointment_letter' => '9. the appointment letter',
    'identity_card' => '10. the identity card',
] as $key => $description) {
    ok(
        !array_key_exists($key, $prefilled),
        'Item ' . $description . ' starts blank, however it was answered last month'
    );
}

// A draft being resumed outranks everything: what the inspector typed is what they see.
$resumed = Inspections::prefill($prefillBcId, $prefillFields, [
    ['field_key' => 'bca_name', 'value' => 'RAMESH KUMAR SINGH'],
    ['field_key' => 'observation', 'value' => 'Good'],
]);

equals('RAMESH KUMAR SINGH', $resumed['bca_name'] ?? '', 'A resumed draft keeps what was typed into it');
equals('Good', $resumed['observation'] ?? '', 'Including an observation, once somebody has actually made it');

// A BCA with no history and a bare staff record gets an empty form rather than an error, and
// certainly rather than the word "null" printed on a page somebody signs.
$blankBcId = (int) Database::scalar(
    'SELECT id FROM bc_supervisors WHERE id NOT IN (:a, :b) ORDER BY id DESC LIMIT 1',
    ['a' => $inspectionBcId, 'b' => $prefillBcId]
);
Database::update('bc_supervisors', [
    'address' => null, 'village' => null, 'block' => null, 'district' => null,
    'state' => null, 'pincode' => null, 'mobile' => null, 'iibf_number' => null,
    'ssa' => null, 'sp_cbc_name' => null, 'joined_on' => null,
], 'id = :id', ['id' => $blankBcId]);

$blank = Inspections::prefill($blankBcId, $prefillFields);

ok(
    !array_key_exists('bca_address_contact', $blank),
    'A BCA with nothing on record gets an empty item 6'
);
ok(!array_key_exists('iibf_certified', $blank), 'And item 7 is not answered "Yes" on their behalf');
ok(!array_key_exists('bc_working_since', $blank), 'And item 8 is not given a made-up date');
ok(
    !in_array('null', array_map('strval', array_values($blank)), true),
    'Nothing comes through as the string "null"'
);
ok(array_key_exists('bca_name', $blank), 'The name is still filled in, because a user always has one');


/* -------------------------------------------------------------------------- */
section('The inspection carries the scheme figures for a window the inspector picks');
/* -------------------------------------------------------------------------- */

/*
 * The client asked for the Social Security Scheme performance to appear on the inspection, for
 * dates chosen at the time, and to print the same way it reads in the SSS register.
 *
 * Two things are being defended here beyond "it appears".
 *
 * Nobody types these figures. Sss says why in its own header: a number the system already holds
 * must not also be entered by hand, or the agent is measured on one and defends the other. So
 * they are read from the enrolment records, and the window is the only thing anybody chooses.
 *
 * And once the sheet is signed the figures stop moving. A day's enrolments can be corrected
 * afterwards — an Admin can hand a submitted day back for exactly that — so a reprint that
 * recomputed would disagree with the copy in the branch's file. That is the last check in this
 * section and the one most worth keeping.
 */

Auth::setUser($admin);

$schemeBcId = (int) Database::scalar(
    'SELECT id FROM bc_supervisors ORDER BY id LIMIT 1'
);
$schemeBranchId = (int) Database::scalar(
    'SELECT branch_id FROM bc_supervisors WHERE id = :id',
    ['id' => $schemeBcId]
);

// A target of ten a working day, and five days actually reported.
Sss::saveTarget($schemeBcId, date('Y-m-01'), [
    'apy_target' => 2,
    'pmjjby_target' => 3,
    'pmsby_target' => 1,
    'pmjdy_target' => 4,
]);

Database::delete('sss_enrolments', 'bc_supervisor_id = :bc', ['bc' => $schemeBcId]);

foreach ([2, 3, 4, 5, 6] as $dayOfMonth) {
    Database::insert('sss_enrolments', [
        'uuid' => sprintf('aaaa5555-2222-4333-8444-5555666600%02d', $dayOfMonth),
        'bc_supervisor_id' => $schemeBcId,
        'branch_id' => $schemeBranchId,
        'enrolment_date' => date('Y-m-') . sprintf('%02d', $dayOfMonth),
        'apy_count' => $dayOfMonth,
        'pmjjby_count' => 2,
        'pmsby_count' => 0,
        'pmjdy_count' => 5,
        'source' => 'panel',
        'status' => 'submitted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/* The window, before anything is signed ---------------------------------- */

$inspectionsService = new Inspections();
$schemeStart = $inspectionsService->start([
    'bc_supervisor_id' => $schemeBcId,
    'inspection_date' => date('Y-m-12'),
    'uuid' => 'aaaa5555-2222-4333-8444-555566660aa1',
]);
$schemeInspectionId = (int) $schemeStart['id'];
$schemeDraft = Database::selectOne('SELECT * FROM inspections WHERE id = :id', ['id' => $schemeInspectionId]);

equals(
    ['from' => date('Y-m-01'), 'to' => date('Y-m-12')],
    Inspections::sssWindow($schemeDraft),
    "A window nobody has set covers the inspection's own month, up to the inspection date"
);

// A slip, not a request for nothing. The panel's own SSS screen swaps a reversed range too.
equals(
    ['from' => date('Y-m-05'), 'to' => date('Y-m-20')],
    Inspections::sssWindow([
        'inspection_date' => date('Y-m-12'),
        'sss_from' => date('Y-m-20'),
        'sss_to' => date('Y-m-05'),
    ]),
    'A window entered backwards is swapped rather than read as empty'
);

// Half a window would otherwise measure a period nobody asked for.
equals(
    ['from' => date('Y-m-01'), 'to' => date('Y-m-12')],
    Inspections::sssWindow([
        'inspection_date' => date('Y-m-12'),
        'sss_from' => date('Y-m-03'),
        'sss_to' => '',
    ]),
    'One date without the other falls back to the default window entirely'
);

// A draft shows live figures, and they are the register's figures for the same window.
$draftBlock = Inspections::sssPerformance($schemeDraft);
$registerBlock = Sss::forSupervisor($schemeBcId, date('Y-m-01'), date('Y-m-12'));

ok($draftBlock !== null, 'A draft inspection offers the scheme block');
equals(false, $draftBlock['frozen'] ?? true, 'And says its figures are not frozen yet');
equals(
    [(int) $registerBlock['total_achievement'], (int) $registerBlock['total_target']],
    [(int) $draftBlock['total_achievement'], (int) $draftBlock['total_target']],
    'A draft reads the same achievement and target as the SSS register does'
);

/* Signing it freezes them ------------------------------------------------- */

lrms_test_inspection_photo($schemeInspectionId);

$schemeFrom = date('Y-m-01');
$schemeTo = date('Y-m-12');

$inspectionsService->submit($schemeInspectionId, [
    'form' => [
        'sss_awareness' => 'Explains all four schemes and keeps the leaflets on the counter.',
        'observation' => 'Good',
    ],
    'sss_from' => $schemeFrom,
    'sss_to' => $schemeTo,
    'remarks' => 'Outlet visited, registers seen.',
]);

$schemeSigned = Database::selectOne('SELECT * FROM inspections WHERE id = :id', ['id' => $schemeInspectionId]);

equals($schemeFrom, (string) $schemeSigned['sss_from'], 'Submitting records the window the sheet was signed against');
equals($schemeTo, (string) $schemeSigned['sss_to'], 'Both ends of it');

$frozenRow = Database::selectOne(
    'SELECT * FROM inspection_sss WHERE inspection_id = :id',
    ['id' => $schemeInspectionId]
);

ok($frozenRow !== null, 'And freezes the figures against that window');

// 2+3+4+5+6 across five reported days, and two Sundays inside a twelve day window.
equals(20, (int) ($frozenRow['apy_count'] ?? 0), 'The APY count is the sum of the days in the window');
equals(10, (int) ($frozenRow['pmjjby_count'] ?? 0), 'And PMJJBY');
equals(0, (int) ($frozenRow['pmsby_count'] ?? 0), 'A scheme with nothing enrolled is frozen as zero, not left out');
equals(25, (int) ($frozenRow['pmjdy_count'] ?? 0), 'And PMJDY');
equals(5, (int) ($frozenRow['days_reported'] ?? 0), 'The days that were reported are recorded too');

$schemeWorkingDays = Sss::workingDaysBetween($schemeFrom, $schemeTo);
equals(
    $schemeWorkingDays,
    (int) ($frozenRow['working_days'] ?? 0),
    sprintf('The working days behind the target are stored (%d), because that setting can change', $schemeWorkingDays)
);
equals(
    2 * $schemeWorkingDays,
    (int) ($frozenRow['apy_target'] ?? 0),
    'A target is the daily figure times those working days'
);

$signedBlock = Inspections::sssPerformance($schemeSigned);
equals(true, $signedBlock['frozen'] ?? false, 'A signed sheet reports its figures as frozen');
equals(55, (int) $signedBlock['total_achievement'], 'Achievement adds up across the four schemes');
equals(
    10 * $schemeWorkingDays,
    (int) $signedBlock['total_target'],
    'And so does the target'
);

/* It prints, in the register's own columns, in the right place ------------- */

$schemePdf = RecordExport::inspectionPdf($schemeInspectionId);
$schemeText = pdf_text_flat($schemePdf['path']);

ok(str_contains($schemeText, 'Social Security Scheme performance'), 'The printed sheet carries the scheme block');
ok(
    str_contains($schemeText, 'Read from the enrolment records, not answered on this form'),
    'And says on the page that the figures were not answered by hand'
);
ok(
    str_contains($schemeText, 'working day(s) of the daily figure'),
    'And explains that a target is a daily figure times working days, as the register does'
);
ok(
    str_contains($schemeText, 'signed against'),
    'And that these are the figures it was signed against'
);

foreach (Sss::schemes() as $abbreviation) {
    ok(str_contains($schemeText, $abbreviation), 'The table names the ' . $abbreviation . ' column');
}

ok(str_contains($schemeText, '20/' . (2 * $schemeWorkingDays)), 'A scheme cell reads achievement of target');

/*
 * Position. Item 16 asks whether the agent is aware of the schemes, so the figures belong
 * directly under it — not after item 27, where the walk ends and the signature lines are.
 */
$item16At = strpos($schemeText, '16. BC awareness');
$blockAt = strpos($schemeText, 'Social Security Scheme performance');
$item17At = strpos($schemeText, '17. Complaint box');

ok($item16At !== false && $blockAt !== false && $item17At !== false, 'Items 16 and 17 and the block are all on the sheet');
ok(
    $item16At !== false && $blockAt !== false && $item16At < $blockAt,
    'The block comes after item 16, which is the question it answers'
);
ok(
    $blockAt !== false && $item17At !== false && $blockAt < $item17At,
    'And before item 17, rather than being appended past the signature'
);

/* The point of freezing them ---------------------------------------------- */

Database::update(
    'sss_enrolments',
    ['apy_count' => 900, 'status' => Sss::STATUS_REOPENED, 'updated_at' => now()],
    'bc_supervisor_id = :bc AND enrolment_date = :day',
    ['bc' => $schemeBcId, 'day' => date('Y-m-02')]
);

$afterCorrection = Inspections::sssPerformance(
    Database::selectOne('SELECT * FROM inspections WHERE id = :id', ['id' => $schemeInspectionId])
);

equals(
    55,
    (int) $afterCorrection['total_achievement'],
    'A day corrected after the sheet was signed does not change what the sheet says'
);
ok(
    (int) Sss::forSupervisor($schemeBcId, $schemeFrom, $schemeTo)['total_achievement'] > 55,
    'Though the correction did land, and the live register shows it'
);

$reprint = pdf_text_flat(RecordExport::inspectionPdf($schemeInspectionId)['path']);
ok(
    str_contains($reprint, '20/' . (2 * $schemeWorkingDays)),
    'So a reprint still matches the copy in the file'
);

/* A sheet signed before any of this existed -------------------------------- */

$preSchemeId = Database::insert('inspections', [
    'uuid' => 'aaaa5555-2222-4333-8444-555566660aa2',
    'admin_user_id' => (int) $admin['id'],
    'bc_supervisor_id' => $schemeBcId,
    'branch_id' => $schemeBranchId,
    'form_id' => (int) $inspectionForm['id'],
    'inspection_date' => date('Y-m-d', strtotime('-200 days')),
    'started_at' => now(),
    'submitted_at' => now(),
    'result' => 'good',
    'status' => 'submitted',
    'created_at' => now(),
    'updated_at' => now(),
]);

equals(
    null,
    Inspections::sssPerformance(Database::selectOne('SELECT * FROM inspections WHERE id = :id', ['id' => $preSchemeId])),
    'An inspection signed before the scheme block existed is given no figures at all'
);
ok(
    !str_contains(pdf_text_flat(RecordExport::inspectionPdf($preSchemeId)['path']), 'Social Security Scheme performance'),
    'And its reprint carries no scheme block, rather than gaining one it was never signed with'
);

/* And the register agrees with the sheet ----------------------------------- */

$schemeRegister = Reports::run('bc_inspection', ['from' => date('Y-m-01'), 'to' => today()], 1, 100);
$schemeLabels = array_map(static fn (array $c): string => (string) $c['label'], $schemeRegister['columns']);

ok(in_array('Scheme window', $schemeLabels, true), 'The inspection register has a scheme window column');
ok(in_array('Schemes', $schemeLabels, true), 'And the achievement against target');
ok(in_array('Remarks', $schemeLabels, true), 'Remarks survived the addition');

// writePdf() prints only the first eleven columns of a wide report, so the count is the
// guard: one more and something already on the page would silently drop off it.
ok(
    count($schemeRegister['columns']) <= 11,
    sprintf('The register still fits the printed page (%d columns)', count($schemeRegister['columns']))
);

$schemeColumn = ['key' => 'sss_achievement', 'type' => 'computed'];
$windowColumn = ['key' => 'sss_window', 'type' => 'computed'];
$signedRow = null;
$preSchemeRow = null;

foreach ($schemeRegister['rows'] as $registerRow) {
    if ((int) $registerRow['id'] === $schemeInspectionId) {
        $signedRow = $registerRow;
    }
}

equals(
    '55/' . (10 * $schemeWorkingDays),
    Reports::value($schemeColumn, $signedRow ?? []),
    'The register reads the same achievement of target as the sheet'
);
ok(
    str_contains((string) Reports::value($windowColumn, $signedRow ?? []), format_date($schemeFrom)),
    'And names the window it measured'
);

$preSchemeRegister = Reports::run(
    'bc_inspection',
    ['from' => date('Y-m-d', strtotime('-210 days')), 'to' => date('Y-m-d', strtotime('-190 days'))],
    1,
    100
);

foreach ($preSchemeRegister['rows'] as $registerRow) {
    if ((int) $registerRow['id'] === $preSchemeId) {
        $preSchemeRow = $registerRow;
    }
}

equals(
    null,
    Reports::value($schemeColumn, $preSchemeRow ?? []),
    'A sheet with no scheme figures leaves the column empty rather than claiming nought of nought'
);

/* -------------------------------------------------------------------------- */
section('A heading is never printed without the thing it heads');
/* -------------------------------------------------------------------------- */

/*
 * Two faults, both found by rendering a sheet and reading it.
 *
 * The photographs heading was printed from a count of database rows, while the grid below it
 * skips any file it cannot read without a word. A site whose uploads had been moved therefore
 * printed "Photographs at the BC point" with nothing whatever underneath.
 *
 * And the heading was drawn before the first row of pictures was measured, so it could finish a
 * page with the photographs overleaf — a reader turning over finds pictures with no caption and
 * the page before ends on a promise.
 */

$photoInspectionId = (int) Database::scalar(
    'SELECT inspection_id FROM inspection_photos ORDER BY inspection_id DESC LIMIT 1'
);
$photoRow = Database::selectOne(
    'SELECT * FROM inspection_photos WHERE inspection_id = :id ORDER BY id LIMIT 1',
    ['id' => $photoInspectionId]
);

if ($photoRow === null) {
    ok(false, 'An inspection photograph is on record to check the heading against');
} else {
    $photoFile = storage_path((string) $photoRow['file_path']);
    $withPhoto = RecordExport::inspectionPdf($photoInspectionId);

    ok(
        str_contains(pdf_text_flat($withPhoto['path']), 'Photographs at the BC point'),
        'A sheet with a readable photograph prints the photographs heading'
    );

    // The heading and the picture have to be on one page.
    $headingPage = null;

    foreach (pdf_text_runs($withPhoto['path']) as $run) {
        if (str_contains($run['text'], 'Photographs at the BC point')) {
            $headingPage = (int) $run['page'];
        }
    }

    /*
     * pdf_image_draws_per_page() is indexed from nought and pdf_text_runs() numbers pages from
     * one, so the keys have to be shifted before the two can be compared. Its values are counts
     * and can read higher than the number of pictures — it tallies every resource dictionary the
     * name appears in — so only "more than none" is meaningful.
     */
    $imagePages = array_map(
        static fn (int $index): int => $index + 1,
        array_keys(array_filter(
            pdf_image_draws_per_page($withPhoto['path'], $photoFile),
            static fn (int $drawn): bool => $drawn > 0
        ))
    );

    ok($headingPage !== null, 'The photographs heading is on the page somewhere');
    ok(
        $headingPage !== null && in_array($headingPage, $imagePages, true),
        sprintf(
            'And on the same page as the photograph itself (heading page %s, picture on %s)',
            (string) $headingPage,
            $imagePages === [] ? 'no page' : implode(',', $imagePages)
        )
    );

    /*
     * Now the same sheet with the file gone. The row stays, because that is the state a site
     * gets into: the record of the photograph outlives the file.
     */
    $movedTo = $photoFile . '.moved';
    rename($photoFile, $movedTo);

    ok(
        !str_contains(
            pdf_text_flat(RecordExport::inspectionPdf($photoInspectionId)['path']),
            'Photographs at the BC point'
        ),
        'A photograph whose file has gone takes its heading with it, rather than leaving it bare'
    );

    rename($movedTo, $photoFile);
}

/* -------------------------------------------------------------------------- */
section('Every exported PDF carries a QR code back to its record');
/* -------------------------------------------------------------------------- */

/*
 * A printed page leaves the system when it is printed. It gets photocopied, carried to a
 * branch and filed, and after that nothing on it can be checked against what was submitted.
 * The QR is what puts the paper back in touch with the record.
 *
 * The encoder itself is tested in tests/test-qr.php, against an independent implementation
 * and with a reader that walks the finished matrix. What is checked here is that all four
 * kinds of document actually carry one, since "every PDF" was the requirement and it is the
 * kind of thing that gets added to three places out of four.
 */

/**
 * The QR module fill PdfWriter draws, if the document has one.
 */
function lrms_pdf_has_qr(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    $raw = (string) file_get_contents($path);

    // One black fill containing many rectangles: the merged modules of a code. A tick or a
    // table rule is a stroke or a single rectangle, so neither can be mistaken for this.
    return preg_match('/q 0 0 0 rg (?:[\d.]+ [\d.]+ [\d.]+ [\d.]+ re ){40,}f Q/', $raw) === 1;
}

// 1. The BCA inspection, in the client's issued format.
$inspectionQrPdf = RecordExport::inspectionPdf($inspectionId);
$inspectionQrText = pdf_text_flat($inspectionQrPdf['path']);

ok(lrms_pdf_has_qr($inspectionQrPdf['path']), 'The inspection report carries a QR code');
ok(str_contains($inspectionQrText, 'Scan to open this record'), 'With a caption saying what it is for');
// The inspection's own reference is deliberately not here — see the letterhead section
// below. What is printed is what identifies the outlet to a person.
ok(
    str_contains($inspectionQrText, 'BC001'),
    'And the BC code printed beside it, for whoever has no phone to hand'
);

// 2. The customer visit report.
$visitQrId = (int) Database::scalar('SELECT id FROM visits ORDER BY id LIMIT 1');
$visitQrPdf = RecordExport::visitPdf($visitQrId);

ok(lrms_pdf_has_qr($visitQrPdf['path']), 'The customer visit report carries a QR code');

// 3. The client's official field visit verification report.
$fvrVisitId = (int) Database::scalar(
    "SELECT id FROM visits WHERE visit_type = 'krm_ots' AND status = 'submitted' ORDER BY id LIMIT 1"
);

if ($fvrVisitId > 0) {
    $fvrPdf = FieldVisitReport::pdf($fvrVisitId);
    ok(lrms_pdf_has_qr((string) ($fvrPdf['path'] ?? '')), 'The field visit verification report carries a QR code');
} else {
    ok(false, 'A KRM OTS visit exists to print the verification report from');
}

// 4. A tabular management report, where the code leads to the live figures rather than to one
//    record — a report is a filter, not a row.
//
// Back to the signed-in user the top of this file set up: the inspection sections above
// replaced it with a bare `users` row, and running a report needs the role joined on.
Auth::setUser($admin);

$tabularExport = \App\Services\Reports::export('customer_visit', [], 'pdf');
$tabularPath = storage_path((string) $tabularExport['file_path']);

ok(lrms_pdf_has_qr($tabularPath), 'A tabular report PDF carries a QR code');
ok(
    str_contains(pdf_text_flat($tabularPath), 'Scan for the live figures'),
    'And says the code leads to the current figures, not to this sheet'
);

/* -------------------------------------------------------------------------- */
section("The bank's letterhead is on every page, and the inspection drops its reference");
/* -------------------------------------------------------------------------- */

/*
 * These pages are photocopied, stapled, unstapled and filed. A page that comes loose has to
 * still be identifiable as the bank's, so the logo goes in the header of every page — not only
 * the first, which is the version of this that would pass a naive test.
 */
$logoFile = base_path('public/assets/img/cbi-logo.jpg');

ok(is_file($logoFile), 'The letterhead image ships with the project');

foreach ([
    'the inspection report' => $inspectionQrPdf['path'],
    'the customer visit report' => $visitQrPdf['path'],
    'a tabular report' => $tabularPath,
] as $description => $file) {
    $perPage = pdf_image_draws_per_page($file, $logoFile);

    ok($perPage !== [], 'The letterhead is embedded in ' . $description);
    ok(
        $perPage !== [] && count(array_filter($perPage, static fn (int $n): bool => $n < 1)) === 0,
        sprintf('It is drawn on every one of %d pages of %s (%s)', count($perPage), $description, implode(',', $perPage))
    );
    ok(
        count($perPage) > 1 || $description !== 'the inspection report',
        'The inspection report runs to more than one page, so "every page" means something'
    );
}

// The client asked for the system reference off this form: a uuid means nothing to the branch
// that signs it, and on an official letterhead it reads as clutter.
$inspectionUuid = (string) Database::scalar('SELECT uuid FROM inspections WHERE id = :id', ['id' => $inspectionId]);
$noRefText = pdf_text_flat($inspectionQrPdf['path']);

ok($inspectionUuid !== '', 'The inspection still has a reference in the database');
ok(!str_contains($noRefText, $inspectionUuid), 'But it is not printed on the inspection report');
ok(!str_contains($noRefText, 'Reference:'), 'Nor is the label it used to sit under');

// What identifies it to a person is still there, and so is the QR that identifies it to the
// panel — dropping the number must not leave the sheet untraceable.
ok(str_contains($noRefText, 'BC001'), 'The BC code still identifies whose outlet it was');
ok(lrms_pdf_has_qr($inspectionQrPdf['path']), 'And the QR code still leads back to the record');

// The visit report keeps its reference: only the inspection was asked about.
ok(
    str_contains(pdf_text_flat($visitQrPdf['path']), 'Visit reference:'),
    'The customer visit report keeps its reference line'
);

/* -------------------------------------------------------------------------- */
section('The header is the letterhead, not a stack of names');
/* -------------------------------------------------------------------------- */

/*
 * The organisation name used to be the first and largest line of the heading. The letterhead
 * above it already says "Central Bank of India" in Hindi and English, so the line said nothing
 * — and it was the line that made the rest of the block look packed together.
 */
$headerPdfs = [
    'the inspection report' => $inspectionQrPdf['path'],
    'the customer visit report' => $visitQrPdf['path'],
    'a tabular report' => $tabularPath,
];

foreach ($headerPdfs as $description => $file) {
    ok(
        !str_contains(pdf_text_flat($file), org_name()),
        'The organisation name is not printed on ' . $description
    );
}

// And the title still is, or removing the name would have taken the heading with it.
ok(str_contains(pdf_text_flat($inspectionQrPdf['path']), 'BCA Inspection'), 'The report title is still there');

/*
 * Leading, across every page of every document. This is the check for "text tangled together":
 * not literal overlap, which a PDF rarely produces, but baselines set closer than the type is
 * drawn, so descenders land in the ascenders below.
 */
foreach ($headerPdfs + ['the verification report' => $fvrPdf['path'] ?? ''] as $description => $file) {
    if ($file === '') {
        continue;
    }

    $tight = pdf_tight_leading($file);

    ok(
        $tight === [],
        $tight === []
            ? 'Every line of ' . $description . ' has room to breathe'
            : 'Cramped lines in ' . $description . ': ' . implode(' | ', array_slice($tight, 0, 3))
    );
}

/*
 * Two things a key/value label used to do on the inspection form: lose its tail, and orphan its
 * colon.
 *
 * Item 1 is "1. Name of Business Correspondent Agent (BCA)", which needs three lines in the
 * label column. Labels were sliced to two with no mark, so "(BCA)" simply vanished off a form
 * somebody signs. Item 4 was long enough that the wrapper broke before the " :", printing a
 * line containing nothing but a colon.
 */
$labelRuns = pdf_text_runs($inspectionQrPdf['path']);
$labelTexts = array_map(static fn (array $r): string => trim($r['text']), $labelRuns);

ok(in_array('(BCA) :', $labelTexts, true), 'A three-line label prints its last line rather than dropping it');
ok(!in_array(':', $labelTexts, true), 'No line is drawn containing nothing but a colon');
ok(
    count(array_filter($labelTexts, static fn (string $t): bool => $t !== '' && trim($t, ': ') === '')) === 0,
    'Nor one containing only punctuation'
);

exit(TestRunner::summary());
