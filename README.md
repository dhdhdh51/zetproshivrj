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
git pull --ff-only origin web-app
php database/upgrade.php        # adds only what is missing; safe to re-run
php database/migrate.php --seed # installs any new default forms
php deploy/preflight.php
```

`--ff-only` is deliberate: this checkout should only ever move forward. If it
refuses, someone edited files on the server that are tracked by git — check with
`git status` before going further.

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
