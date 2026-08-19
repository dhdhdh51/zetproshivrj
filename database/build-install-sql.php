#!/usr/bin/env php
<?php

declare(strict_types=1);

// Generates database/install.sql — the single file a hosting panel can import.
//
//   php database/build-install-sql.php
//
// WHY
// schema.sql creates the 40 tables and nothing else. An installation also needs
// the roles, report types, settings, the four configurable forms and an admin
// account, and those are created by seed.php — which needs a command line. On
// shared hosting there often isn't one: the operator has phpMyAdmin and a file
// manager. install.sql is the whole installation in one importable file.
//
// HOW
// By running the real migrate + seed against a scratch database and dumping the
// result. The generated file therefore cannot drift from what the PHP path
// produces — if seed.php gains a form, install.sql gains it on the next build.
//
// The dump deliberately contains no CREATE DATABASE and no USE, so it imports
// into whichever database is already selected in phpMyAdmin.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This generator may only be run from the command line.\n");
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$output = __DIR__ . '/install.sql';
$scratch = 'lrms_install_build';

/* -------------------------------------------------------------------------- */
/* Locate the client tools                                                    */
/* -------------------------------------------------------------------------- */

function lrms_tool(string ...$candidates): string
{
    foreach ($candidates as $candidate) {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));

        if ($path !== '') {
            return $path;
        }
    }

    fwrite(STDERR, sprintf("Could not find any of: %s\n", implode(', ', $candidates)));
    exit(1);
}

$client = lrms_tool('mariadb', 'mysql');
$dumper = lrms_tool('mariadb-dump', 'mysqldump');

/* -------------------------------------------------------------------------- */
/* Connection arguments, taken from the app's own configuration               */
/* -------------------------------------------------------------------------- */

$db = Config::get('database');
$socket = (string) ($db['socket'] ?? '');
$args = [];

if ($socket !== '') {
    $args[] = '--socket=' . escapeshellarg($socket);
} else {
    $args[] = '--host=' . escapeshellarg((string) ($db['host'] ?? '127.0.0.1'));
    $args[] = '--port=' . escapeshellarg((string) ($db['port'] ?? 3306));
}

$args[] = '--user=' . escapeshellarg((string) ($db['username'] ?? 'root'));

$password = (string) ($db['password'] ?? '');

if ($password !== '') {
    // Passed via the environment so it never appears in the process list.
    putenv('MYSQL_PWD=' . $password);
}

$connect = implode(' ', $args);

function lrms_run(string $command, string $what): void
{
    exec($command . ' 2>&1', $out, $code);

    if ($code !== 0) {
        fwrite(STDERR, sprintf("%s failed:\n%s\n", $what, implode("\n", $out)));
        exit(1);
    }
}

echo "Building database/install.sql\n";

/* -------------------------------------------------------------------------- */
/* 1. Build the installation in a scratch database                            */
/* -------------------------------------------------------------------------- */

echo "  scratch database: {$scratch}\n";

lrms_run(
    sprintf(
        '%s %s -e %s',
        escapeshellarg($client),
        $connect,
        escapeshellarg(sprintf(
            'DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
            $scratch,
            $scratch
        ))
    ),
    'Creating the scratch database'
);

// Run the real installer against it, so this file is always what the code
// produces rather than a hand-maintained copy.
echo "  running migrate --fresh --seed against it\n";

lrms_run(
    sprintf(
        'LRMS_DB_NAME=%s %s %s --fresh --seed',
        escapeshellarg($scratch),
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__ . '/migrate.php')
    ),
    'Seeding the scratch database'
);

/* -------------------------------------------------------------------------- */
/* 2. Dump it                                                                 */
/* -------------------------------------------------------------------------- */

echo "  dumping\n";

$dumpFile = $output . '.tmp';

lrms_run(
    sprintf(
        '%s %s %s > %s',
        escapeshellarg($dumper),
        $connect . ' '
            // --no-tablespaces and --skip-lock-tables keep the dump usable on
            // shared hosting, where the account often lacks PROCESS and
            // LOCK TABLES. --complete-insert makes the file survive a column
            // being added later.
            . '--no-tablespaces --skip-lock-tables --complete-insert '
            . '--default-character-set=utf8mb4 --skip-set-charset '
            . '--add-drop-table --skip-comments --skip-add-locks --skip-disable-keys',
        escapeshellarg($scratch),
        escapeshellarg($dumpFile)
    ),
    'Dumping the scratch database'
);

$dump = (string) file_get_contents($dumpFile);
@unlink($dumpFile);

// Belt and braces: a CREATE DATABASE or USE would send the import into the wrong
// database, which on shared hosting the account is not even allowed to create.
foreach (['CREATE DATABASE', 'USE `'] as $forbidden) {
    if (stripos($dump, $forbidden) !== false) {
        fwrite(STDERR, "The dump contains {$forbidden}, which would target the wrong database.\n");
        exit(1);
    }
}

/* -------------------------------------------------------------------------- */
/* 3. Wrap it with a header and the settings an import needs                  */
/* -------------------------------------------------------------------------- */

$tables = (int) Database::scalar(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db',
    ['db' => $scratch]
);

$header = <<<SQL
-- =============================================================================
--  LRMS — Loan Recovery Management System
--  COMPLETE INSTALLATION — import this one file and the application is ready.
--
--  Generated by database/build-install-sql.php. Do not edit by hand: it is
--  rebuilt from schema.sql and seed.php, which are the source of truth.
--
--  WHAT IT CONTAINS
--    * all {$tables} tables
--    * the three roles, the 14 report types and the default settings
--    * the customer visit form, the KRM OTS and CKCC OD-2 field visit
--      verification report forms, and the BC Supervisor inspection form
--    * one Admin/Supervisor account
--
--  HOW TO IMPORT (no command line needed)
--    1. Create an empty database in your hosting panel.
--    2. Open phpMyAdmin and SELECT that database on the left.
--    3. Import tab -> choose this file -> Go.
--
--  FIRST SIGN-IN
--    username: admin
--    password: ChangeMe@123
--
--    >> This password is published in the source code. The application forces a
--    >> change at first sign-in; do that immediately, before anyone else does.
--
--  IT DOES NOT CONTAIN
--    No CREATE DATABASE and no USE, so it imports into whichever database is
--    selected. No demo data. Re-importing DROPS the tables and starts over,
--    which destroys everything already recorded.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

SQL;

$footer = <<<SQL

SET FOREIGN_KEY_CHECKS = 1;

-- End of LRMS installation.
SQL;

file_put_contents($output, $header . "\n" . trim($dump) . "\n" . $footer . "\n");

/* -------------------------------------------------------------------------- */
/* 4. Clean up and report                                                     */
/* -------------------------------------------------------------------------- */

lrms_run(
    sprintf(
        '%s %s -e %s',
        escapeshellarg($client),
        $connect,
        escapeshellarg(sprintf('DROP DATABASE IF EXISTS `%s`;', $scratch))
    ),
    'Dropping the scratch database'
);

printf(
    "\nWrote %s\n  tables : %d\n  size   : %s\n  lines  : %d\n",
    $output,
    $tables,
    number_format(filesize($output) / 1024, 1) . ' KB',
    count(file($output))
);

echo "\nImport it through phpMyAdmin with the target database selected.\n";
