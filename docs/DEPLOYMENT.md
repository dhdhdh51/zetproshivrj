# Deployment

LRMS is a plain PHP application with no Composer dependencies, so it runs on
ordinary shared hosting (cPanel) as well as a VPS.

## Requirements

- PHP **8.2+** with `pdo_mysql`, `mbstring`, `gd`, `zip`, `curl`, `json`
  (`gd` is needed for photo watermarking, `zip` for reading and writing `.xlsx`)
- MySQL **5.7+** or MariaDB **10.4+**
- HTTPS (a free certificate is fine — customer data must not travel in the clear)

## 1. Files

Upload the repository so that **`public/` is the document root**.

- **VPS / dedicated:** point the vhost `DocumentRoot` at `/path/to/lrms/public`.
- **Shared hosting** where the document root must be `public_html`: place the
  application one level above it and either symlink `public_html` to
  `public/`, or upload everything into `public_html` and rely on the committed
  root `.htaccess`, which forwards requests into `public/` and blocks direct
  access to `app`, `config`, `database`, `storage`, `resources`, `routes`,
  `tests`, `docs` and `android`.

Never expose `storage/` — photographs and generated exports live there and are
streamed only through authorised controllers.

## 2. Configuration

```bash
cp config/config.example.php config/config.php
```

Edit `config/config.php`:

| Setting | Notes |
| --- | --- |
| `app.url` | Full public URL, e.g. `https://lrms.yourbank.in` |
| `app.key` | `php -r "echo bin2hex(random_bytes(32));"` |
| `app.debug` | **false** in production |
| `app.force_https` | true (adds HSTS and redirects) |
| `app.timezone` | `Asia/Kolkata` — this is the timezone the report deadline uses |
| `database.*` | Credentials for the LRMS database |

`config/config.local.php` is git-ignored and recursively overrides
`config/config.php`; use it for developer machines, not production.

Anything an Admin/Supervisor can change from **Settings** is stored in
`system_settings` and overrides the file at runtime.

## 3. Permissions

```bash
chmod -R 0775 storage
chown -R www-data:www-data storage      # or the user your PHP-FPM pool runs as
```

`storage/` must be writable: `uploads/photos`, `uploads/signatures`,
`uploads/imports`, `generated`, `logs`.

## 4. Database

```bash
php database/migrate.php --fresh --seed
```

This creates all 40 tables and loads: the three roles, 14 report types, default
settings, the default customer visit form (21 fields), the default BC Supervisor
inspection form (11 fields) and the first Admin/Supervisor account.

Set the first account's credentials instead of using the defaults:

```bash
LRMS_ADMIN_EMAIL=admin@yourbank.in \
LRMS_ADMIN_PASSWORD='a-long-random-password' \
LRMS_ADMIN_MOBILE=9876543210 \
php database/migrate.php --fresh --seed
```

Useful afterwards:

```bash
php database/migrate.php --status     # tables and row counts
```

The schema can also be loaded directly: `mysql -u user -p lrms < database/schema.sql`.

## 5. Cron

One entry runs everything; the script decides what is due:

```cron
*/5 * * * * /usr/bin/php /path/to/lrms/bin/cron.php >> /path/to/lrms/storage/logs/cron.log 2>&1
```

It handles pre-deadline reminders, locking yesterday's unsubmitted reports,
sweeping promises whose date has passed, follow-up reminders, marking absentees
and housekeeping (expired OTPs, old export files).

Without cron the application still works, but reminders are not sent and
promises are not swept automatically.

## 6. First run

1. Sign in and change the administrator password (forced).
2. **Settings** — organisation name, minimum photographs, GPS accuracy limit,
   payment modes.
3. **Report deadline** — working days, deadline time, reminder thresholds.
4. **Branches** — create every branch, using the branch codes that appear in your
   Excel sheets. Add coordinates if you want the GPS drift check.
5. **Branch managers** and **BC supervisors** — the BC code must match the code in
   the sheets so accounts allocate automatically.
6. **Excel import** — upload, map columns, save the mapping as a template,
   preview, import.
7. **Allocation** — balance anything the import could not allocate.
8. **Targets**, then hand the Android app to supervisors.

## 7. Security checklist

- [ ] HTTPS enforced (`app.force_https`), certificate valid
- [ ] `app.debug` is `false`
- [ ] `app.key` changed from the placeholder
- [ ] Administrator password changed; temporary passwords rotated
- [ ] Database user limited to the LRMS database only
- [ ] `storage/` and `config/` unreachable over HTTP (test:
      `curl -I https://your-host/config/config.php` must not return 200)
- [ ] Backups cover both the database and `storage/uploads` (photographs are
      evidence and are not reproducible)
- [ ] OTP enabled only with a working SMS gateway
- [ ] Device binding left on, so one account cannot be shared across handsets
- [ ] Cron running (check `storage/logs/cron.log`)

What the application already enforces: bcrypt password hashing, per-IP and
per-account sign-in throttling with account lockout, CSRF on every browser POST,
a strict Content-Security-Policy, prepared statements for every query, session
expiry with auto logout, role and branch authorisation in one place
(`App\Core\Acl`), API tokens stored only as SHA-256 hashes, device binding, and an
append-only audit trail.

## 8. Backups

```bash
mysqldump --single-transaction --routines lrms | gzip > lrms-$(date +%F).sql.gz
tar czf lrms-uploads-$(date +%F).tar.gz storage/uploads
```

`storage/generated` does not need backing up — exports are re-created on demand.

## 9. Upgrading

1. Back up the database and `storage/uploads`.
2. Deploy the new files (`config/config.php` is not overwritten).
3. `php database/migrate.php --status` to confirm the schema manifest.
4. Check `storage/logs/` after the first few requests.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| "Configuration required" page | `config/config.php` missing — copy the example. |
| Blank page, 500 in the log | Check `storage/logs/app-*.log`; usually a missing extension or an unwritable `storage/`. |
| Photos fail to upload | `gd` not installed, or `storage/uploads` not writable. |
| Excel upload rejected | Legacy `.xls`; re-save as `.xlsx` or `.csv`. |
| Import reports "unknown branch" | Create the branch with the code used in the sheet, then re-import. |
| App cannot sign in | Wrong `API_BASE_URL` in the build, or the device is bound to another handset — reset the binding on the supervisor's page. |
| Deadline countdown looks wrong | The **server** timezone (`app.timezone`) is authoritative; the device clock is ignored. |
