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
// BC Supervisor identity fields from the BC creation screen.

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

foreach ($newTables as $table => $definition) {
    if (tableExists($database, $table)) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        printf("  +  CREATE TABLE `%s`\n", $table);
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

/* Inspection form ---------------------------------------------------------- */

// The Admin's inspection of a BC Supervisor was replaced with the format the client
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
            "  +  inspection_forms: BC Supervisor inspection, %d fields, and the default moves to it\n",
            count($inspectionFields)
        );
        $applied++;
    } else {
        try {
            $version = 1 + (int) Database::scalar('SELECT COALESCE(MAX(version), 0) FROM inspection_forms');

            $formId = Database::insert('inspection_forms', [
                'name' => 'BC Supervisor Inspection',
                'description' => 'TYPE B: the Admin/Supervisor inspection of a BC outlet and its agent.',
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
                "  +  inspection_forms: BC Supervisor inspection #%d v%d with %d fields, now the default\n",
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
