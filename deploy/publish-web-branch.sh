#!/usr/bin/env bash
#
# Publishes the `web-app` branch: the web application only, ready to deploy.
#
#   bash deploy/publish-web-branch.sh              # build it locally
#   bash deploy/publish-web-branch.sh --push       # build and push to origin
#
# WHY THIS EXISTS
# The main branch carries the Android project, four test suites, CI workflows and
# the git history. None of that belongs on a web server. This branch contains
# only what the application needs to run, so a server can `git clone` it directly
# or you can use GitHub's "Download ZIP" and upload the result.
#
# HOW IT IS BUILT
# The branch shares no history with main: its first commit is a root commit, and
# each publish adds one commit on top of the last so that `git pull` on the server
# fast-forwards. Never commit to it by hand — a publish would then be rejected.
# Change the application on the normal branch and re-run this.
#
# Your server's credentials live in config/config.local.php, which this branch
# ignores, so `git pull` on the server never conflicts with your settings.

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

BRANCH="${WEB_BRANCH:-web-app}"
PUSH=0

for arg in "$@"; do
    case "$arg" in
        --push) PUSH=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

SOURCE_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
SOURCE_SHA="$(git rev-parse --short HEAD)"

echo "Publishing the ${BRANCH} branch"
echo "  from  : ${SOURCE_BRANCH} @ ${SOURCE_SHA}"
echo

if [ -n "$(git status --porcelain)" ]; then
    echo "  !! The working tree has uncommitted changes." >&2
    echo "     Commit them first, so the published branch matches a real commit." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 1. Stage the runtime files, reusing the packager so the two cannot disagree.
# ---------------------------------------------------------------------------

VERSION="${SOURCE_SHA}"
bash deploy/build-package.sh "${VERSION}" > /dev/null
STAGE="${ROOT}/dist/lrms-${VERSION}"

if [ ! -d "${STAGE}" ]; then
    echo "  !! the packager did not produce ${STAGE}" >&2
    exit 1
fi

echo "Staged $(find "${STAGE}" -type f | wc -l) files."

# ---------------------------------------------------------------------------
# 2. Add the things that only make sense on the deployment branch.
# ---------------------------------------------------------------------------

# Credentials and runtime output must never be tracked here.
cat > "${STAGE}/.gitignore" <<'GITIGNORE'
# Your server's settings live here. Never committed, so `git pull` on the
# server will not conflict with them.
/config/config.local.php

# Runtime output: uploads, generated exports and logs.
/storage/uploads/*
!/storage/uploads/.gitkeep
/storage/generated/*
!/storage/generated/.gitkeep
/storage/logs/*
!/storage/logs/.gitkeep

*.log
.DS_Store
GITIGNORE

# A stamp so you can tell what is actually deployed. Deliberately derived from
# the source commit rather than the current time: republishing the same commit
# must produce an identical tree, otherwise the "nothing changed" check below can
# never fire.
SOURCE_DATE="$(git show -s --format=%cd --date=format:'%Y-%m-%d %H:%M:%S' HEAD)"

cat > "${STAGE}/VERSION" <<VERSIONFILE
LRMS web application
source   : ${SOURCE_BRANCH} @ ${SOURCE_SHA}
committed: ${SOURCE_DATE}
VERSIONFILE

# The branch's front page. GitHub shows this when the branch is selected, so it
# is the first thing anyone deploying will read.
cat > "${STAGE}/README.md" <<'BRANCHREADME'
# LRMS — web application (deployment branch)

The PHP web panel, branch manager portal and REST API, and nothing else. No
Android project, no test suites, no CI workflows, no git history of the main
branch.

**This branch is generated.** It is rebuilt and force-updated by
`deploy/publish-web-branch.sh` on the development branch. Do not commit to it
directly — your changes would be discarded on the next publish.

---

## Deploy it

Two ways, both fine.

**Download and upload** — in GitHub use *Code ▸ Download ZIP* with this branch
selected, then upload and extract into `public_html` using your host's file
manager.

**Or clone on the server**, which makes updates a single command:

```bash
cd /home/your-domain
git clone -b web-app --single-branch https://github.com/dhdhdh51/zetpro.git tmp
mv tmp/* tmp/.htaccess tmp/.gitignore public_html/
mv tmp/.git public_html/.git
rm -rf tmp
```

## Then configure it

Put your credentials in `config/config.local.php`. This file is **not tracked**,
so updating with `git pull` will never overwrite or conflict with it:

```php
<?php

return [
    'app' => [
        'url'   => 'https://your-domain',
        'key'   => 'a-long-random-string',
        'debug' => false,
    ],
    'database' => [
        'host'     => 'localhost',
        'database' => 'prefixed_dbname',
        'username' => 'prefixed_dbuser',
        'password' => 'your-password',
    ],
];
```

Anything you leave out falls back to `config/config.php`.

## Point the web server at `public/`

Only `public/` may be web-visible. On CyberPanel: *Websites ▸ Manage ▸
Configurations ▸ vHost Conf*:

```
docRoot                   $VH_ROOT/public_html/public
```

Then check that `https://your-domain/config/config.php` returns 404 or 403. If it
downloads a file, the document root is still wrong and your database password is
public. Fix that before entering any real data.

## Install the database and verify

```bash
cd /home/your-domain/public_html
php database/migrate.php --fresh --seed   # first install only — this DROPS tables
php deploy/preflight.php                  # checks the server can run it
```

Full instructions, including the OpenLiteSpeed `.htaccess` setting, PHP
extensions, permissions, SSL and the cron entry, are in
[`HOSTING-CYBERPANEL.md`](HOSTING-CYBERPANEL.md).

## Update later

```bash
cd /home/your-domain/public_html
mysqldump -u USER -p DBNAME > ~/backup-$(date +%F).sql   # always
git pull
php database/upgrade.php        # adds only what is missing; safe to re-run
php database/migrate.php --seed # installs any new default forms
php deploy/preflight.php
```

`config/config.local.php` and everything under `storage/` are untracked, so your
settings, uploaded photographs and logs all survive the pull.

## What is in here

```
app/          application code: router, auth, services, controllers
bin/cron.php  scheduled tasks — deadline reminders, promise sweep, housekeeping
config/       config.php (defaults) — your overrides go in config.local.php
database/     schema.sql, migrate.php, upgrade.php, seed.php
deploy/       preflight check and OpenLiteSpeed rewrite rules
public/       the ONLY directory that may be web-visible
resources/    view templates
routes/       web, admin, manager and API route definitions
storage/      uploads, generated exports, logs (must be writable)
```

See `VERSION` for the commit this was built from.
BRANCHREADME

# ---------------------------------------------------------------------------
# 3. Write the branch using git plumbing.
#
# This never checks anything out, so the working tree and the branch you are on
# are left exactly as they were.
# ---------------------------------------------------------------------------

# git refuses to reuse an existing empty file as an index ("index file smaller
# than expected"), so reserve the name and remove the file before handing it over.
TEMP_INDEX="$(mktemp -t lrms-web-index.XXXXXX)"
trap 'rm -f "${TEMP_INDEX}"' EXIT
rm -f "${TEMP_INDEX}"

GIT_INDEX_FILE="${TEMP_INDEX}" git --git-dir="${ROOT}/.git" --work-tree="${STAGE}" \
    add --all --force -- .

TREE="$(GIT_INDEX_FILE="${TEMP_INDEX}" git --git-dir="${ROOT}/.git" write-tree)"

# The first publish is an orphan commit, so the deployment branch carries none of
# the development history and stays small. Every later publish is parented on the
# existing tip — without that, each publish would be an unrelated root and
# `git pull` on the server would fail with "divergent branches", which defeats
# the point of deploying by git.
PARENT=""

for ref in "refs/heads/${BRANCH}" "refs/remotes/origin/${BRANCH}"; do
    if git rev-parse --verify --quiet "${ref}" > /dev/null; then
        PARENT="$(git rev-parse "${ref}")"
        break
    fi
done

if [ -n "${PARENT}" ]; then
    # Nothing changed since the last publish? Then there is nothing to deploy.
    if [ "$(git rev-parse "${PARENT}^{tree}")" = "${TREE}" ]; then
        echo
        echo "The application is identical to the current ${BRANCH} tip — nothing to publish."
        rm -rf "${ROOT}/dist/lrms-${VERSION}" "${ROOT}/dist/lrms-${VERSION}.zip" \
               "${ROOT}/dist/lrms-${VERSION}.zip.sha256" 2> /dev/null || true
        exit 0
    fi

    echo "  parent: ${PARENT:0:7} (this will fast-forward on the server)"
fi

COMMIT="$(git commit-tree "${TREE}" ${PARENT:+-p "${PARENT}"} <<MESSAGE
deploy: web application from ${SOURCE_BRANCH} @ ${SOURCE_SHA}

Generated by deploy/publish-web-branch.sh. Contains the PHP web panel, branch
manager portal and REST API only — no Android project, test suites or CI
workflows.

Source committed ${SOURCE_DATE}; published $(date -u '+%Y-%m-%d %H:%M:%S UTC').
MESSAGE
)"

git update-ref "refs/heads/${BRANCH}" "${COMMIT}"

FILE_COUNT="$(git ls-tree -r --name-only "${COMMIT}" | wc -l)"

echo
echo "Branch ${BRANCH} now at ${COMMIT:0:7} with ${FILE_COUNT} files."

# ---------------------------------------------------------------------------
# 4. Prove nothing unwanted was committed.
# ---------------------------------------------------------------------------

LEAKED=0

# Read the file list once. Deliberately not `git ls-tree | grep -q` per file:
# grep exits on its first match, git then dies of SIGPIPE, and `set -o pipefail`
# reports the pipeline as failed even though the file was found — which shows up
# as a random "missing" file depending on pipe buffering.
COMMITTED="$(git ls-tree -r --name-only "${COMMIT}")"

contains_path() {
    case $'\n'"${COMMITTED}"$'\n' in
        *$'\n'"$1"$'\n'*) return 0 ;;
        *) return 1 ;;
    esac
}

while IFS= read -r path; do
    case "$path" in
        android/*|.github/*|tests/*|docs/*|config/config.local.php|*.jks|*.keystore|*.log)
            echo "  !! ${path} must not be on this branch" >&2
            LEAKED=1
            ;;
    esac
done <<< "${COMMITTED}"

for required in public/index.php app/bootstrap.php config/config.php \
                database/schema.sql database/migrate.php database/upgrade.php \
                deploy/preflight.php README.md VERSION; do
    if ! contains_path "${required}"; then
        echo "  !! ${required} is missing from the branch" >&2
        LEAKED=1
    fi
done

if [ "${LEAKED}" -ne 0 ]; then
    git update-ref -d "refs/heads/${BRANCH}" || true
    echo "Branch discarded." >&2
    exit 1
fi

echo "  contents verified."

# ---------------------------------------------------------------------------
# 5. Push, on request.
# ---------------------------------------------------------------------------

if [ "${PUSH}" -eq 1 ]; then
    echo
    echo "Pushing to origin/${BRANCH}..."
    # No --force: each publish is parented on the previous tip, so this is a
    # fast-forward. A rejection here means someone committed to the branch by
    # hand, which is worth stopping for rather than overwriting.
    git push origin "refs/heads/${BRANCH}:refs/heads/${BRANCH}"
    echo "Pushed."
else
    echo
    echo "Not pushed. Review it first:"
    echo "  git ls-tree -r --name-only ${BRANCH} | head -30"
    echo "Then publish with:"
    echo "  bash deploy/publish-web-branch.sh --push"
fi

rm -rf "${ROOT}/dist/lrms-${VERSION}" "${ROOT}/dist/lrms-${VERSION}.zip" \
       "${ROOT}/dist/lrms-${VERSION}.zip.sha256" 2> /dev/null || true
