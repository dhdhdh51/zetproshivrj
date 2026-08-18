<?php

declare(strict_types=1);

/**
 * Excel pipeline tests: writer → reader round trip, automatic column matching,
 * value parsing, preview validation, import and workload-balanced allocation.
 *
 *   php tests/test-import.php
 *
 * Requires a seeded database (php database/migrate.php --fresh --demo).
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\Allocation;
use App\Services\Excel\ColumnMatcher;
use App\Services\Excel\LoanImporter;
use App\Services\Excel\SpreadsheetReader;
use App\Services\Excel\SystemFields;
use App\Services\Excel\ValueParser;
use App\Services\Export\XlsxWriter;

// Run as the seeded Admin/Supervisor.
$admin = Database::selectOne(
    "SELECT u.*, r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin' LIMIT 1"
);

if ($admin === null) {
    exit("No admin user found. Run: php database/migrate.php --fresh --demo\n");
}

Auth::setUser($admin);

/* -------------------------------------------------------------------------- */
section('Value parsing');
/* -------------------------------------------------------------------------- */

equals(123456.78, ValueParser::amount('1,23,456.78')[0], 'Indian grouped amount parsed');
equals(-2500.0, ValueParser::amount('(2,500)')[0], 'Accounting negative parsed');
equals(-1200.0, ValueParser::amount('1,200 Cr')[0], 'Credit marker treated as negative');
equals(null, ValueParser::amount('-')[0], 'Dash amount treated as empty');
equals(45000.0, ValueParser::amount('₹45,000')[0], 'Rupee symbol stripped');
ok(ValueParser::amount('abc')[1] !== '', 'Unparseable amount reports an error');

equals('2024-03-25', ValueParser::date('25/03/2024')[0], 'd/m/Y date parsed');
equals('2024-03-25', ValueParser::date('25-03-2024')[0], 'd-m-Y date parsed');
equals('2024-03-25', ValueParser::date('2024-03-25')[0], 'ISO date parsed');
equals('2024-03-25', ValueParser::date('25-Mar-2024')[0], 'd-M-Y date parsed');
equals('2024-12-25', ValueParser::date('12/25/2024')[0], 'Unambiguous US date recovered');
equals(null, ValueParser::date('NIL')[0], 'NIL date treated as empty');
ok(ValueParser::date('32/13/2024')[1] !== '', 'Impossible date reports an error');

equals('9876543210', ValueParser::mobile('+91 98765-43210')[0], 'Mobile normalised from +91 form');
equals('9876543210', ValueParser::mobile('09876543210')[0], 'Trunk prefix stripped');
ok(ValueParser::mobile('12345')[1] !== '', 'Short mobile reports a warning');

equals('31234567890123', ValueParser::accountNumber('31234567890123'), 'Long account number preserved');
equals('3123456789', ValueParser::accountNumber('3123456789.0'), 'Excel float account number cleaned');
equals('919999999999', ValueParser::accountNumber('9.19999999999E+11'), 'Scientific notation account number restored');

/* -------------------------------------------------------------------------- */
section('Automatic column matching');
/* -------------------------------------------------------------------------- */

$headers = [
    0 => 'A/C No',
    1 => 'CIF No',
    2 => 'Customer Name',
    3 => "Father's Name",
    4 => 'Mobile No',
    5 => 'Village',
    6 => 'Branch Code',
    7 => 'Outstanding Amt',
    8 => 'OD Amount',
    9 => 'NPA Dt',
    10 => 'Sanction Date',
    11 => 'Limit',
    12 => 'BC Code',
    13 => 'Scheme',
];

$matches = ColumnMatcher::match($headers);

equals(0, $matches['account_number']['column'], 'A/C No → Account Number');
equals(1, $matches['cif']['column'], 'CIF No → CIF');
equals(2, $matches['borrower_name']['column'], 'Customer Name → Borrower Name');
equals(3, $matches['father_name']['column'], "Father's Name → Father Name");
equals(4, $matches['mobile']['column'], 'Mobile No → Mobile');
equals(6, $matches['branch_code']['column'], 'Branch Code → Branch Code');
equals(7, $matches['outstanding']['column'], 'Outstanding Amt → Outstanding');
equals(8, $matches['overdue']['column'], 'OD Amount → Overdue');
equals(9, $matches['npa_date']['column'], 'NPA Dt → NPA Date');
equals(10, $matches['sanction_date']['column'], 'Sanction Date → Sanction Date');
equals(11, $matches['limit_amount']['column'], 'Limit → Limit');
equals(12, $matches['bc_code']['column'], 'BC Code → BC Code');
equals(13, $matches['loan_type']['column'], 'Scheme → Loan Type');

ok(ColumnMatcher::missingRequired($matches) === [], 'No required field left unmatched');
ok($matches['account_number']['certain'], 'Account Number match is high confidence');

// Two fields must never claim the same column.
$claimed = [];
$collision = false;

foreach ($matches as $match) {
    if ($match['column'] === null) {
        continue;
    }

    if (isset($claimed[$match['column']])) {
        $collision = true;
    }

    $claimed[$match['column']] = true;
}

ok(!$collision, 'Each Excel column is claimed by at most one system field');

// A sheet with unrecognisable headers should leave required fields unmatched
// rather than inventing a mapping.
$noise = ColumnMatcher::match([0 => 'Col1', 1 => 'Col2', 2 => 'Zzz']);
ok(ColumnMatcher::missingRequired($noise) !== [], 'Unrecognisable headers leave required fields unmatched');

/* -------------------------------------------------------------------------- */
section('XLSX writer → reader round trip');
/* -------------------------------------------------------------------------- */

$branchCodes = Database::select('SELECT code FROM branches ORDER BY id');
ok(count($branchCodes) >= 3, 'Demo branches are present');

$bcCodes = Database::select('SELECT bc_code FROM bc_supervisors ORDER BY id');
ok(count($bcCodes) >= 6, 'Demo BC Supervisors are present');

// BC Codes grouped by their branch code, so the generated sheet only pairs a
// supervisor with accounts of their own branch (as a real export would).
$bcByBranch = [];

foreach (Database::select(
    'SELECT b.code AS branch_code, s.bc_code
       FROM bc_supervisors s JOIN branches b ON b.id = s.branch_id
      ORDER BY s.id'
) as $row) {
    $bcByBranch[(string) $row['branch_code']][] = (string) $row['bc_code'];
}

$file = sys_get_temp_dir() . '/lrms-test-accounts.xlsx';
$writer = new XlsxWriter('Accounts');
$writer->headers($headers);

$totalRows = 40;

for ($i = 1; $i <= $totalRows; $i++) {
    $branch = (string) $branchCodes[($i - 1) % count($branchCodes)]['code'];

    // Rows 1–12 carry a BC Code from their own branch; the rest have no code and
    // must be balanced automatically.
    $bcCode = '';

    if ($i <= 12) {
        $candidates = $bcByBranch[$branch] ?? [];
        $bcCode = $candidates === [] ? '' : $candidates[intdiv($i - 1, count($branchCodes)) % count($candidates)];
    }

    $writer->row([
        '31' . str_pad((string) $i, 12, '0', STR_PAD_LEFT),
        'CIF' . $i,
        'Borrower ' . $i,
        'Father ' . $i,
        '98765' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
        'Village ' . (($i % 7) + 1),
        $branch,
        (float) (10000 + ($i * 137)),
        (float) (2500 + ($i * 31)),
        '2023-06-15',
        '2020-04-01',
        (float) (50000 + ($i * 100)),
        $bcCode,
        $i % 2 === 0 ? 'KCC' : 'Agri Term Loan',
    ]);
}

// Deliberate problem rows.
$writer->row(['', 'CIF-X', 'No Account Number', 'F', '9000000000', 'V', $branchCodes[0]['code'], 100.0, 10.0, '', '', 0.0, '', 'KCC']);
$writer->row(['31' . str_pad('1', 12, '0', STR_PAD_LEFT), 'CIF-DUP', 'Duplicate Row', 'F', '9000000001', 'V', $branchCodes[0]['code'], 500.0, 50.0, '', '', 0.0, '', 'KCC']);
$writer->row(['319999999999', 'CIF-Y', 'Unknown Branch', 'F', '9000000002', 'V', 'ZZ999', 700.0, 70.0, '', '', 0.0, '', 'KCC']);
$writer->row(['319999999998', 'CIF-Z', 'Bad Amount', 'F', '9000000003', 'V', $branchCodes[0]['code'], 'not-a-number', 70.0, '', '', 0.0, '', 'KCC']);
$writer->row(['319999999997', 'CIF-W', 'Bad BC Code', 'F', '9000000004', 'V', $branchCodes[0]['code'], 900.0, 90.0, '', '', 0.0, 'BC-NOPE', 'KCC']);

$writer->save($file);
ok(is_file($file) && filesize($file) > 500, 'XLSX file written');

$reader = new SpreadsheetReader($file);
equals(['Accounts'], $reader->sheetNames(), 'Sheet name round trips');

$readHeaders = $reader->headers(null, 1);
equals('A/C No', $readHeaders[0], 'First header round trips');
equals('BC Code', $readHeaders[12], 'Later header round trips');
equals(count($headers), count($readHeaders), 'Header count round trips');

$readRowCount = $reader->countRows(null, 1);
equals($totalRows + 5, $readRowCount, 'Data row count round trips');

$firstRow = null;

foreach ($reader->rows(null, 1, 1) as $values) {
    $firstRow = $values;
}

ok($firstRow !== null, 'First data row is readable');
equals('31000000000001', $firstRow[0] ?? '', 'Long numeric account number kept as text');
equals('Borrower 1', $firstRow[2] ?? '', 'String cell round trips');
ok(abs((float) ($firstRow[7] ?? 0) - 10137.0) < 0.01, 'Numeric amount round trips');
equals('2023-06-15', substr((string) ($firstRow[9] ?? ''), 0, 10), 'Date cell round trips as a date');

/* -------------------------------------------------------------------------- */
section('Import: upload, mapping, preview');
/* -------------------------------------------------------------------------- */

$importer = new LoanImporter();

$temp = sys_get_temp_dir() . '/lrms-upload-copy.xlsx';
copy($file, $temp);

$import = $importer->store([
    'name' => 'central-bank-npa-list.xlsx',
    'tmp_name' => $temp,
    'size' => filesize($file),
    'error' => UPLOAD_ERR_OK,
]);

ok((int) $import['id'] > 0, 'Import row created');
equals('uploaded', $import['status'], 'Import starts in the uploaded state');

$autoMapping = $importer->mapping($import);
equals('A/C No', $autoMapping['account_number'] ?? '', 'Mapping auto-populated from detection');

// Saving a mapping without the required fields must be refused.
throws(
    static fn () => $importer->saveMapping((int) $import['id'], ['cif' => 'CIF No']),
    'Mapping without Account Number is rejected',
    'required'
);

// Saving a mapping that names a column not in the file must be refused.
throws(
    static fn () => $importer->saveMapping((int) $import['id'], array_merge($autoMapping, ['mobile' => 'Nope'])),
    'Mapping a non-existent column is rejected',
    'not present'
);

$import = $importer->saveMapping((int) $import['id'], $autoMapping);
equals('mapped', $import['status'], 'Confirmed mapping moves the import to mapped');

$preview = $importer->preview((int) $import['id'], 60);

equals($totalRows + 5, $preview['total_rows'], 'Preview reports the full row count');
equals(1, $preview['summary']['missing_required'], 'Preview flags the row with no account number');
equals(1, $preview['summary']['duplicate_in_file'], 'Preview flags the in-file duplicate');
equals(1, $preview['summary']['unknown_branch'], 'Preview flags the unknown branch');
equals(1, $preview['summary']['invalid_data'], 'Preview flags the unparseable amount');
equals(1, $preview['summary']['invalid_bc'], 'Preview flags the unknown BC Code');
equals($totalRows + 1, $preview['summary']['ready'], 'Preview counts the importable rows');
ok($preview['issues'] !== [], 'Preview surfaces human readable issues');

// Nothing may be written before the import is confirmed.
equals(0, (int) Database::scalar('SELECT COUNT(*) FROM loan_accounts'), 'Preview writes no accounts');

/* -------------------------------------------------------------------------- */
section('Import: execution and allocation');
/* -------------------------------------------------------------------------- */

$stats = $importer->import((int) $import['id']);

equals($totalRows + 1, $stats['imported'], 'Importable rows were imported');
equals($totalRows + 1, $stats['created'], 'All importable rows created new accounts');
equals(4, $stats['skipped'], 'Four problem rows were skipped');
equals($totalRows + 1, $stats['assigned'], 'Every imported account was allocated');

equals($totalRows + 1, (int) Database::scalar('SELECT COUNT(*) FROM loan_accounts'), 'Accounts landed in the database');

$errors = Database::select('SELECT error_type, severity FROM excel_import_errors WHERE import_id = :id', ['id' => (int) $import['id']]);
$errorTypes = array_column($errors, 'error_type');
ok(in_array('missing_required', $errorTypes, true), 'Missing required error recorded');
ok(in_array('duplicate', $errorTypes, true), 'Duplicate error recorded');
ok(in_array('unknown_branch', $errorTypes, true), 'Unknown branch error recorded');
ok(in_array('invalid_data', $errorTypes, true), 'Invalid data error recorded');
ok(in_array('invalid_bc', $errorTypes, true), 'Invalid BC Code recorded (as a warning)');

// BC Code rows must go to the named supervisor.
$byCode = Database::selectOne(
    "SELECT s.bc_code
       FROM account_assignments a
       JOIN bc_supervisors s ON s.id = a.bc_supervisor_id
       JOIN loan_accounts l ON l.id = a.loan_account_id
      WHERE l.account_number = :n AND a.is_active = 1",
    ['n' => '31000000000001']
);
$expectedFirstBc = $bcByBranch[(string) $branchCodes[0]['code']][0] ?? '';
equals($expectedFirstBc, (string) ($byCode['bc_code'] ?? ''), 'Row with a BC Code went to that BC Supervisor');

$methods = Database::select(
    'SELECT method, COUNT(*) AS c FROM account_assignments WHERE is_active = 1 GROUP BY method'
);
$methodCounts = array_column($methods, 'c', 'method');
ok((int) ($methodCounts['excel_bc_code'] ?? 0) > 0, 'Some accounts were allocated by BC Code');
ok((int) ($methodCounts['auto_balance'] ?? 0) > 0, 'Some accounts were allocated by workload balancing');

// Balance check: within a branch the spread must stay tight.
$perBranch = Database::select(
    "SELECT s.branch_id, MIN(cnt) AS lo, MAX(cnt) AS hi FROM (
        SELECT s.id, s.branch_id, COUNT(a.id) AS cnt
          FROM bc_supervisors s
          LEFT JOIN account_assignments a ON a.bc_supervisor_id = s.id AND a.is_active = 1
         GROUP BY s.id, s.branch_id
     ) AS s GROUP BY s.branch_id"
);

$balanced = true;

foreach ($perBranch as $branch) {
    // BC-coded rows are intentionally concentrated, so allow a modest spread
    // but assert that no supervisor was left with nothing while another piled up.
    if ((int) $branch['lo'] === 0 && (int) $branch['hi'] > 3) {
        $balanced = false;
    }
}

ok($balanced, 'No active supervisor was starved while another accumulated accounts');

/* -------------------------------------------------------------------------- */
section('Allocation invariants');
/* -------------------------------------------------------------------------- */

$allocation = new Allocation();
$accountId = (int) Database::scalar('SELECT id FROM loan_accounts ORDER BY id LIMIT 1');
$branchId = (int) Database::scalar('SELECT branch_id FROM loan_accounts WHERE id = :id', ['id' => $accountId]);

$sameBranchOther = (int) Database::scalar(
    'SELECT s.id FROM bc_supervisors s
      WHERE s.branch_id = :b
        AND s.id <> (SELECT bc_supervisor_id FROM account_assignments WHERE loan_account_id = :id AND is_active = 1)
      LIMIT 1',
    ['b' => $branchId, 'id' => $accountId]
);

$allocation->reassign($accountId, $sameBranchOther, 'Load rebalancing during test');

equals(
    1,
    (int) Database::scalar(
        'SELECT COUNT(*) FROM account_assignments WHERE loan_account_id = :id AND is_active = 1',
        ['id' => $accountId]
    ),
    'Exactly one active assignment after reassignment'
);

equals(
    $sameBranchOther,
    (int) Database::scalar(
        'SELECT bc_supervisor_id FROM account_assignments WHERE loan_account_id = :id AND is_active = 1',
        ['id' => $accountId]
    ),
    'Active assignment points at the new supervisor'
);

ok(
    (int) Database::scalar(
        'SELECT COUNT(*) FROM account_assignments WHERE loan_account_id = :id AND is_active IS NULL',
        ['id' => $accountId]
    ) >= 1,
    'Previous assignment retained as history'
);

// Cross-branch allocation must be refused.
$otherBranchSupervisor = (int) Database::scalar(
    'SELECT id FROM bc_supervisors WHERE branch_id <> :b LIMIT 1',
    ['b' => $branchId]
);

throws(
    static fn () => $allocation->assign($accountId, $otherBranchSupervisor, 'manual', 'should fail'),
    'Allocating an account to another branch is refused',
    'own branch'
);

// Audit trail must record the reassignment.
ok(
    (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_logs WHERE action = 'account_reassigned' AND entity_id = :id",
        ['id' => $accountId]
    ) > 0,
    'Reassignment written to the audit trail'
);

/* -------------------------------------------------------------------------- */
section('Re-import updates instead of duplicating');
/* -------------------------------------------------------------------------- */

$temp2 = sys_get_temp_dir() . '/lrms-upload-copy-2.xlsx';
copy($file, $temp2);

$second = $importer->store([
    'name' => 'central-bank-npa-list-v2.xlsx',
    'tmp_name' => $temp2,
    'size' => filesize($file),
    'error' => UPLOAD_ERR_OK,
]);

$importer->saveMapping((int) $second['id'], $importer->mapping($second));
$stats2 = $importer->import((int) $second['id']);

equals(0, $stats2['created'], 'Re-importing the same sheet creates no new accounts');
equals($totalRows + 1, $stats2['updated'], 'Re-importing updates the existing accounts');
equals($totalRows + 1, (int) Database::scalar('SELECT COUNT(*) FROM loan_accounts'), 'Account count unchanged after re-import');

/* -------------------------------------------------------------------------- */
section('Mapping templates');
/* -------------------------------------------------------------------------- */

$templateId = $importer->saveTemplate((int) $second['id'], 'Central Bank Excel Format', 'NPA list layout');
ok($templateId > 0, 'Mapping template saved');

$temp3 = sys_get_temp_dir() . '/lrms-upload-copy-3.xlsx';
copy($file, $temp3);

$third = $importer->store([
    'name' => 'central-bank-npa-list-v3.xlsx',
    'tmp_name' => $temp3,
    'size' => filesize($file),
    'error' => UPLOAD_ERR_OK,
]);

$applied = $importer->applyTemplate((int) $third['id'], $templateId);
equals('A/C No', $applied['applied']['account_number'] ?? '', 'Template re-applied to a new upload');
equals([], $applied['missing_columns'], 'Template columns all resolved in the new file');
equals('mapped', $applied['import']['status'], 'Applying a template marks the import as mapped');

$importer->cancel((int) $third['id']);
equals(
    'cancelled',
    (string) Database::scalar('SELECT status FROM excel_imports WHERE id = :id', ['id' => (int) $third['id']]),
    'Cancelling an import records the cancellation'
);

@unlink($file);

exit(TestRunner::summary());
