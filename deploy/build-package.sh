#!/usr/bin/env bash
#
# Builds the upload-ready hosting package.
#
#   bash deploy/build-package.sh              # -> dist/lrms-<version>.zip
#   bash deploy/build-package.sh 1.0.0        # name it explicitly
#
# The repository contains a lot that must never sit on a web server: the Android
# project, the test suites, CI workflows, the git history and any uploads left
# over from local testing. This produces an archive with only the files the
# application needs to run, laid out so it unzips straight into public_html.
#
# See docs/HOSTING-CYBERPANEL.md for what to do with the result.

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

VERSION="${1:-$(date +%Y%m%d-%H%M)}"
NAME="lrms-${VERSION}"
OUT="${ROOT}/dist"
STAGE="${OUT}/${NAME}"

echo "LRMS hosting package"
echo "  version : ${VERSION}"
echo "  source  : ${ROOT}"
echo

# ---------------------------------------------------------------------------
# 1. Refuse to package something that is obviously broken.
# ---------------------------------------------------------------------------

if ! command -v php > /dev/null 2>&1; then
    echo "  !! php is not on PATH; cannot verify the sources." >&2
    exit 1
fi

echo "Checking PHP syntax across the files being shipped..."
LINT_FAILED=0

while IFS= read -r -d '' file; do
    if ! php -l "$file" > /dev/null 2>&1; then
        echo "  !! syntax error: ${file}" >&2
        LINT_FAILED=1
    fi
done < <(find app bin config database public resources routes -name '*.php' -print0)

if [ "$LINT_FAILED" -ne 0 ]; then
    echo "Refusing to build a package containing a syntax error." >&2
    exit 1
fi

echo "  all files parse."
echo

# ---------------------------------------------------------------------------
# 2. Stage only what the application needs at runtime.
# ---------------------------------------------------------------------------

rm -rf "${STAGE}"
mkdir -p "${STAGE}"

# Application code and assets.
for path in app bin config database public resources routes; do
    cp -R "${path}" "${STAGE}/"
done

cp .htaccess "${STAGE}/.htaccess"
cp composer.json "${STAGE}/composer.json"

# Writable directories, created empty: local uploads and logs must not travel.
for dir in storage/uploads storage/generated storage/logs; do
    mkdir -p "${STAGE}/${dir}"
    touch "${STAGE}/${dir}/.gitkeep"
done

# Never ship local credentials. config/config.php is the placeholder the
# operator edits in place; config.local.php is a developer's own machine.
rm -f "${STAGE}/config/config.local.php"

# The importer writes here at runtime; ship it empty.
rm -rf "${STAGE}/storage/uploads/imports"
mkdir -p "${STAGE}/storage/uploads/imports"
touch "${STAGE}/storage/uploads/imports/.gitkeep"

# Deployment helpers the operator will want on the server.
mkdir -p "${STAGE}/deploy"
cp deploy/preflight.php "${STAGE}/deploy/preflight.php"
cp deploy/openlitespeed-rewrite.conf "${STAGE}/deploy/openlitespeed-rewrite.conf"
cp docs/HOSTING-CYBERPANEL.md "${STAGE}/HOSTING-CYBERPANEL.md"

# ---------------------------------------------------------------------------
# 3. Prove nothing sensitive or oversized slipped in.
# ---------------------------------------------------------------------------

echo "Verifying the staged package..."

FORBIDDEN=0

check_absent() {
    if [ -e "${STAGE}/$1" ]; then
        echo "  !! $1 must not be in the package" >&2
        FORBIDDEN=1
    fi
}

for path in android .github tests docs .git .gitignore config/config.local.php \
            vendor node_modules dist; do
    check_absent "$path"
done

# Any file that looks like a credential or a database dump.
while IFS= read -r -d '' file; do
    echo "  !! unexpected file in package: ${file#"${STAGE}/"}" >&2
    FORBIDDEN=1
done < <(find "${STAGE}" \
    \( -name '*.sql.gz' -o -name '*.jks' -o -name '*.keystore' -o -name '.env' \
       -o -name 'config.local.php' -o -name '*.log' \) -print0)

if [ "$FORBIDDEN" -ne 0 ]; then
    echo "Refusing to ship the package." >&2
    exit 1
fi

# The one .sql file that is meant to be there.
if [ ! -f "${STAGE}/database/schema.sql" ]; then
    echo "  !! database/schema.sql is missing" >&2
    exit 1
fi

# The front controller must be present, or the site will 404 everywhere.
for required in public/index.php app/bootstrap.php config/config.php \
                database/migrate.php database/seed.php database/upgrade.php; do
    if [ ! -f "${STAGE}/${required}" ]; then
        echo "  !! ${required} is missing" >&2
        exit 1
    fi
done

echo "  contents look right."
echo

# ---------------------------------------------------------------------------
# 4. Archive.
# ---------------------------------------------------------------------------

cd "${OUT}"
rm -f "${NAME}.zip" "${NAME}.tar.gz"

# Track the name we actually produced. Globbing for it afterwards would trip
# `set -o pipefail` on the format that was not built.
if command -v zip > /dev/null 2>&1; then
    zip -rq "${NAME}.zip" "${NAME}"
    ARCHIVE="${NAME}.zip"
else
    echo "  zip not found; falling back to tar.gz" >&2
    tar -czf "${NAME}.tar.gz" "${NAME}"
    ARCHIVE="${NAME}.tar.gz"
fi

if [ ! -s "${ARCHIVE}" ]; then
    echo "  !! ${ARCHIVE} was not written" >&2
    exit 1
fi

sha256sum "${ARCHIVE}" > "${ARCHIVE}.sha256"

FILE_COUNT=$(find "${NAME}" -type f | wc -l)

echo "Built dist/${ARCHIVE}"
printf '  files : %s\n' "${FILE_COUNT}"
printf '  size  : %s\n' "$(du -h "${ARCHIVE}" | cut -f1)"
printf '  sha256: %s\n' "$(cut -d' ' -f1 "${ARCHIVE}.sha256")"
echo
echo "Next: upload it to /home/<your-domain>/public_html and follow"
echo "HOSTING-CYBERPANEL.md — the document root has to point at public_html/public."
