<?php

declare(strict_types=1);

/**
 * LRMS schema installer / updater.
 *
 *   php database/migrate.php            # create missing tables only
 *   php database/migrate.php --fresh    # DROP and recreate every table (destructive)
 *   php database/migrate.php --seed     # also load database/seed.php
 *   php database/migrate.php --demo     # seed + a small demo data set for testing
 *   php database/migrate.php --status   # list tables and row counts
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This installer may only be run from the command line.\n");
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/sql.php';

use App\Core\Database;

$options = array_slice($argv, 1);
$fresh = in_array('--fresh', $options, true);
$seed = in_array('--seed', $options, true) || in_array('--demo', $options, true);
$demo = in_array('--demo', $options, true);
$status = in_array('--status', $options, true);

$expected = [
    'roles', 'users', 'branches', 'branch_managers', 'bc_supervisors',
    'excel_mapping_templates', 'excel_imports', 'excel_import_errors',
    'loan_accounts', 'account_assignments',
    'visit_forms', 'visit_form_fields', 'inspection_forms', 'inspection_form_fields',
    'devices', 'api_tokens', 'otp_codes', 'sync_batches',
    'visits', 'visit_form_values', 'visit_gps', 'visit_photos',
    'inspections', 'inspection_form_values', 'inspection_gps', 'inspection_photos',
    'recoveries', 'promises', 'followups',
    'krm_ots_cases', 'ckcc_renewals',
    'attendance', 'targets',
    'sss_enrolments', 'sss_targets',
    'report_types', 'report_submissions', 'report_exports',
    'notifications', 'documents', 'system_settings', 'audit_logs',
];

function existingTables(): array
{
    $rows = Database::select('SHOW TABLES');
    $tables = [];

    foreach ($rows as $row) {
        $tables[] = (string) reset($row);
    }

    return $tables;
}

if ($status) {
    $tables = existingTables();
    printf("Database: %s\n", (string) App\Core\Config::get('database.database'));
    printf("Tables:   %d found, %d expected\n\n", count($tables), count($expected));

    foreach ($expected as $table) {
        if (!in_array($table, $tables, true)) {
            printf("  %-28s MISSING\n", $table);
            continue;
        }

        printf("  %-28s %8d rows\n", $table, (int) Database::scalar(sprintf('SELECT COUNT(*) FROM `%s`', $table)));
    }

    $extra = array_diff($tables, $expected);
    if ($extra !== []) {
        printf("\n  Extra tables not in the manifest: %s\n", implode(', ', $extra));
    }

    exit(0);
}

/* -------------------------------------------------------------------------- */
/* Install                                                                    */
/* -------------------------------------------------------------------------- */

$existing = existingTables();

if ($existing !== [] && !$fresh) {
    $missing = array_diff($expected, $existing);

    if ($missing === []) {
        echo "Schema is already up to date. Use --fresh to rebuild (destructive) or --status to inspect.\n";

        if ($seed) {
            require __DIR__ . '/seed.php';
            lrms_seed($demo);
        }

        exit(0);
    }

    printf("Missing tables detected: %s\n", implode(', ', $missing));
    echo "The schema file recreates tables in dependency order, so a partial install is\n";
    echo "rebuilt with --fresh. Re-run with --fresh once you have a backup.\n";
    exit(1);
}

$sqlFile = __DIR__ . '/schema.sql';

if (!is_file($sqlFile)) {
    fwrite(STDERR, "database/schema.sql not found.\n");
    exit(1);
}

echo "Installing LRMS schema...\n";

$statements = lrms_split_sql((string) file_get_contents($sqlFile));
$pdo = Database::pdo();
$count = 0;

foreach ($statements as $statement) {
    try {
        $pdo->exec($statement);
        $count++;
    } catch (Throwable $e) {
        fwrite(STDERR, "\nFailed statement:\n" . substr($statement, 0, 300) . "\n\n" . $e->getMessage() . "\n");
        exit(1);
    }
}

printf("  %d statements executed.\n", $count);

$tables = existingTables();
$missing = array_diff($expected, $tables);

if ($missing !== []) {
    fwrite(STDERR, 'Schema incomplete, missing: ' . implode(', ', $missing) . "\n");
    exit(1);
}

printf("  %d tables present.\n", count($tables));

if ($seed) {
    require __DIR__ . '/seed.php';
    lrms_seed($demo);
}

echo "Done.\n";
