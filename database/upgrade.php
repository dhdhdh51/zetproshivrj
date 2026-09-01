<?php

// No shebang. The file is not executable and is always invoked as
// `php database/upgrade.php`, but a shebang counts as output when the file is included —
// which pushed declare(strict_types=1) off the first line and made the panel's update
// button fatal instead of running.
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
// BCA identity fields from the BCA creation screen.

// The panel can run this too, because the hosting this is deployed on often has no
// terminal at all. That route defines LRMS_UPGRADE_IN_APP and has already checked that
// the caller is a signed-in Admin. Reached any other way over HTTP it still refuses:
// a URL that can alter the schema is not something to leave open.
if (PHP_SAPI !== 'cli' && !defined('LRMS_UPGRADE_IN_APP')) {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

// require_once, not require: when the panel runs this the application is already booted,
// and bootstrap.php defines constants and registers handlers that must not run twice.
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Settings;

$dryRun = defined('LRMS_UPGRADE_DRY_RUN')
    ? (bool) constant('LRMS_UPGRADE_DRY_RUN')
    : in_array('--dry-run', array_slice($argv ?? [], 1), true);
$database = (string) Config::get('database.database');

if (!Database::isConnected()) {
    $problem = 'Database unavailable: ' . (string) Database::lastError();

    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException($problem);
    }

    fwrite(STDERR, $problem . "\n");
    exit(1);
}

/**
 * Whole tables added after the first release.
 *
 * Every other pass in this file assumes its table already exists — they add columns,
 * widen them, set defaults and build indexes. A feature that arrives with a table of its
 * own has nowhere else to go, and a live database that predates it would otherwise have
 * to be migrated by hand.
 *
 * Keep these statements identical to schema.sql. A fresh install runs schema.sql and an
 * existing one runs this, and the two must not drift.
 *
 * @var array<string, string> $newTables
 */
$newTables = [
    'sss_enrolments' => "CREATE TABLE `sss_enrolments` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`             CHAR(36) NOT NULL,
  `bc_supervisor_id` BIGINT UNSIGNED NOT NULL,
  `branch_id`        BIGINT UNSIGNED NOT NULL,
  `enrolment_date`   DATE NOT NULL,
  `apy_count`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pmjjby_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pmsby_count`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pmjdy_count`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `remarks`          VARCHAR(500) NULL,
  `source`           ENUM('app','panel') NOT NULL DEFAULT 'app',
  `status`           ENUM('submitted','reopened') NOT NULL DEFAULT 'submitted',
  `submitted_at`     DATETIME NULL,
  `reopened_by`      BIGINT UNSIGNED NULL,
  `reopened_at`      DATETIME NULL,
  `recorded_by`      BIGINT UNSIGNED NULL,
  `device_id`        BIGINT UNSIGNED NULL,
  `created_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sss_uuid` (`uuid`),
  UNIQUE KEY `uq_sss_day` (`bc_supervisor_id`, `enrolment_date`),
  KEY `ix_sss_branch_date` (`branch_id`, `enrolment_date`),
  KEY `ix_sss_date` (`enrolment_date`),
  KEY `ix_sss_status` (`status`),
  CONSTRAINT `fk_sss_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sss_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sss_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sss_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'sss_targets' => "CREATE TABLE `sss_targets` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bc_supervisor_id` BIGINT UNSIGNED NOT NULL,
  `target_month`     DATE NOT NULL,
  `apy_target`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pmjjby_target`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pmsby_target`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pmjdy_target`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `notes`            VARCHAR(255) NULL,
  `created_by`       BIGINT UNSIGNED NULL,
  `created_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sss_target_month` (`bc_supervisor_id`, `target_month`),
  KEY `ix_sss_targets_month` (`target_month`),
  CONSTRAINT `fk_sss_target_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    /* The scheme figures a signed inspection was signed against. --------------
     *
     * See the note above this table in schema.sql for why figures that can be derived are
     * stored at all: an inspection is a sheet in a file, and the days behind it can be
     * corrected after it is signed. */
    'inspection_sss' => "CREATE TABLE `inspection_sss` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspection_id`  BIGINT UNSIGNED NOT NULL,
  `period_from`    DATE NOT NULL,
  `period_to`      DATE NOT NULL,
  `working_days`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `days_reported`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `days_reopened`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `apy_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `pmjjby_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `pmsby_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `pmjdy_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `apy_target`     INT UNSIGNED NOT NULL DEFAULT 0,
  `pmjjby_target`  INT UNSIGNED NOT NULL DEFAULT 0,
  `pmsby_target`   INT UNSIGNED NOT NULL DEFAULT 0,
  `pmjdy_target`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME NULL,
  `updated_at`     DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_sss` (`inspection_id`),
  CONSTRAINT `fk_insp_sss_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

/**
 * Columns to add, in order. `after` keeps the physical column order readable,
 * which matters when someone inspects the table by hand.
 *
 * @var array<int, array{0:string, 1:string, 2:string, 3:?string}> $columns
 */
$columns = [
    /* Branch: Regional Office is `region`; Zone is new. --------------------- */
    ['branches', 'zone', 'VARCHAR(120) NULL', 'region'],

    /* BCA identity, from the Add BCA screen. -------------------------------- */
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

    /* SSS: submitted figures lock, and only an Admin can hand the day back. ---
     *
     * These four are also in the `sss_enrolments` CREATE TABLE above, for a database old
     * enough to have no SSS at all. This pass is for the one in between: SSS arrived in an
     * earlier update, so the table exists without them. `status` defaults to 'submitted'
     * so the days already in the register read as reported rather than unfinished. */
    ['sss_enrolments', 'status', "ENUM('submitted','reopened') NOT NULL DEFAULT 'submitted'", 'source'],
    ['sss_enrolments', 'submitted_at', 'DATETIME NULL', 'status'],
    ['sss_enrolments', 'reopened_by', 'BIGINT UNSIGNED NULL', 'submitted_at'],
    ['sss_enrolments', 'reopened_at', 'DATETIME NULL', 'reopened_by'],

    /* The window the scheme figures on a printed inspection cover. ------------
     *
     * Nullable with no default, and nothing backfills it: an inspection signed before this
     * existed measured no window, and inventing one for it would put figures on a reprint
     * that were never on the sheet somebody signed. */
    ['inspections', 'sss_from', 'DATE NULL', 'photo_count'],
    ['inspections', 'sss_to', 'DATE NULL', 'sss_from'],
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
    // Item 24 of the printed inspection form is the assessment: Excellent, Good,
    // Satisfactory or Poor. Appended after the ten visit-verification outcomes rather than
    // replacing them — an ENUM is stored as an integer index, so dropping the old members
    // would re-map every inspection already recorded, and those records have to keep
    // reading the way they were answered.
    [
        'inspections',
        'result',
        "ENUM('work_verified','partially_verified','not_satisfactory','customer_not_found',"
            . "'bc_not_present','visit_not_genuine','incorrect_information','gps_issue',"
            . "'photo_issue','other',"
            . "'excellent','good','satisfactory','poor') NULL",
        'excellent',
    ],
];

/** Indexes worth adding for the new report filters. */
/**
 * Column defaults that changed. Checked against COLUMN_DEFAULT rather than
 * COLUMN_TYPE, which is why these cannot live in $modifications: a type comparison
 * cannot see a default, so the same ALTER would be re-applied on every run.
 *
 * @var array<int, array{0:string, 1:string, 2:string, 3:string}> $defaults
 */
$defaults = [
    // A repayment row inserted without a mode used to claim a cash collection,
    // because 'Cash' was the column default. Recovery follow-up is this company's
    // work; taking money is not, and a default must not assert otherwise.
    ['recoveries', 'payment_mode', "VARCHAR(40) NOT NULL DEFAULT 'Other'", 'Other'],
];

$indexes = [
    ['bc_supervisors', 'ix_bc_iibf', '(`iibf_number`)'],
    ['bc_supervisors', 'ix_bc_dra', '(`dra_id`)'],
    ['loan_accounts', 'ix_accounts_asset_class', '(`asset_classification`)'],
    ['krm_ots_cases', 'ix_krm_final', '(`final_status`)'],
    ['krm_ots_cases', 'ix_krm_response', '(`customer_response`)'],
    ['ckcc_renewals', 'ix_ckcc_final', '(`final_status`)'],
    ['ckcc_renewals', 'ix_ckcc_due', '(`renewal_due_date`)'],
    ['sss_enrolments', 'ix_sss_status', '(`status`)'],
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

function columnDefault(string $database, string $table, string $column): ?string
{
    $value = Database::scalar(
        'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c',
        ['db' => $database, 't' => $table, 'c' => $column]
    );

    return $value === null ? null : trim((string) $value, "'");
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

/* Tables ------------------------------------------------------------------- */

/**
 * Tables this run is about to create.
 *
 * The column pass below needs to know. Several columns are listed there *and* included in a
 * CREATE TABLE here, because they have to reach two different databases: one old enough to have
 * no such table at all, and one where the table arrived in an earlier update without them.
 *
 * When the table is created, the columns come with it and the column pass finds them already
 * present. In a dry run nothing is created, so the column pass used to find the table missing
 * and report four failures for work it was about to do correctly — which is what an operator
 * saw when they pressed Preview: "4 failed — table missing, run migrate.php first", on an
 * update that then applied cleanly. The preview has to be trustworthy or nobody will press the
 * button after it.
 *
 * @var array<string, true> $pendingTables
 */
$pendingTables = [];

foreach ($newTables as $table => $definition) {
    if (tableExists($database, $table)) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        printf("  +  CREATE TABLE `%s`\n", $table);
        $pendingTables[$table] = true;
        $applied++;
        continue;
    }

    try {
        $pdo->exec($definition);
        printf("  +  %s table created\n", $table);
        $applied++;
    } catch (Throwable $e) {
        printf("  !! %s table failed: %s\n", $table, $e->getMessage());
        $failed++;
    }
}

/* Columns ------------------------------------------------------------------ */

foreach ($columns as [$table, $column, $definition, $after]) {
    if (!tableExists($database, $table)) {
        // Created a few lines above, with this column already in it. Not a failure, and not a
        // change either: the CREATE TABLE was already counted.
        if (isset($pendingTables[$table])) {
            $skipped++;
            continue;
        }

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

/* Defaults ----------------------------------------------------------------- */

foreach ($defaults as [$table, $column, $definition, $expected]) {
    if (!columnExists($database, $table, $column)) {
        continue;
    }

    if (columnDefault($database, $table, $column) === $expected) {
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
        printf("  ~  %s.%s default is now %s\n", $table, $column, $expected);
        $applied++;
    } catch (Throwable $e) {
        printf("  !! %s.%s default failed: %s\n", $table, $column, $e->getMessage());
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

/* Form fields ------------------------------------------------------------- */

// Questions added to a form after it was installed. seed.php only builds a form
// that does not exist yet, so on a live database — where the KRM OTS and CKCC OD-2
// forms were installed months ago and carry real answers — a new question would
// never appear. Matched on field_key within the form, so running this twice adds
// nothing, and no existing row is touched.
//
// @var array<int, array{0:string, 1:string, 2:string, 3:string, 4:?string, 5:?string, 6:?array{0:string,1:string,2:string}}>
$formFields = [
    // Picking "Other" for occupation asks which, the way the reference app does.
    // Placed immediately after the dropdown it depends on.
    [
        'krm_ots', 'occupation_other', 'Which other occupation', 'text', null, 'occupation',
        ['occupation', 'equals', 'Other'],
    ],
    [
        'ckcc_od2', 'occupation_other', 'Which other occupation', 'text', null, 'occupation',
        ['occupation', 'equals', 'Other'],
    ],
];

if (tableExists($database, 'visit_forms') && tableExists($database, 'visit_form_fields')) {
    foreach ($formFields as [$visitType, $key, $label, $type, $options, $afterKey, $condition]) {
        $forms = $pdo->prepare(
            'SELECT id FROM visit_forms WHERE visit_type = :type'
        );
        $forms->execute(['type' => $visitType]);

        foreach ($forms->fetchAll(PDO::FETCH_COLUMN) as $formId) {
            $existing = $pdo->prepare(
                'SELECT id FROM visit_form_fields WHERE form_id = :form AND field_key = :key LIMIT 1'
            );
            $existing->execute(['form' => $formId, 'key' => $key]);

            if ($existing->fetchColumn() !== false) {
                $skipped++;
                continue;
            }

            // Sits just after the question it hangs off, so the form still reads in
            // order. Falls to the end when that question is not on this form.
            $after = $pdo->prepare(
                'SELECT sort_order FROM visit_form_fields WHERE form_id = :form AND field_key = :key LIMIT 1'
            );
            $after->execute(['form' => $formId, 'key' => $afterKey]);
            $afterOrder = $after->fetchColumn();

            $sortOrder = $afterOrder === false
                ? (int) $pdo->query(
                    sprintf('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM visit_form_fields WHERE form_id = %d', (int) $formId)
                )->fetchColumn()
                : (int) $afterOrder + 1;

            if ($dryRun) {
                printf("  +  visit_form_fields.%s on form #%d (%s)\n", $key, (int) $formId, $visitType);
                $applied++;
                continue;
            }

            try {
                $conditionId = null;

                if ($condition !== null) {
                    $parent = $pdo->prepare(
                        'SELECT id FROM visit_form_fields WHERE form_id = :form AND field_key = :key LIMIT 1'
                    );
                    $parent->execute(['form' => $formId, 'key' => $condition[0]]);
                    $found = $parent->fetchColumn();
                    $conditionId = $found === false ? null : (int) $found;
                }

                $insert = $pdo->prepare(
                    'INSERT INTO visit_form_fields
                        (form_id, field_key, label, field_type, options, is_required, sort_order, is_active,
                         condition_field_id, condition_operator, condition_value, created_at, updated_at)
                     VALUES
                        (:form, :key, :label, :type, :options, 0, :sort_order, 1,
                         :condition_field_id, :condition_operator, :condition_value, NOW(), NOW())'
                );
                $insert->execute([
                    'form' => $formId,
                    'key' => $key,
                    'label' => $label,
                    'type' => $type,
                    'options' => $options,
                    'sort_order' => $sortOrder,
                    'condition_field_id' => $conditionId,
                    'condition_operator' => $conditionId === null ? null : $condition[1],
                    'condition_value' => $conditionId === null ? null : $condition[2],
                ]);

                printf("  +  visit_form_fields.%s added to form #%d (%s)\n", $key, (int) $formId, $visitType);
                $applied++;
            } catch (Throwable $e) {
                printf("  !! visit_form_fields.%s on form #%d failed: %s\n", $key, (int) $formId, $e->getMessage());
                $failed++;
            }
        }
    }
}

/* Settings rows added since this database was built ------------------------ */

/*
 * Settings are read through a code default until the row exists, so a missing row is not a
 * bug — the office letterhead prints correctly on a site that has never been re-seeded.
 *
 * What it does break is the settings screen, which would show five empty boxes for an office
 * that is in fact being printed on every inspection report. Somebody looking at that would
 * reasonably conclude it was not configured, and clear something that was working.
 *
 * seed.php owns the list, and only inserts keys that are absent, so this is one call and
 * cannot drift from what a fresh install gets.
 */
if (tableExists($database, 'system_settings')) {
    require_once __DIR__ . '/seed.php';

    $before = (int) Database::scalar('SELECT COUNT(*) FROM system_settings');

    if ($dryRun) {
        // Counting what is missing without inserting it means listing the keys, which would be
        // a second copy of seed.php's list. Naming the step is enough: it adds rows for
        // settings this build knows about and this database does not, and never overwrites one.
        printf("  +  system_settings: any settings rows this build adds (%d present)\n", $before);
        $applied++;
    } else {
        try {
            // seed.php reports its own totals, which reads as a second, contradictory line in
            // the middle of an upgrade log. This step does its own reporting.
            ob_start();

            try {
                lrms_seed_settings();
            } finally {
                ob_end_clean();
            }

            $added = (int) Database::scalar('SELECT COUNT(*) FROM system_settings') - $before;

            if ($added > 0) {
                printf("  +  system_settings: %d row(s) added\n", $added);
                $applied++;
            } else {
                $skipped++;
            }
        } catch (Throwable $e) {
            printf("  !! system_settings: %s\n", $e->getMessage());
            $failed++;
        }
    }
}

/* The BCA / BC Supervisor rename ------------------------------------------- */

/*
 * Two job titles swapped places, and some of the old ones are stored in the database rather
 * than written in the code.
 *
 * The agent at the outlet is a BCA; the panel account that monitors and inspects them is the
 * BC Supervisor. Until now the code called the agent "BC Supervisor" and the panel account
 * "Admin / Supervisor", which is not what the branch calls them and made every screen and
 * every printed report read wrongly.
 *
 * The screens were renamed in the code. These rows cannot be: `roles.name` is what the panel
 * prints for a role and `report_types.name` titles a report, and both were written once by
 * seed.php when the database was created. A fresh install gets the new wording from seed.php;
 * an existing one gets it here.
 *
 * Only exact matches for the old text are touched. Anything else is a name somebody chose,
 * and this is not the place to overrule it. That also makes the step idempotent: run it
 * twice and the second run finds nothing to do.
 *
 * The `bc_supervisors` table, its `bc_supervisor_id` foreign keys, the form field keys and
 * the API routes all keep their names. Renaming those would break every handset in the field
 * mid-shift — the app posts to those routes with those keys — and would rewrite the history
 * of records that were filed under them. The rename is what people read, not what the
 * columns are called.
 */
$renames = [
    ['roles', 'name', 'Admin / Supervisor', 'BC Supervisor'],
    ['roles', 'description', 'Full control. Monitors and inspects BC Supervisors; does not perform customer recovery visits.',
        'Full control. Monitors and inspects BCAs; does not perform customer recovery visits.'],
    ['roles', 'description', 'Field officer. Performs customer recovery visits through the Android app.',
        'Business Correspondent Agent. Performs customer recovery visits through the Android app.'],
    ['report_types', 'name', 'BC Supervisor Inspection Report', 'BCA Inspection Report'],
    ['report_types', 'name', 'BC Supervisor Performance', 'BCA Performance'],
    ['report_types', 'description', 'The BC Supervisor day-end submission the report deadline applies to.',
        'The BCA day-end submission the report deadline applies to.'],
    ['report_types', 'description', 'TYPE A — BC Supervisor customer recovery visits.',
        'TYPE A — BCA customer recovery visits.'],
    ['report_types', 'description', 'TYPE B — Admin/Supervisor verification of BC field work.',
        'TYPE B — BC Supervisor verification of BCA field work.'],
    ['report_types', 'description', 'Supervisor level visits, recovery and inspection outcomes.',
        'BCA level visits, recovery and inspection outcomes.'],
    ['inspection_forms', 'name', 'BC Supervisor Inspection', 'BCA Inspection'],
    ['inspection_forms', 'description', 'TYPE B: the Admin/Supervisor inspection of a BC outlet and its agent.',
        'TYPE B: the BC Supervisor inspection of a BC outlet and its agent.'],
];

/*
 * The agent role also loses the name 'BC Supervisor', but by the time the first rule above
 * has run there are two rows carrying that name — the admin role has just been given it. So
 * this one is matched on the slug as well. Without that guard the rename would depend on
 * which order the two rules happened to run in, and would rename the wrong role half the
 * time.
 */
$renames[] = ['roles', 'name', 'BC Supervisor', 'BCA', 'slug = :slug', ['slug' => Auth::ROLE_BC]];

foreach ($renames as $rename) {
    [$table, $column, $from, $to] = $rename;
    $extra = $rename[4] ?? null;
    $extraParams = $rename[5] ?? [];

    if (!tableExists($database, $table) || !columnExists($database, $table, $column)) {
        $skipped++;

        continue;
    }

    $where = sprintf('`%s` = :from', $column) . ($extra !== null ? ' AND ' . $extra : '');
    $whereParams = array_merge(['from' => $from], $extraParams);

    $matches = (int) Database::scalar(
        sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $table, $where),
        $whereParams
    );

    if ($matches === 0) {
        $skipped++;

        continue;
    }

    if ($dryRun) {
        printf("  +  %s.%s: %d row(s) renamed to \"%s\"\n", $table, $column, $matches, mb_strimwidth($to, 0, 52, '...'));
        $applied++;

        continue;
    }

    try {
        Database::update($table, [$column => $to, 'updated_at' => now()], $where, $whereParams);

        printf("  +  %s.%s: %d row(s) renamed to \"%s\"\n", $table, $column, $matches, mb_strimwidth($to, 0, 52, '...'));
        $applied++;
    } catch (Throwable $e) {
        printf("  !! %s.%s rename failed: %s\n", $table, $column, $e->getMessage());
        $failed++;
    }
}

/* Inspection form ---------------------------------------------------------- */

// The BC Supervisor's inspection of a BCA was replaced with the format the client
// issued: 27 numbered items about the BC outlet itself, in place of eleven questions
// about whether one customer visit had been done properly.
//
// seed.php only builds a form when none is installed, so on a live database — where the
// old form is the default and inspections are already recorded against it — the new one
// would never appear. It is installed here as another version, and the old form and its
// fields are left exactly as they are: an inspection points at the form it was filled in
// on, and Forms::fields() selects by form id, so a record from last month keeps printing
// the questions it was actually answered against. Nothing is deleted.
//
// The field list is required from seed.php rather than repeated here. That file is only
// function declarations — lrms_seed() is called by migrate.php, not on include — so there
// is one definition of this form and no way for the two installers to drift apart.
if (tableExists($database, 'inspection_forms') && tableExists($database, 'inspection_form_fields')) {
    require_once __DIR__ . '/seed.php';

    // Matched on a field key only the new form has, not on the form's name, so renaming
    // it in the panel cannot cause a second copy to be installed on the next run.
    $installed = (int) Database::scalar(
        'SELECT COUNT(*) FROM inspection_form_fields WHERE field_key = :key',
        ['key' => 'bca_name']
    );

    $inspectionFields = lrms_inspection_fields();

    if ($installed > 0) {
        $skipped++;
    } elseif ($dryRun) {
        printf(
            "  +  inspection_forms: BCA inspection, %d fields, and the default moves to it\n",
            count($inspectionFields)
        );
        $applied++;
    } else {
        try {
            $version = 1 + (int) Database::scalar('SELECT COALESCE(MAX(version), 0) FROM inspection_forms');

            $formId = Database::insert('inspection_forms', [
                'name' => 'BCA Inspection',
                'description' => 'TYPE B: the BC Supervisor inspection of a BC outlet and its agent.',
                'version' => $version,
                'is_active' => 1,
                'is_default' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            lrms_insert_inspection_fields($formId, $inspectionFields);

            Database::update(
                'inspection_forms',
                ['is_default' => 0, 'updated_at' => now()],
                'id <> :id',
                ['id' => $formId]
            );

            // Forms::defaultForm() reads this setting before it looks at is_default, so
            // the switch is not finished without it — the old form would keep being
            // served no matter which row is flagged.
            Settings::set('default_inspection_form_id', (string) $formId, 'forms');

            printf(
                "  +  inspection_forms: BCA inspection #%d v%d with %d fields, now the default\n",
                $formId,
                $version,
                count($inspectionFields)
            );
            $applied++;
        } catch (Throwable $e) {
            printf("  !! inspection_forms: %s\n", $e->getMessage());
            $failed++;
        }
    }
}

printf(
    "\n%s: %d change(s), %d already present, %d failed.\n",
    $dryRun ? 'Would apply' : 'Applied',
    $applied,
    $skipped,
    $failed
);

if (!$dryRun && $applied > 0 && PHP_SAPI === 'cli') {
    echo "\nRe-run database/seed.php to install the KRM OTS and CKCC OD-2 report forms:\n";
    echo "  php database/migrate.php --seed\n";
}

// The caller reads these when this file was included rather than run.
$GLOBALS['lrms_upgrade_result'] = [
    'applied' => $applied,
    'skipped' => $skipped,
    'failed' => $failed,
    'dry_run' => $dryRun,
];

// Only a command line gets an exit status. Included, the page still has a response to
// finish writing.
if (PHP_SAPI === 'cli') {
    exit($failed > 0 ? 1 : 0);
}
