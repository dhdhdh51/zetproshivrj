#!/usr/bin/env php
<?php

declare(strict_types=1);

// LRMS in-place schema upgrade.
//
//   php database/upgrade.php --dry-run    show what would change, touch nothing
//   php database/upgrade.php              apply
//
// migrate.php --fresh is destructive, so it cannot be used once real loan data
// and field visits exist. This script brings an installed database up to the
// current database/schema.sql by adding only what is missing. It is safe to run
// repeatedly: every step checks INFORMATION_SCHEMA first, and no step drops a
// column or rewrites data.
//
// The 2026 upgrade adds the fields the client's official "Field Visit
// Verification Report" prints — borrower identity (section 2), loan detail
// (section 3), the KRM OTS block (section 4), the CKCC OD-2 renewal block
// (section 5), the rest of physical verification (section 6), the documents and
// evidence checklists (sections 7 and 10), the declaration and certification
// (sections 11 and 12) and the per-stream final status (section 13) — plus the
// BC Supervisor identity fields from the BC creation screen.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
$database = (string) Config::get('database.database');

if (!Database::isConnected()) {
    fwrite(STDERR, 'Database unavailable: ' . (string) Database::lastError() . "\n");
    exit(1);
}

/**
 * Columns to add, in order. `after` keeps the physical column order readable,
 * which matters when someone inspects the table by hand.
 *
 * @var array<int, array{0:string, 1:string, 2:string, 3:?string}> $columns
 */
$columns = [
    /* Branch: Regional Office is `region`; Zone is new. --------------------- */
    ['branches', 'zone', 'VARCHAR(120) NULL', 'region'],

    /* BC Supervisor identity (the BC Agent is the BC Supervisor). ----------- */
    ['bc_supervisors', 'sp_cbc_name', 'VARCHAR(190) NULL', 'bc_code'],
    ['bc_supervisors', 'ssa', 'VARCHAR(160) NULL', 'sp_cbc_name'],
    ['bc_supervisors', 'iibf_number', 'VARCHAR(60) NULL', 'ssa'],
    ['bc_supervisors', 'dra_id', 'VARCHAR(60) NULL', 'iibf_number'],
    ['bc_supervisors', 'designation', 'VARCHAR(120) NULL', 'dra_id'],
    ['bc_supervisors', 'aadhaar_last4', 'CHAR(4) NULL', 'designation'],
    ['bc_supervisors', 'pan_number', 'VARCHAR(12) NULL', 'aadhaar_last4'],
    ['bc_supervisors', 'block', 'VARCHAR(120) NULL', 'village'],
    ['bc_supervisors', 'tehsil', 'VARCHAR(120) NULL', 'block'],
    ['bc_supervisors', 'district', 'VARCHAR(120) NULL', 'tehsil'],
    ['bc_supervisors', 'state', 'VARCHAR(120) NULL', 'district'],
    ['bc_supervisors', 'pincode', 'VARCHAR(12) NULL', 'state'],

    /* Borrower information, report section 2. ------------------------------ */
    ['loan_accounts', 'gender', "ENUM('male','female','other') NULL", 'mobile'],
    ['loan_accounts', 'date_of_birth', 'DATE NULL', 'gender'],
    ['loan_accounts', 'alternate_mobile', 'VARCHAR(20) NULL', 'date_of_birth'],
    ['loan_accounts', 'aadhaar_last4', 'CHAR(4) NULL', 'alternate_mobile'],
    ['loan_accounts', 'pan_number', 'VARCHAR(12) NULL', 'aadhaar_last4'],
    ['loan_accounts', 'gram_panchayat', 'VARCHAR(160) NULL', 'village'],
    ['loan_accounts', 'tehsil', 'VARCHAR(120) NULL', 'gram_panchayat'],
    ['loan_accounts', 'district', 'VARCHAR(120) NULL', 'tehsil'],
    ['loan_accounts', 'state', 'VARCHAR(120) NULL', 'district'],
    ['loan_accounts', 'pincode', 'VARCHAR(12) NULL', 'state'],

    /* Loan account details, report section 3. ------------------------------ */
    ['loan_accounts', 'drawing_power', 'DECIMAL(15,2) NULL', 'limit_amount'],
    ['loan_accounts', 'interest_overdue', 'DECIMAL(15,2) NULL', 'outstanding'],
    ['loan_accounts', 'asset_classification', "ENUM('standard','sma_0','sma_1','sma_2','npa') NULL", 'total_recovered'],

    /* Visit: case type detail, physical verification, checklists, sign-off. - */
    ['visits', 'visit_type_other', 'VARCHAR(120) NULL', 'visit_type'],

    ['visits', 'visit_time', 'TIME NULL', 'visit_date'],
    ['visits', 'address_shifted', 'TINYINT(1) NULL', 'current_address'],
    ['visits', 'residence_verified', 'TINYINT(1) NULL', 'occupation'],
    ['visits', 'neighbour_verified', 'TINYINT(1) NULL', 'residence_verified'],
    ['visits', 'documents_verified', 'VARCHAR(500) NULL', 'neighbour_verified'],
    ['visits', 'documents_other', 'VARCHAR(160) NULL', 'documents_verified'],
    ['visits', 'evidence_attached', 'VARCHAR(500) NULL', 'documents_other'],
    ['visits', 'evidence_other', 'VARCHAR(160) NULL', 'evidence_attached'],
    ['visits', 'declaration_accepted', 'TINYINT(1) NOT NULL DEFAULT 0', 'supervisor_signature'],
    ['visits', 'declared_at', 'DATETIME NULL', 'declaration_accepted'],
    ['visits', 'verifier_signature', 'VARCHAR(255) NULL', 'declared_at'],

    /* KRM OTS details, report section 4 (+ sections 9 and 13). ------------- */
    ['krm_ots_cases', 'ots_eligible', 'TINYINT(1) NULL', 'visit_id'],
    ['krm_ots_cases', 'scheme', "ENUM('krm_ots','general_ots','other') NOT NULL DEFAULT 'krm_ots'", 'ots_eligible'],
    ['krm_ots_cases', 'scheme_other', 'VARCHAR(120) NULL', 'scheme'],
    ['krm_ots_cases', 'borrower_share', 'DECIMAL(15,2) NULL', 'ots_amount'],
    ['krm_ots_cases', 'initial_deposit_required', 'DECIMAL(15,2) NULL', 'borrower_share'],
    [
        'krm_ots_cases',
        'customer_response',
        "ENUM('agreed','requested_time','financial_difficulty','refused','not_eligible') NULL",
        'paid_amount',
    ],
    [
        'krm_ots_cases',
        'recommendation',
        "ENUM('proposal_recommended','followup_required','customer_refused','not_eligible') NULL",
        'promise_date',
    ],
    [
        'krm_ots_cases',
        'final_status',
        "ENUM('customer_contacted','customer_verified','ots_accepted','ots_rejected','initial_deposit_received',"
            . "'ots_closed','followup_required') NULL",
        'recommendation',
    ],

    /* CKCC OD-2 renewal details, report section 5 (+ sections 9 and 13). --- */
    ['ckcc_renewals', 'renewal_eligible', 'TINYINT(1) NULL', 'overdue'],
    [
        'ckcc_renewals',
        'renewal_due_bucket',
        "ENUM('within_30_days','within_15_days','within_7_days','overdue') NULL",
        'renewal_eligible',
    ],
    ['ckcc_renewals', 'renewal_due_date', 'DATE NULL', 'renewal_due_bucket'],
    ['ckcc_renewals', 'expected_npa_date', 'DATE NULL', 'renewal_due_date'],
    ['ckcc_renewals', 'days_remaining', 'INT NULL', 'expected_npa_date'],
    ['ckcc_renewals', 'kyc_status', "ENUM('complete','pending') NULL", 'days_remaining'],
    ['ckcc_renewals', 'aadhaar_seeded', 'TINYINT(1) NULL', 'kyc_status'],
    ['ckcc_renewals', 'mobile_linked', 'TINYINT(1) NULL', 'aadhaar_seeded'],
    ['ckcc_renewals', 'aadhaar_authentication', "ENUM('completed','pending') NULL", 'mobile_linked'],
    ['ckcc_renewals', 'renewal_consent', 'TINYINT(1) NULL', 'aadhaar_authentication'],
    ['ckcc_renewals', 'renewal_form_signed', 'TINYINT(1) NULL', 'renewal_consent'],
    ['ckcc_renewals', 'biometrics_completed', 'TINYINT(1) NULL', 'renewal_form_signed'],
    [
        'ckcc_renewals',
        'recommendation',
        "ENUM('renew_immediately','documents_complete','documents_pending','customer_not_interested',"
            . "'branch_followup_required') NULL",
        'renewed_on',
    ],
    [
        'ckcc_renewals',
        'final_status',
        "ENUM('customer_contacted','customer_verified','documents_collected','renewal_submitted','renewal_approved',"
            . "'pending_at_branch','became_npa','followup_required') NULL",
        'recommendation',
    ],
];

/**
 * Columns whose definition widened. Re-applying an identical definition is a
 * no-op in MySQL, but each is checked so a run that changes nothing says so.
 *
 * @var array<int, array{0:string, 1:string, 2:string, 3:string}> $modifications
 */
$modifications = [
    [
        'visits',
        'visit_type',
        "ENUM('customer','krm_ots','ckcc_od2','recovery_followup','pre_npa','post_npa','other') NOT NULL DEFAULT 'customer'",
        // Applied only when the stored definition is missing an option.
        'recovery_followup',
    ],
    // `contains` lets a field depend on one ticked value of a checkbox answer,
    // which the "Other document" and "Other evidence" fields need.
    [
        'visit_form_fields',
        'condition_operator',
        "ENUM('equals','not_equals','in','contains','filled','empty') NULL",
        'contains',
    ],
    [
        'inspection_form_fields',
        'condition_operator',
        "ENUM('equals','not_equals','in','contains','filled','empty') NULL",
        'contains',
    ],
    // The Aadhaar slot the reference app has. Appended to the end of the ENUM on
    // purpose: MySQL stores an ENUM as an integer index, so inserting a member
    // mid-list would rebuild visit_photos and re-map values already stored.
    [
        'visit_photos',
        'photo_type',
        "ENUM('customer','house','shop','land','document','selfie','other','aadhaar') NOT NULL DEFAULT 'other'",
        'aadhaar',
    ],
    // The section 11 declaration is ~1,100 characters and must be shown verbatim.
    ['visit_form_fields', 'help_text', 'VARCHAR(2000) NULL', 'varchar(2000)'],
    ['inspection_form_fields', 'help_text', 'VARCHAR(2000) NULL', 'varchar(2000)'],
];

/** Indexes worth adding for the new report filters. */
$indexes = [
    ['bc_supervisors', 'ix_bc_iibf', '(`iibf_number`)'],
    ['bc_supervisors', 'ix_bc_dra', '(`dra_id`)'],
    ['loan_accounts', 'ix_accounts_asset_class', '(`asset_classification`)'],
    ['krm_ots_cases', 'ix_krm_final', '(`final_status`)'],
    ['krm_ots_cases', 'ix_krm_response', '(`customer_response`)'],
    ['ckcc_renewals', 'ix_ckcc_final', '(`final_status`)'],
    ['ckcc_renewals', 'ix_ckcc_due', '(`renewal_due_date`)'],
];

function tableExists(string $database, string $table): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t',
        ['db' => $database, 't' => $table]
    ) > 0;
}

function columnExists(string $database, string $table, string $column): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        ['db' => $database, 't' => $table, 'c' => $column]
    ) > 0;
}

function columnType(string $database, string $table, string $column): string
{
    return (string) Database::scalar(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        ['db' => $database, 't' => $table, 'c' => $column]
    );
}

function indexExists(string $database, string $table, string $index): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND INDEX_NAME = :i',
        ['db' => $database, 't' => $table, 'i' => $index]
    ) > 0;
}

printf("LRMS schema upgrade%s\n", $dryRun ? ' (dry run — nothing will be changed)' : '');
printf("Database: %s\n\n", $database);

$pdo = Database::pdo();
$applied = 0;
$skipped = 0;
$failed = 0;

/* Columns ------------------------------------------------------------------ */

foreach ($columns as [$table, $column, $definition, $after]) {
    if (!tableExists($database, $table)) {
        printf("  !! %-16s table missing — run migrate.php first\n", $table);
        $failed++;
        continue;
    }

    if (columnExists($database, $table, $column)) {
        $skipped++;
        continue;
    }

    $sql = sprintf(
        'ALTER TABLE `%s` ADD COLUMN `%s` %s%s',
        $table,
        $column,
        $definition,
        $after !== null && columnExists($database, $table, $after) ? sprintf(' AFTER `%s`', $after) : ''
    );

    if ($dryRun) {
        printf("  +  %s\n", $sql);
        $applied++;
        continue;
    }

    try {
        $pdo->exec($sql);
        printf("  +  %s.%s added\n", $table, $column);
        $applied++;
    } catch (Throwable $e) {
        printf("  !! %s.%s failed: %s\n", $table, $column, $e->getMessage());
        $failed++;
    }
}

/* Widened definitions ------------------------------------------------------ */

foreach ($modifications as [$table, $column, $definition, $marker]) {
    if (!columnExists($database, $table, $column)) {
        continue;
    }

    if (str_contains(columnType($database, $table, $column), $marker)) {
        $skipped++;
        continue;
    }

    $sql = sprintf('ALTER TABLE `%s` MODIFY COLUMN `%s` %s', $table, $column, $definition);

    if ($dryRun) {
        printf("  ~  %s\n", $sql);
        $applied++;
        continue;
    }

    try {
        $pdo->exec($sql);
        printf("  ~  %s.%s widened\n", $table, $column);
        $applied++;
    } catch (Throwable $e) {
        printf("  !! %s.%s failed: %s\n", $table, $column, $e->getMessage());
        $failed++;
    }
}

/* Indexes ------------------------------------------------------------------ */

foreach ($indexes as [$table, $index, $definition]) {
    if (!tableExists($database, $table) || indexExists($database, $table, $index)) {
        $skipped++;
        continue;
    }

    $sql = sprintf('ALTER TABLE `%s` ADD KEY `%s` %s', $table, $index, $definition);

    if ($dryRun) {
        printf("  +  %s\n", $sql);
        $applied++;
        continue;
    }

    try {
        $pdo->exec($sql);
        printf("  +  %s.%s index added\n", $table, $index);
        $applied++;
    } catch (Throwable $e) {
        printf("  !! %s.%s failed: %s\n", $table, $index, $e->getMessage());
        $failed++;
    }
}

printf(
    "\n%s: %d change(s), %d already present, %d failed.\n",
    $dryRun ? 'Would apply' : 'Applied',
    $applied,
    $skipped,
    $failed
);

if ($failed > 0) {
    exit(1);
}

if (!$dryRun && $applied > 0) {
    echo "\nRe-run database/seed.php to install the KRM OTS and CKCC OD-2 report forms:\n";
    echo "  php database/migrate.php --seed\n";
}

exit(0);
