#!/usr/bin/env bash
#
# DocuPilot AI — one-shot test runner.
#
# Boots a throwaway database and PHP dev server, installs the schema, then runs
# both smoke suites and tears everything down again.
#
#   ./tests/run-smoke.sh
#
# Requirements: php >= 8.2, composer install already run, a MySQL/MariaDB server
# reachable with the credentials below (override with environment variables).

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DB_NAME="${DP_DB_NAME:-docupilot_test}"
DB_USER="${DP_DB_USER:-root}"
DB_PASS="${DP_DB_PASS:-}"
DB_HOST="${DP_DB_HOST:-127.0.0.1}"
DB_PORT="${DP_DB_PORT:-3306}"
DB_SOCKET="${DP_DB_SOCKET:-}"
PORT="${DP_PORT:-8123}"

echo "==> Writing config/config.local.php for the test database"
cat > config/config.local.php <<PHPCONF
<?php
return [
    'app' => ['url' => 'http://127.0.0.1:${PORT}', 'debug' => true],
    'database' => [
        'host' => '${DB_HOST}',
        'port' => ${DB_PORT},
        'database' => '${DB_NAME}',
        'username' => '${DB_USER}',
        'password' => '${DB_PASS}',
        'socket' => '${DB_SOCKET}',
    ],
    'session' => ['secure' => false],
    'mail' => ['log_only' => true],
];
PHPCONF

echo "==> Installing a fresh schema"
php database/migrate.php --fresh || exit 1

echo "==> Clearing rate-limit counters and generated files"
rm -rf storage/logs/throttle
rm -f storage/generated/*.pdf

echo "==> Starting the PHP development server on port ${PORT}"
php -S "127.0.0.1:${PORT}" -t public public/index.php > storage/logs/dev-server.log 2>&1 &
SERVER_PID=$!

cleanup() {
    kill "${SERVER_PID}" 2>/dev/null
    rm -f config/config.local.php
}
trap cleanup EXIT

sleep 2

STATUS=0

echo
echo "==> Functional smoke test"
php tests/smoke.php || STATUS=1

echo
echo "==> HTTP smoke test"
php tests/http-smoke.php "http://127.0.0.1:${PORT}" || STATUS=1

echo
if [ "$STATUS" -eq 0 ]; then
    echo "All suites passed."
else
    echo "Some checks failed — see the output above."
    echo "Server log: storage/logs/dev-server.log"
fi

exit "$STATUS"
