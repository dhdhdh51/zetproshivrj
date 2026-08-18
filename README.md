# LRMS — Loan Recovery Management System

Bank loan recovery operations and monitoring of Business Correspondent (BC)
Supervisor field activity.

LRMS is one system with four parts:

| Part | For | Technology |
| --- | --- | --- |
| **Web panel** | Admin/Supervisor | PHP 8.2+, no framework, MySQL |
| **Branch portal** | Branch Manager | same application, branch-scoped |
| **REST API** | the Android app | `/api/v1`, bearer tokens |
| **Android app** | BC Supervisor | Kotlin, Compose, offline-first (`android/`) |

---

## The two field workflows

These are deliberately separate throughout the system — separate tables, separate
screens, separate reports.

**TYPE A — Customer visit.** A **BC Supervisor** visits a borrower to recover
money, using the **Android app**: mandatory GPS, watermarked photographs, the
configurable visit form, recovery, promise to pay, follow-up and signatures.

**TYPE B — BC Supervisor inspection.** An **Admin/Supervisor** goes to the field
to verify that a BC Supervisor actually did the allocated work, using the **web
panel**: their own GPS, inspection photographs, the configurable inspection form,
a verification result and remarks.

> An Admin/Supervisor never performs customer recovery visits. Their field
> activity is inspection and monitoring only.

## Roles

There are exactly three, and no separate "super admin":

- **Admin / Supervisor** — one role, full control: branches, staff, Excel import,
  allocation, targets, deadline, forms, inspections, reports, audit log.
- **Branch Manager** — read and report access to **one** branch. Enforced in
  `App\Core\Acl` and covered by tests that assert a cross-branch record returns
  403 and that reports never leak another branch's rows.
- **BC Supervisor** — the Android app only. Signing in to the web portal tells
  them so rather than showing a half-working UI.

---

## Quick start

Requirements: PHP 8.2+ with `pdo_mysql`, `gd`, `zip`, `mbstring`, `curl`;
MySQL 5.7+ or MariaDB 10.4+. No Composer packages are required.

```bash
cp config/config.example.php config/config.php
# edit config/config.php: database credentials, app.url, timezone

php database/migrate.php --fresh --seed      # schema + baseline data
php -S localhost:8000 -t public public/index.php
```

Sign in at `http://localhost:8000/login` with `admin@lrms.local` /
`ChangeMe@123` (the password must be changed at first sign-in). Override the
seeded account with `LRMS_ADMIN_EMAIL` and `LRMS_ADMIN_PASSWORD`.

Add `--demo` instead of `--seed` to also create three branches, three branch
managers and six BC Supervisors for testing.

### Tests

```bash
php tests/test-import.php    # Excel import, allocation, exports   (91 checks)
php tests/http-smoke.php     # every web screen, all 13 reports    (79 checks)
php tests/api-smoke.php      # the Android API end to end         (101 checks)
```

The suites run against a real database and a real HTTP server — they start
`php -S` themselves. `tests/http-smoke.php` also fails if any PHP notice or
warning leaks into a page.

### Android

```bash
cd android
./gradlew testDebugUnitTest assembleDebug          # 21 unit tests + APK
./gradlew assembleRelease bundleRelease lintRelease
```

See [`docs/ANDROID.md`](docs/ANDROID.md) for signing, environments and the
release process.

---

## What is implemented

**Loan book**
- Excel/CSV upload with a dependency-free `.xlsx` reader (ZipArchive + XMLReader).
- Automatic column matching with confidence scoring — `A/C No` → Account Number,
  `OD Amount` → Overdue, `NPA Dt` → NPA Date — and a mapping screen where every
  system field has a dropdown of the detected headers.
- Saveable mapping templates, matched by column caption so they survive columns
  moving between uploads.
- Preview before writing anything: mapped values, missing required fields,
  unparseable amounts and dates, in-file duplicates, unknown branches, invalid BC
  codes.
- Re-importing updates existing accounts instead of duplicating them.

**Allocation**
- BC code in the sheet → that supervisor; otherwise balanced by current workload.
- Manual and bulk reassignment with a mandatory reason.
- One live owner per account is enforced by the database, not by application code
  (`UNIQUE(loan_account_id, is_active)` with `is_active` NULL for history).

**Field work**
- Configurable visit and inspection forms (15 field types, conditional fields,
  ordering, active flag) — the same engine drives the Android form and the web
  inspection form.
- Server-side GPS validation: accuracy limit, mock-location rejection, 0,0
  rejection, optional drift from the branch centroid, and the distance between an
  inspector's point and the supervisor's recorded point.
- Photographs are re-encoded (stripping EXIF), watermarked with name, time and
  coordinates, hashed for duplicate detection and stored outside the web root.
- Recovery, PTP (with automatic kept/broken sweeping), follow-ups, attendance
  with selfie, KRM OTS and CKCC OD-2 work streams.

**Reporting**
- 13 reports, each with its own filters and PDF / Excel / CSV export, plus
  per-record Customer Visit and BC Supervisor Inspection report PDFs.
- Exporters are dependency-free: an `.xlsx` writer and a PDF writer (tables,
  key/value blocks, embedded photographs) are part of the application.

**Operations**
- Report deadline with server-authoritative time, countdown, reminders, locking
  and a late-submission approval queue that records reason, approver and time.
- Targets (daily/monthly, per supervisor or branch) with achievement computed
  from actual visits and recoveries.
- Live monitoring: online/offline, last known position and age, today's route.
- Audit log of every sign-in, import, allocation, visit, inspection, money entry,
  configuration change and export, with before/after values.

**Android**
- Offline-first: Room cache + an outbox. Work is written locally first and drained
  by WorkManager when a connection appears.
- Every queued record carries a client UUID; the server treats a replay as a
  duplicate, so retries can never double-count a recovery.
- The deadline countdown uses the server deadline and the monotonic clock, so
  changing the device time buys no extra minutes.

**Pipeline**
- `.github/workflows/android-build.yml` — tests, lint, signed release APK + AAB,
  versioned artifacts, GitHub Release on a `v*` tag.
- `.github/workflows/backend-ci.yml` — PHP 8.2 and 8.4, real MySQL, schema
  install and all three suites.

---

## Layout

```
app/
  Core/            router, PDO wrapper, auth, ACL, API tokens, settings, views
  Controllers/     Admin/*, Manager/*, Api/*, auth, authorised file streaming
  Services/        the domain: Allocation, Visits, Inspections, Deadline, Gps,
                   Photos, Forms, Reports, Audit, Excel/*, Export/*
  Middleware/
config/            config.example.php → config.php (git-ignored local overrides)
database/          schema.sql (40 tables), migrate.php, seed.php
public/            the only web-exposed directory
resources/views/   layouts, partials, admin/*, manager/*, auth, errors
routes/            web.php, admin.php, manager.php, api.php
storage/           uploads (photos, signatures, imports), generated exports, logs
tests/             three executable suites
bin/cron.php       deadline reminders, promise sweep, absentees, housekeeping
android/           the Kotlin application
docs/              deployment, API reference, Android build, operations
```

## Documentation

- [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) — install, cron, permissions,
  security checklist.
- [`docs/API.md`](docs/API.md) — every endpoint the app uses, with payloads.
- [`docs/ANDROID.md`](docs/ANDROID.md) — build, signing secrets, environments,
  releases.
- [`docs/OPERATIONS.md`](docs/OPERATIONS.md) — the day-to-day runbook.

## Known limitations

Stated plainly so nobody is surprised in production:

- **Legacy `.xls` is not supported.** Excel's binary format needs a parser LRMS
  does not ship; the upload screen asks for `.xlsx` or `.csv`.
- **PDF exports render Latin text.** The built-in PDF writer uses the standard
  Helvetica fonts, so Devanagari borrower names are transliterated. Use the Excel
  or CSV export when a report must preserve non-Latin script exactly.
- **Monitoring is last-known, not continuous.** Positions are recorded when the
  app reports in. While a device is offline or location permission is off, the UI
  shows the last point and its age rather than pretending to track live.
- **OTP delivery needs an SMS gateway.** Without one configured, codes are written
  to the application log so staging still works — never enable OTP in production
  without a gateway.
- **Android instrumentation tests are not included.** The unit tests cover the
  offline form rules and formatting; the API contract is covered by
  `tests/api-smoke.php` against the real server.
