<?php

declare(strict_types=1);

/**
 * DocuPilot AI — installer / migration runner (CLI).
 *
 *   php database/migrate.php            Install schema (if needed) + run pending migrations
 *   php database/migrate.php --fresh    Drop every application table, then re-install
 *   php database/migrate.php --status   Show what is installed / pending
 *
 * On cPanel you can simply import database/schema.sql through phpMyAdmin instead.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\Settings;

const APP_TABLES = [
    'activity_logs', 'email_verifications', 'password_resets', 'settings', 'email_logs',
    'share_links', 'payments', 'subscriptions', 'ai_usage', 'ai_generations',
    'document_items', 'documents', 'document_templates', 'clients', 'business_profiles',
    'plans', 'users',
];

$options = array_slice($argv, 1);
$fresh = in_array('--fresh', $options, true);
$statusOnly = in_array('--status', $options, true);

/**
 * Split a SQL dump into individual statements.
 *
 * @return array<int, string>
 */
function sql_statements(string $sql): array
{
    $lines = preg_split('/\R/', $sql) ?: [];
    $clean = [];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $clean[] = $line;
    }

    $statements = [];
    foreach (explode(';', implode("\n", $clean)) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }

    return $statements;
}

function table_exists(string $table): bool
{
    try {
        return Database::selectOne('SHOW TABLES LIKE :t', ['t' => $table]) !== null;
    } catch (Throwable $e) {
        return false;
    }
}

function applied_migrations(): array
{
    try {
        $value = Database::scalar('SELECT `value` FROM settings WHERE `key` = :k', ['k' => 'migrations_applied']);
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $e) {
        return [];
    }
}

echo "DocuPilot AI — database setup\n";
echo str_repeat('-', 50) . "\n";

if (!Database::isConnected()) {
    fwrite(STDERR, "Cannot connect to the database.\n  " . (string) Database::lastError() . "\n");
    fwrite(STDERR, "Check the 'database' section of config/config.php.\n");
    exit(1);
}

echo "Connected to database: " . (string) config('database.database') . "\n";

$installed = table_exists('users') && table_exists('settings');
$migrationFiles = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($migrationFiles);
$applied = applied_migrations();

if ($statusOnly) {
    echo 'Schema installed : ' . ($installed ? 'yes' : 'no') . "\n";
    echo "Migrations:\n";
    if ($migrationFiles === []) {
        echo "  (none)\n";
    }
    foreach ($migrationFiles as $file) {
        $name = basename($file);
        echo '  [' . (in_array($name, $applied, true) ? 'x' : ' ') . '] ' . $name . "\n";
    }
    exit(0);
}

if ($fresh) {
    echo "Dropping existing tables...\n";
    Database::statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach (APP_TABLES as $table) {
        Database::statement(sprintf('DROP TABLE IF EXISTS `%s`', $table));
    }
    Database::statement('SET FOREIGN_KEY_CHECKS = 1');
    $installed = false;
}

if (!$installed) {
    echo "Importing database/schema.sql ...\n";
    $sql = (string) file_get_contents(__DIR__ . '/schema.sql');
    $count = 0;

    foreach (sql_statements($sql) as $statement) {
        Database::statement($statement);
        $count++;
    }

    echo "  {$count} statements executed.\n";
    echo "  Default admin: admin@docupilot.ai / Admin@12345 (change it after the first login)\n";
} else {
    echo "Schema already installed.\n";
}

$pending = array_values(array_filter(
    $migrationFiles,
    static fn (string $file): bool => !in_array(basename($file), applied_migrations(), true)
));

if ($pending === []) {
    echo "No pending migrations.\n";
} else {
    foreach ($pending as $file) {
        echo 'Applying migration ' . basename($file) . " ...\n";
        foreach (sql_statements((string) file_get_contents($file)) as $statement) {
            Database::statement($statement);
        }
        $applied = applied_migrations();
        $applied[] = basename($file);
        Settings::set('migrations_applied', json_encode(array_values(array_unique($applied))), 'system');
    }
}

echo "Done.\n";
