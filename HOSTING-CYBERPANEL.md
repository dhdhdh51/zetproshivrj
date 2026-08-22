# Hosting LRMS on CyberPanel

Start to finish, on a CyberPanel server (OpenLiteSpeed). About 30 minutes.

If you are on cPanel, plain Apache or nginx instead, use
[`DEPLOYMENT.md`](DEPLOYMENT.md) — it covers the same ground without the
CyberPanel-specific screens.

**The one thing that catches everybody:** the document root has to point at
`public_html/public`, not `public_html`. Section 4. Get that wrong and either
nothing loads, or — worse — your database password becomes downloadable.

---

## 0. Get the files

**Never upload the repository itself.** It carries the Android project, four test
suites, CI workflows and the git history — none of which belong on a web server.

Two supported ways to get just the web application:

**A — the `web-app` branch (easiest).** A branch containing the PHP application
only, kept up to date by the development team. In GitHub switch to the `web-app`
branch and use *Code ▸ Download ZIP*. Or clone it on the server, which makes
future updates one command:

```bash
git clone -b web-app --single-branch https://github.com/dhdhdh51/zetpro.git
```

**B — build the archive yourself**, on your own machine in a clone of the repo:

```bash
bash deploy/build-package.sh 1.0.0     # -> dist/lrms-1.0.0.zip
```

Either way you get the same file set. The packager lints every file it ships and
refuses to build on a syntax error or if anything credential-shaped slipped in.

> Maintainers: refresh the branch with
> `bash deploy/publish-web-branch.sh --push`.

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
2. Upload the archive.
3. Extract it, then **flatten it** — move everything out of the folder the
   archive created so it sits directly in `public_html`.

> **This is the most common cause of a site that 404s on every page.** Both
> archives contain a wrapper folder. GitHub's *Download ZIP* names it after the
> commit, e.g. `dhdhdh51-zetpro-c94ffc5/`; `build-package.sh` names it
> `lrms-1.0.0/`. If you skip the flatten step you end up with
> `public_html/dhdhdh51-zetpro-c94ffc5/app/...`, there is no
> `public_html/public/index.php` for the web server to reach, and every URL
> returns 404.
>
> **Also: File Manager hides dotfiles by default.** When you move the contents,
> turn on "show hidden files" so `.htaccess` comes across too — without it the
> clean URLs stop working and you get 404s on everything except the home page.
> In the terminal, `mv folder/* folder/.[!.]* public_html/` catches both.

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

The decisive check, before you touch anything else:

```bash
ls -la /home/your-domain/public_html/public/index.php
ls -la /home/your-domain/public_html/.htaccess
```

Both must exist. If `public/index.php` is missing you have the nesting problem
above. If `.htaccess` is missing, the dotfiles were left behind in the move.

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

### If the home page itself returns 404

That rules out the rewrite rules — a missing `.htaccess` breaks the other URLs but
leaves the home page working. It is one of these two, and you can tell which from
a browser without needing a terminal. Open, in order:

| Open this URL | It loads | What it means |
| --- | --- | --- |
| `https://your-domain/deploy/preflight.php` | runs | The document root is one level too high — it is on `public_html`, not `public_html/public`. **Also check `/config/config.php` immediately: it is probably downloadable right now.** |
| `https://your-domain/dhdhdh51-zetpro-<sha>/deploy/preflight.php` | runs | The archive was never flattened. Move the contents of that folder up into `public_html`. |
| neither loads, and the site is unreachable rather than 404 | — | The document root points at a directory that does not exist — usually `public_html/public` set while the files are still nested. |

Verified behaviour of each layout:

| Request | docRoot too high | Files still nested |
| --- | --- | --- |
| `/` | 404 | 404 |
| `/deploy/preflight.php` | **200** | 404 |
| `/<sha-folder>/deploy/preflight.php` | 404 | **200** |
| `/config/config.local.php` | **200 — your database password** | 404 |

Do not "fix" this by adding an `index.php` to `public_html` that includes
`public/index.php`. It makes the home page work while leaving `config/` and
`storage/` downloadable, because OpenLiteSpeed is not applying the `.htaccess`
rules that would otherwise block them. Set the document root.

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

## 7. Install — the easy way, in a browser

**No terminal needed.** Open:

```
https://your-domain/install.php
```

It checks the server can run LRMS, then asks for your database details and the
admin account you want. On submit it writes `config/config.local.php`, creates all
40 tables, loads the roles, report types, settings and the four field forms, and
creates your account with the password *you* chose — no default password is left
behind.

What it will and will not touch:

| The database you point it at | What happens |
| --- | --- |
| Empty | Installs. |
| LRMS tables but no user accounts — an install that failed part way | Offers to clear them and start over, once you tick a box. Nothing recorded is lost, because nothing was recorded. |
| A working LRMS site with accounts | Refuses. Drop the database in your hosting panel first if you really mean to reinstall. |
| Tables that are not LRMS | Refuses, and touches nothing. |

If an install fails it removes the tables it created, so you can correct the field
it complained about and submit again — no clean-up in phpMyAdmin.

When it finishes it deletes itself; if it cannot, it says so and you must delete
`public/install.php` yourself.

If connecting fails with a socket error, fill in the **socket path** field —
some hosts do not accept TCP connections on `localhost`.

Skip to section 8 once it succeeds. The rest of this section is the command-line
route.

## 7b. Install the schema from a terminal

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
5. **Staff ▸ BCAs** — the BCBF code must match the code in your Excel
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
