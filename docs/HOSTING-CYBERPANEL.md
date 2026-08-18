# Hosting LRMS on CyberPanel

Start to finish, on a CyberPanel server (OpenLiteSpeed). About 30 minutes.

If you are on cPanel, plain Apache or nginx instead, use
[`DEPLOYMENT.md`](DEPLOYMENT.md) — it covers the same ground without the
CyberPanel-specific screens.

**The one thing that catches everybody:** the document root has to point at
`public_html/public`, not `public_html`. Section 4. Get that wrong and either
nothing loads, or — worse — your database password becomes downloadable.

---

## 0. Build the upload package

Do this on your own machine, in a clone of the repository:

```bash
bash deploy/build-package.sh 1.0.0
```

That writes `dist/lrms-1.0.0.zip` — the application only. The Android project,
test suites, CI workflows, git history and any local test uploads are excluded,
and the script refuses to build if a shipped file has a syntax error or if
anything credential-shaped slipped in.

Don't upload the repository itself. It contains files that have no business on a
web server.

---

## 1. Create the website

**CyberPanel ▸ Websites ▸ Create Website**

| Field | Value |
| --- | --- |
| Domain | your domain, e.g. `lrms.example.com` |
| Email | your address |
| PHP | **8.2** or newer |
| Additional features | tick **SSL**, **DKIM**, **Open Basedir Protection** |

LRMS needs PHP 8.2+. If the dropdown has nothing recent, install it first:
**Server ▸ PHP ▸ Install Extensions**.

---

## 2. Enable the PHP extensions

**CyberPanel ▸ PHP ▸ Edit PHP Extensions**, pick the version you chose, and make
sure all of these are on:

| Extension | Needed for |
| --- | --- |
| `pdo_mysql` | the database |
| `mbstring` | text handling |
| `gd` | photo watermarking and resizing |
| `zip` | reading `.xlsx` uploads |
| `fileinfo` | checking upload types |
| `openssl` | password hashing |
| `json` | API responses |
| `curl` | optional: SMS gateway calls |

`gd` and `zip` are the two that are commonly missing. Without `gd` no
photograph can be watermarked; without `zip` no Excel file can be read.

While you are in the PHP settings, raise these — the defaults are too small for a
branch NPA list:

```ini
upload_max_filesize = 32M
post_max_size = 32M
memory_limit = 256M
max_execution_time = 300
```

---

## 3. Upload the files

**CyberPanel ▸ Websites ▸ List Websites ▸ Manage ▸ File Manager**, open
`public_html`.

1. Delete the placeholder `index.html` CyberPanel created.
2. Upload `lrms-1.0.0.zip`.
3. Extract it, then move everything **out of** the `lrms-1.0.0/` folder so it
   sits directly in `public_html`.

`public_html` should now look like this:

```
public_html/
├── app/
├── bin/
├── config/
├── database/
├── deploy/
├── public/          ← the document root, set in the next step
├── resources/
├── routes/
├── storage/
├── .htaccess
└── HOSTING-CYBERPANEL.md
```

If `app/`, `config/` and `public/` are not siblings at that level, the extract
went one directory too deep. Fix it now; nothing later will work otherwise.

---

## 4. Point the document root at `public/` ← the important one

Only `public/` may be web-visible. Everything else — your config file with the
database password, the borrower photographs, the logs — must sit above it.

**Websites ▸ List Websites ▸ Manage ▸ Configurations ▸ vHost Conf**

Find the `docRoot` line and add `/public`:

```
docRoot                   $VH_ROOT/public_html/public
```

While you are in that file, confirm the rewrite block reads
`autoLoadHtaccess 1`. OpenLiteSpeed ignores `.htaccess` unless told to, and LRMS
ships `.htaccess` files that provide its clean URLs:

```
rewrite  {
  enable                  1
  autoLoadHtaccess        1
}
```

**Save**, then **Server ▸ Restart OpenLiteSpeed** (a vHost change needs a
restart, unlike the Rewrite Rules editor).

> If your CyberPanel build will not honour `.htaccess`, use the native rules in
> [`deploy/openlitespeed-rewrite.conf`](../deploy/openlitespeed-rewrite.conf)
> instead — paste them into **Manage ▸ Rewrite Rules**. The symptom is that the
> sign-in page loads but every other URL 404s.

### Check it worked

Open `https://your-domain/config/config.php`. You want **404 or 403**. If the
browser downloads a file or shows PHP code, the document root is still wrong —
stop and fix it before entering any real data.

---

## 5. Create the database

**CyberPanel ▸ Databases ▸ Create Database**

| Field | Note |
| --- | --- |
| Select website | your domain |
| Database name | e.g. `lrms` — CyberPanel prefixes it, giving something like `lrmsexam_lrms` |
| Username | CyberPanel prefixes this too |
| Password | generate a long one |

Write down the **full prefixed names**. Using the unprefixed name is the most
common reason for "database connection failed".

Set the charset to `utf8mb4` if you are asked. Borrower names need it.

---

## 6. Configure the application

In File Manager, open `config/config.php` and edit it in place:

```php
'app' => [
    'name'  => 'LRMS',
    'url'   => 'https://lrms.example.com',   // no trailing slash
    'key'   => 'a-long-random-string-you-generate',
    'debug' => false,                         // must be false in production
    'timezone' => 'Asia/Kolkata',
],

'database' => [
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'lrmsexam_lrms',            // the prefixed name
    'username' => 'lrmsexam_lrms',            // the prefixed user
    'password' => 'the password you generated',
],
```

Three things people get wrong here:

- **`debug` must be `false`.** With it on, any error prints a stack trace —
  including file paths and query fragments — to whoever triggered it.
- **`key` must be changed** from the placeholder. It signs sessions.
- **`url` must match the real address**, including `https`, or PDF links and
  password-reset links will point somewhere else.

---

## 7. Install the schema

**Websites ▸ List Websites ▸ Manage ▸ Terminal** (or SSH):

```bash
cd /home/lrms.example.com/public_html
php database/migrate.php --fresh --seed
```

That creates 40 tables and the baseline data: roles, the 13 report types, the
default visit form, the two Field Visit Verification Report forms, and the first
admin account.

Set your own admin credentials rather than using the seeded ones:

```bash
LRMS_ADMIN_EMAIL=you@yourbank.com LRMS_ADMIN_PASSWORD='a-strong-password' \
  php database/migrate.php --fresh --seed
```

**No terminal on your plan?** Use phpMyAdmin instead: **Databases ▸ phpMyAdmin**,
select the database, **Import**, and upload `database/schema.sql`. Then you still
need the baseline data — run `php database/seed.php` from a terminal, or ask your
host to run it once. The app cannot function without the seeded roles.

> `--fresh` **drops every table**. It is right for a first install and wrong ever
> after. To update an existing installation see section 11.

---

## 8. Fix ownership and permissions

Still in the terminal, as root or via SSH:

```bash
cd /home/lrms.example.com
chown -R lrms.example.com:lrms.example.com public_html
find public_html -type d -exec chmod 755 {} \;
find public_html -type f -exec chmod 644 {} \;
chmod -R 775 public_html/storage
chmod 640 public_html/config/config.php
```

The site user must own the files, `storage/` must be group-writable so uploads
and logs can be written, and the config file should not be world-readable.

---

## 9. Run the preflight check

This is the fastest way to find a problem before your staff do:

```bash
cd /home/lrms.example.com/public_html
php deploy/preflight.php
```

It checks the PHP version and every extension, the upload limits, that
`storage/` is genuinely writable (it writes a real file, not just
`is_writable`), that `debug` is off and the key is set, that the database
connects and the schema is complete, and whether the seeded admin password has
been changed.

No shell? Copy that one file into the web root, open it in a browser, then
**delete it**:

```bash
cp deploy/preflight.php public/preflight.php
# open https://your-domain/preflight.php
rm public/preflight.php
```

Do not leave it in place. It exposes which extensions you run.

---

## 10. SSL and the cron job

**SSL** — **Websites ▸ Manage ▸ SSL ▸ Issue SSL** (Let's Encrypt), then turn on
**Force HTTP → HTTPS**. The Android app refuses plain HTTP in staging and
release builds, so a certificate is not optional if you want the app to work.

**Cron** — **Websites ▸ Manage ▸ Cron Jobs ▸ Add Cron**, every five minutes:

```
*/5 * * * * /usr/local/lsws/lsphp82/bin/php /home/lrms.example.com/public_html/bin/cron.php >> /home/lrms.example.com/public_html/storage/logs/cron.log 2>&1
```

Adjust `lsphp82` to your PHP version. Find the real path with `which php` or
`ls /usr/local/lsws/`.

Without this, none of the following happens: deadline reminders, locking
yesterday's unsubmitted reports, marking broken promises, follow-up reminders,
absentee marking, and cleanup of old exports and expired OTPs.

Confirm it is running after ten minutes:

```bash
tail /home/lrms.example.com/public_html/storage/logs/cron.log
```

---

## 11. Sign in, and point the app at the server

1. Open `https://your-domain/login`.
2. Sign in and change the password when prompted.
3. **Settings** — set the report deadline, working days and GPS limits.
4. **Branches ▸ Add** — include the Regional Office and Zone; both print on the
   verification report.
5. **Staff ▸ BC Supervisors** — the BCBF code must match the code in your Excel
   sheets, or automatic allocation cannot match on it.

Then build the Android app against this server. In GitHub, add the repository
variable `LRMS_API_URL`:

```
https://your-domain/api/v1/
```

and the four signing secrets, then run the **Android build** workflow. Details in
[`ANDROID.md`](ANDROID.md). A release build **fails** if the API URL is not
HTTPS, which is deliberate — it stops credentials going out in the clear.

Check the API is reachable from a phone browser: `https://your-domain/api/v1/ping`
should return JSON.

---

## Updating an existing installation

`migrate.php --fresh` destroys data. To update in place:

```bash
cd /home/lrms.example.com
# 1. Back up first, always.
mysqldump -u USER -p DBNAME > ~/lrms-backup-$(date +%F).sql
tar -czf ~/lrms-storage-$(date +%F).tar.gz public_html/storage

# 2. Upload and extract the new package over the old files, but keep
#    config/config.php and storage/ — do not overwrite those two.

# 3. Apply schema changes. Adds only what is missing; safe to re-run.
cd public_html
php database/upgrade.php --dry-run    # see what it would change
php database/upgrade.php              # apply
php database/migrate.php --seed       # install any new default forms

# 4. Confirm.
php deploy/preflight.php
```

---

## When something is wrong

| Symptom | Cause |
| --- | --- |
| Blank white page | PHP error with `debug` off. Read `storage/logs/` and the site's error log under **Manage ▸ Logs** |
| Sign-in page loads, every other URL 404s | `.htaccess` is not being read. Set `autoLoadHtaccess 1`, restart OpenLiteSpeed, or use `deploy/openlitespeed-rewrite.conf` |
| `config/config.php` downloads in the browser | Document root still points at `public_html`. Section 4 — urgent |
| "Database connection failed" | Using the unprefixed database or user name, or the user is not attached to that database (**Databases ▸ List Databases**) |
| Photos fail to upload | `gd` missing, or `storage/uploads` not writable by the site user |
| Excel upload rejected | `zip` missing, or the file is a legacy `.xls` — re-save it as `.xlsx` |
| Large import dies partway | `max_execution_time` or `memory_limit` too low (section 2) |
| App reports "server unreachable" | No valid certificate, or `LRMS_API_URL` was not compiled into the build |
| API returns 401 for everything | The `Authorization` header is being stripped. The shipped rewrite rules pass it through — check they are active |
| Deadline reminders never arrive | Cron not installed, or pointing at the wrong `php` binary (section 10) |

Open **Manage ▸ Logs** for the OpenLiteSpeed error log, and read
`storage/logs/` for the application's own log. Between the two, almost every
blank page explains itself.
