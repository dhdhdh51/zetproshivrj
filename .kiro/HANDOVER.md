# Where things stand

Last updated: 20 August 2026. Delete or rewrite this once it stops being true — a stale
handover is worse than none.

Working branch: `feat/lrms-loan-recovery-system`, open as PR #2.

## Shipped and verified

Everything below is on the branch, green in CI, and live-ready.

- **SSS enrolments** — the four Social Security Scheme counts (APY, PMJJBY, PMSBY, PMJDY)
  per BCA per day, in the app and the panel. One row per supervisor per day,
  enforced by a unique key; every write is an upsert, because the offline outbox can deliver
  the same day twice and appending would double every total built on it.
  - Backdating window is a setting (`sss_backdate_days`, default 30).
- **SSS target monitoring** — the figures above are now measured against a target.
  - The Admin sets a figure **per working day, per scheme, per supervisor, per month**
    (`sss_targets`). Day, month-to-date and month totals are all derived from it and the
    `report_working_days` setting, so nothing stored can fall out of step and a non-working
    day is not a shortfall.
  - **% and GAP have no columns.** They are computed in `App\Services\Sss` on the way to the
    panel, the report and the handset, so the three cannot disagree. A test asserts the
    screen and the report produce the same target for the same window.
  - **A submitted day is closed to the app.** `Sss::record()` refuses a change with 409 and a
    message naming the way out; only `POST /admin/sss/{id}/reopen` opens it, which buys
    exactly one more submission, notifies the supervisor and is audit-logged
    (`sss_reopened`).
  - **The lock stops at the outbox.** Identical figures arriving twice are a redelivery and
    are accepted unchanged (`Sss::sameFigures()`). Only an attempt to say something
    *different* about a closed day is refused. Do not "simplify" this into refusing every
    repeat: it strands queue entries that were never wrong.
  - Panel: **Field work ▸ SSS enrolments** (Achievement / Target / % / Gap, Today ▸ Month to
    date ▸ Full month, a ranked register with the per-scheme breakdown, Re-open per day) and
    **SSS targets** (one daily figure applied to many supervisors, each notified). Report:
    **SSS Target vs Achievement**, ordered by percentage, PDF and CSV.
  - App: target, done, % and gap, recalculating as the figures are typed and cached in
    `SessionStore` so the screen still reads with no signal. A closed day composes no inputs.
    A refused day says why — it used to read as "Sent" for ever.
- **Browser database update** — **Settings ▸ Update the database**. It exists because every
  documented upgrade path needed a terminal and this hosting has none.
- **Field visit format** — the client's 27-item "New Field Visit Format", added as a new
  version so historic inspections still print the questions they were answered against.
- **The inspection is monthly, and of the outlet** — the questions were replaced earlier but
  the workflow around them was not, so it still ran as "verify one customer visit".
  - Starting one asks for the **BCA and the date, nothing else**. The visit and
    account pickers are gone, and `visit_id` / `loan_account_id` are left NULL on new rows.
    The columns stay, and historic rows still show their links.
  - **Item 24 (Excellent / Good / Satisfactory / Poor) is the result.** The separate
    "Work Verified / Customer Not Found" question is gone — it asked the same thing twice, in
    the vocabulary of a form this one replaced. `Inspections::submit()` derives the grade from
    the `observation` value; a `result` in the payload is ignored. Only **Poor** demands
    remarks, and only Poor schedules a follow-up by itself.
  - The four grades are **appended** to the `result` ENUM, never inserted mid-list: MySQL
    stores an ENUM as an integer index, so the ten retired outcomes stay where they are and
    keep reading as they were recorded. Verified on a simulated older database — the values
    survived the widening unchanged.
  - **Once a month is the expectation, not a rule.** The start screen warns when that month
    already has one and offers to open it, but a second visit after a Poor grade is still
    possible.
  - Coverage on the dashboards now means **how many BCAs have had their monthly
    inspection**, not what share of customer visits was verified.
- **A handset can be handed to a second BCA.** It could not before, and the failure was
  permanent. `devices.device_uuid` is unique, so the row for a phone carried whoever first
  signed in on it, and a release only changed its `status` — the next BCA still hit "already
  registered to another user", with nothing on any screen to say what would fix it. The phone
  was scrap. `ApiAuth::registerDevice()` now reassigns a **released** row to the new owner and
  sets it active again; an **active** row still refuses, and names the holder and their BC
  code so the branch knows who to ask.
  - The one rule that had to survive the fix: **one live handset per account.** Every path
    that turns a row active — a fresh bind, a re-activation, a takeover — goes through the
    same check, so picking up a released phone while holding your own is still refused.
    `tests/api-smoke.php` walks the whole handover and both ways of trying to get two.
- **The inspection form arrives part-filled.** The client's complaint was retyping the BCA's
  name, age, address and IIBF number twelve times a year. Three sources, in order: this
  inspection's own answers (a resumed draft wins), **then** last month's for standing facts
  only, **then** the BCA's staff record.
  - Last month beats the staff record on purpose: a detail corrected on the form stays
    corrected instead of being overwritten by the same stale master data every month.
  - `Inspections::CARRIED_FORWARD` is the whole list and everything absent from it is
    deliberately absent — transactions, remuneration, feedback, boards, registers, equipment,
    photographs and the item 24 grade all start blank. Items **9 and 10** read like standing
    facts and are not: the inspector is being asked to be *shown* the appointment letter and
    the identity card. Item 25 comes from whoever is signed in, not from last month's name.
- **The header is the letterhead.** The logo moved out of the navy band onto its own white
  strip above it, at 32pt, and the organisation name is gone from every PDF — heading block,
  band subtitle and page footer. The client asked for both: the mark was a small boxed stamp
  crowding a title, and the name duplicated what the mark already says in two scripts.
  - `documentHeader()` lost its `$organisation` parameter rather than being passed an empty
    string, because no caller had anything else to put there.
- **Two real layout bugs found by writing the test for "text tangled together".** Neither was
  literal overlap — `pdftotext -bbox` reports zero overlapping words on every page, before and
  after — which is why looking for collisions would have found nothing.
  - `keyValues()` set 9pt type on 10pt lines: 1.11 em, tighter than Helvetica is drawn, so a
    wrapped label ran into the line beneath it. Now 11.5pt.
  - Labels were sliced to two lines with no mark. Item 1 of the inspection form needs three, so
    "(BCA)" was silently missing from a form somebody signs. Now three lines, and the cut is
    marked if it still overflows.
  - The colon was wrapped with the label, so " :" could break onto a line of its own — item 4
    did exactly that. It is appended after wrapping now, and dropped rather than overflowed when
    it will not fit.
  - `pdf_tight_leading()` is the guard: it measures leading rather than hunting collisions.
- **A full diagnostic sweep found four real defects that all five green suites had missed.**
  Worth reading as a set, because each was invisible to the obvious check:
  - `preflight.php` could not detect the commonest CyberPanel failure. It tested that
    `public/.htaccess` **exists**, and OpenLiteSpeed ignores `.htaccess` unless
    `autoLoadHtaccess 1` is set — so the file was present, the check passed, and the site was
    unusable. It now **probes `/health`**. Only a 404 means the rewrite never arrived; a 500 or
    503 still proves it reached PHP, so anything that is not a 404 counts as working.
  - **The verification QR was too small to scan on the common case.** Fixed 62pt square whatever
    the payload: a record link is 33 modules at 0.533mm and fine, but a report link carries its
    filters, so a date range gave 0.446mm and a filter set at the cap 0.359mm. Rasterised at
    200dpi — roughly what a phone resolves across an A4 sheet — the 0.446mm code would not
    decode. `verification()` now encodes first and sizes the square from the module count, so
    the module stays at ~0.512mm and the square grows instead (62–96pt).
  - **An over-long link would have aborted a whole PDF export.** `QrCode::encode()` throws past
    213 bytes, and the address, slug and filters are all assembled outside `PdfWriter`.
    `encodeVerification()` now logs and returns null: a report without a QR beats an error page.
  - **`upgrade.php --dry-run` reported failures for work that applied cleanly.** Four columns
    are listed in the column pass *and* included in the `sss_enrolments` CREATE TABLE, so they
    reach two different vintages of database. Applying creates the table with its columns; a dry
    run creates nothing, so the column pass called it missing and counted four failures. Against
    a real 40-table database Preview said "4 failed — run migrate.php first" and Apply said
    "0 failed". A `$pendingTables` set fixes it. A genuinely absent table still fails loudly.
  - Plus one of my own: the root `index.php` built a `mv` command from `SCRIPT_NAME` without
    validating it. `/a; rm -rf ~/index.php` printed `mv a; rm -rf ~/.[!.]* ...` as the
    instruction. The name is now accepted only as a single segment of `[A-Za-z0-9._-]`, and
    anything else falls back to a literal `<folder>` placeholder. Escaping for HTML was never
    enough — the string ends up in a root shell.
- **Known limitation, deliberately not fixed here.** `ApiAuth::registerDevice()` checks for
  another active handset and then writes, with no transaction and no constraint behind it —
  MySQL cannot express "one active row per user" as a unique index. Two genuinely simultaneous
  sign-ins for one account can therefore both pass the check and both end up active. Low
  likelihood, low impact (both are legitimate holders of the credentials) and the fix means
  taking a lock on the login path, which deserves its own change with load testing rather than a
  drive-by edit at the end of a diagnostic. If it is done: `SELECT id FROM users WHERE id = :id
  FOR UPDATE` inside `Database::transaction()` serialises it without gap-locking `devices`.
- **Verified clean, so do not go looking again:** every `PdfWriter` division is guarded
  (`table()` has `?: 1.0` on the weight total, the column methods `max(1, ...)`,
  `signatureLines()` returns early on an empty list, `drawLetterhead()` cannot reach its second
  division with a zero width); `Inspections::prefill()` returns an empty array for a missing form
  or a non-existent BCA rather than throwing; a PDF generates with no database reachable at all;
  the `/r/` route's `[A-Za-z0-9_-]+` reference rejects CRLF, traversal and quotes, with no header
  injection; all 26 endpoints `ApiService.kt` declares map to a served route with a matching
  verb; no Moshi DTO property is camelCase without an `@Json` name; and every translatable
  Android string has a Hindi value with matching format specifiers.
- **`install.php` returning 404 has four causes and one status code.** Answered by the
  **browser installer** section of `deploy/preflight.php`, and by a table in
  HOSTING-CYBERPANEL.md.
  - The common one is that the 404 is **correct**: the installer deletes itself the instant it
    succeeds, so that nobody can later point the site at a different database. Preflight reports
    that as a pass, not a failure, and says so in words — the wrong reaction is to re-upload it,
    and the wrong reaction after that is to run it, which drops every table.
  - The others: document root on `public_html` (the installer answers at
    `/public/install.php`, but fix the root — the config is downloadable meanwhile), archive
    never flattened, and the file simply not uploaded to a site that never installed.
  - The root `index.php` diagnosis names the installer too, because somebody whose document
    root is wrong is usually mid-install.
- **A document root left on `public_html` now explains itself.** The root `index.php` is not
  the application: it prints the diagnosis, the CyberPanel setting to change, and the fact that
  `config/config.local.php` is downloadable until it is. It loads no application code and reads
  no configuration, so it cannot bring the site up in the unsafe state that a
  `require public/index.php` shim would. Unreachable once the document root is right, and on
  Apache the root `.htaccess` forwards `/` into `public/` before it is considered.
  - It tells the two mistakes apart from `SCRIPT_NAME`: served at `/index.php` the root is one
    level high; served at `/<folder>/index.php` the archive was never flattened, and that
    folder is the wrapper.
  - The nested layout still 404s at `/` — there is no `public_html/index.php` to run. Nothing
    PHP can do about that; the docs point at the folder URL instead.
  - Measured, not assumed: a server ignoring `.htaccess` (CyberPanel's default) serves
    `/config/config.local.php` with **HTTP 200** when the document root is too high. That is
    the reason this page exists at all.
- **Only the chosen option is marked on a printed form.** Un-chosen options used to carry a
  muted cross. The client reversed it: four options came out as four marked boxes and the tick
  no longer read as the answer. `crossMark()` is gone; the tick is drawn heavier (`line()` now
  takes a width, with round caps) because at eight points a 0.4pt tick is a smudge, and a
  smudge in a box is what gets mistaken for a cross.
  - Counting muted strokes does **not** tell a cross from a signature rule — both use
    `INK_MUTED`. `pdf_diagonal_strokes()` in tests/lib.php looks at the slope instead.
  - `line()` emits `1 J 1 j` now, so any test regex matching a stroke has to allow for it.
- **The bank's letterhead prints in the header of every page of every PDF.**
  `public/assets/img/cbi-logo.jpg` is the bank's own file, taken from their site — a baseline
  JPEG, which is what `prepareImage()` can embed directly (the other file they publish is
  progressive and would not render). It is 252x79, so about 190 DPI at the size it prints;
  a site wanting sharper can point the `site_logo` setting at its own file, which wins. There
  is no upload UI for that yet — it is honoured if set, nothing more.
  - It sits on a white panel because the header bands are navy and the JPEG has no alpha.
  - `PdfWriter` resolves the file itself rather than taking it from a call site, for the same
    reason the footer does: "every page of every PDF" is not something four callers should each
    have to remember. Resolution is cached, including a resolution to nothing.
- **The inspection report prints no system reference.** The client asked for it off: a uuid
  means nothing to the branch signing the sheet. The BCA, BC code, branch and date identify it
  to a person and the QR identifies it to the panel. The visit report keeps its reference — only
  the inspection was asked about.
- **The office on the printed inspection form is a setting**, not a constant. It moved from
  the Bhopal zonal office to `Central Bank of India — Regional Office, Agra` (37/2/4, First
  Floor, Sanjay Place; 0562-2521342; rdagraro@centralbank.bank.in; helpline 1800 233 4035).
  Five keys in the `office` group, edited under **Settings ▸ Office on printed forms**.
  - `RecordExport::officeValue()` reads `Settings::all()` directly rather than `setting()`,
    because `setting()` treats an empty value as absent and hands back the default — which
    would make a line impossible to remove. A row that exists and is empty means "leave this
    line off the page"; a row that does not exist at all means a site that has not been
    re-seeded, and that still prints the client's own office.
  - `upgrade.php` now also calls `lrms_seed_settings()`, so a live site's settings screen shows
    the office it is actually printing instead of five empty boxes.
- **Every exported PDF carries a QR code** back to its record: both record reports, the
  client's official verification report, and every tabular report (where it leads to the live
  figures with the same filters, since a report is a filter and not a row). It needs a panel
  login, so it is not a way of reading customer data off a discarded printout. The reference
  is printed beside it for whoever has no phone.
  - The encoder is hand-written (`app/Services/Export/QrCode.php`) — no Composer on this
    hosting, and `gd` can draw a QR but not encode one. Byte mode, level M, versions 1–10.
  - It is verified three ways in `tests/test-qr.php`: against matrices captured from Python's
    `qrcode` library, against the specification's function-pattern geometry for all ten
    versions, and by a **reader in the test that shares no code with the writer** — it
    unmasks, follows the snake and de-interleaves to recover the payload, which is what a
    scanner does. Regenerate the fixtures with
    `python3 tests/fixtures/regenerate-qr-reference.py`.
  - The format bits were the one thing that had to be got right by brute force: rows and
    columns were transposed, which produces a code that looks entirely convincing and cannot
    be scanned at all. Nothing about the picture tells you.
- **Nothing on a printed page is left hanging on its own.** The client said the reports simply
  looked wrong, and five green suites had all been missing why. Every page of all four PDFs was
  rendered and read, and the answer was seven separate defects, all of them about placement:
  - **A whole wasted page.** The inspection's third page ended 52pt above the margin and the QR
    panel needed 90, so it moved to a fourth page and left 621pt of it blank. The office
    letterhead and the QR are now one block, `PdfWriter::officeFooter()`, QR on the right.
    Four pages became three.
  - **Item 26 appeared twice**, once as a band and once as a heading with the same words, and
    its signature rules were drawn after the whole field walk — so the page read 25, 26, 27,
    then 26 again. It is the only `section` field that is a single item rather than a range of
    them, and it already sits inside the "25-27" group, so a second navy bar nested in the
    group's own bar is what made it look duplicated even after the repetition went.
    `PdfWriter::signatureBlock()` now prints heading, note and rules as one measured block in
    the item's own case.
  - **An orphaned section band.** "11. DECLARATION" ended a page with the declaration itself
    overleaf. `sectionBand()` takes a `$keepWith` hint; the declaration passes the measured box
    height through the new `noticeBoxHeight()`. Both read the same private `measureNotice()`,
    so an accessor cannot drift from what gets drawn.
  - **A clipped caption.** `officeFooter()` sized the QR column for the code alone, so whenever
    a short URL needed a small code the caption was cut to "Scan to open this rec…" — an
    ellipsis in the one line that says what the code is for. The column is sized for whichever
    of code and caption is wider, capped at 42% of the content width.
  - **Captions glued to the next thing.** A signature block ended 15pt past its rule, but the
    caption sits 9.5pt below it, so the descenders of "Signature - BC Agent / DRA" came within
    a few points of the "13. FINAL REPORT STATUS" band. `signatureLinesHeight()` is now the one
    measurement both the drawing and the reservation use.
  - **Tick boxes with nothing to say what they answered.** `checkboxes()` drew its label first
    and checked each row for room on its own, so it broke at either seam: "Sign board at the BC
    point" ended a page with its Yes / No overleaf, and a six-option group split leaving three
    bare boxes at the top of a page. A group that would fit a page is kept whole; one that
    could not — the 39-service list — keeps its label with the first row and lets the rest flow.
    This needed `contentTop`, recorded at the end of `addPage()`, so a caller can ask what a
    page holds rather than only what is left of this one.
  - **A watermark that ate its photograph.** The band's height came from the line count alone,
    so on a small image it was taller than the picture and `imagecopymerge()` covered the whole
    frame — the evidence photograph printed as white letters on black. It is capped now, with
    trailing lines dropped (address before timestamp before coordinates), and each line
    truncated to what the frame is wide enough to show.
  - Verified by rendering all nine pages and reading them, and by decoding every QR at 300 DPI:
    four documents, four codes, each resolving to its own record.
- **Two devices can no longer bind to one account at the same instant.** The check was a SELECT
  for an active device followed by an INSERT, so two overlapping sign-ins could both find
  nothing and both bind. `ApiAuth::registerDevice()` now takes a row lock on the account
  (`SELECT id FROM users WHERE id = :id FOR UPDATE`) for the length of the check and the write,
  with the body moved into `bindDevice()`.
  - The lock is on `users`, not on `devices`: the device set for an account is often empty, and
    locking an empty range takes gap locks that deadlock against the very insert being waited
    for. "One active row per user" cannot be a constraint either — MySQL has no partial unique
    index.
  - It is guarded by a section of `tests/api-smoke.php` that fires ten rounds of fourteen
    parallel sign-ins. That needs `PHP_CLI_SERVER_WORKERS`, which the suite now sets: the
    built-in server is single-process by default, so parallel requests are served in turn and
    the test would pass on unguarded code. Checked both ways — the pre-fix version fails it.
  - Asserting the lock directly was tried and thrown out. Holding the account row from another
    connection stalls an unguarded sign-in just as surely as a guarded one, because `devices`
    and `api_tokens` both reference `users` and the foreign key on the insert needs the held
    row. A check that passes either way is worse than no check.
- **The monthly inspection carries the agent's Social Security Scheme figures**, for a window the
  inspector chooses. Item 16 of the client's form asks whether the agent is *aware* of the
  schemes; what the sheet could never say was how many people they had actually enrolled, because
  those figures lived only in the panel's SSS register.
  - **Nobody types them.** They are read from the enrolment records. `Sss`'s own header states the
    rule — a figure the system already holds must not also be entered by hand, or the agent ends
    up measured on one number while defending another — and a form field on the inspection screen
    is an editable box, so these are deliberately *not* form fields. `http-smoke` asserts that no
    input on the screen is named for a scheme count.
  - The window is two columns on `inspections` (`sss_from`, `sss_to`), defaulting to the
    inspection's own month up to the inspection date, which is the window the SSS screen opens on.
    A range entered backwards is swapped; one date without the other reverts to the default
    rather than quietly measuring a period nobody asked for.
  - **Signing the sheet freezes the figures** into `inspection_sss`. This is the one place a
    derivable number is stored, and the reason is that an inspection is a sheet somebody signs and
    files: a day's enrolments can be corrected afterwards, and an Admin can hand a submitted day
    back for exactly that, so a reprint that recomputed would disagree with the copy in the
    branch's file. The same reasoning already freezes `photo_count` and copies a field's label and
    type onto `inspection_form_values`. Only the raw counts are stored — no total, percentage or
    gap, because a stored total is one that can end up disagreeing with its parts.
    - A draft has no frozen row and shows live figures. An inspection **submitted before this
      existed** has none either, and gets no block at all: putting today's arithmetic onto a sheet
      signed last year is the same mistake pointing the other way.
  - It prints directly under item 16 — not after the walk, which ends at item 27 and the signature
    lines — in the register's own columns, with each scheme cell reading achievement of target.
    Verified to cost **no extra pages**: the same inspection renders to the same page count with
    and without the block.
  - The inspection register gained two columns, "Scheme window" and "Schemes" (achievement of
    target). Two and not five because `writePdf()` prints only the first eleven columns of a wide
    report and this one already had nine — a target, an achievement, a percentage and a gap would
    have pushed Remarks off the printed page, and the percentage is the one figure a reader can
    work out from the pair. A test asserts the column count still fits.
- **A heading is never printed without the thing it heads.** Two faults in the photograph grid,
  both found by rendering a sheet and reading it rather than by a failing assertion:
  - The photographs heading was printed from a **count of database rows**, while the grid below it
    skips any file it cannot read without a word. A site whose uploads had been moved printed
    "Photographs at the BC point" with nothing whatever underneath.
  - The heading was drawn **before the first row of pictures was measured**, so it could finish a
    page with the photographs overleaf.
  - `imageGrid()` now takes the heading, filters to files it can actually read first, returns
    without a word when none survive, and reserves the heading and the first row together. All
    five callers pass their heading in. Both faults are covered by tests that were checked to fail
    against the old behaviour.
- **The app no longer ships its own test tools.** The "Test connection" button and the
  diagnostic notice under the sign-in form are gone, with `AppViewModel.testConnection()`,
  `FieldRepository.testConnection()`, the two `AuthState` fields behind them and their strings
  in both languages. The base URL is no longer printed under the form; the host still appears in
  the two error messages that need it, so support has not lost anything. The version line hides
  the build environment when it is `production`, and still announces a staging build.
  - The developer-build warning stayed. It only fires on `10.0.2.2`, `127.0.0.1` or
    `localhost`, which is a genuine warning that somebody is about to use a debug APK in the
    field, not leftover scaffolding. `api.ping()` stayed too — a declared endpoint the app is
    entitled to call, not test code.

## The rename, the new server, and the reminders

The company is **D2 Square Credit Solutions**. The app carries the name and the new logo, and
talks to `https://server.d2squarecreditsolutions.in/api/v1/`.

The server was not resolving when the rename landed — `server.` had no A record at all and the
apex answered `/api/v1/ping` with the hosting's own 404 — so no APK went out until it did. It
answers now, and **v1.6.4 is published**.

Two things that will not change and should not be "fixed":

- **The signing certificate still reads D2 RECOVERY SOLUTION.** A subject cannot be edited, so
  changing it means a new key, and a new key is a new app to every handset — all of which would
  have to be uninstalled, taking unsent field work with them. See docs/KEYSTORE-SETUP.md.
- **The Central Bank of India letterhead on the printed reports is the bank's**, not the vendor's.
  The rename does not touch it.

One thing to watch at build time: CI's `LRMS_API_URL` repository variable **overrides**
`lrmsReleaseApiUrl`. Reading it needs repository admin rights, so the way to confirm which server
a build actually points at is to read it back out of the APK's compiled BuildConfig, which is what
was done for 1.6.4.

**Keep the old domain redirecting.** Every QR already printed onto a sheet in a branch file points
at `cvbuilder.bharatseo.site`. Switching that off kills every printed QR.

### The reminders

The app had no notification code at all — no channel, no alarm, no receiver — while the server had
been writing reminders since the first release. They arrived on the handset and went into a list
nobody had reason to open.

`in.lrms.field.reminders` now holds all of it: `ReminderClock` (pure arithmetic, unit tested),
`Reminders` (channel, arming, notifying), `ReminderReceiver` and `BootReceiver`.

- **The panel's own settings drive it.** `working_days`, `reminder_minutes` and `server_timezone`
  had been on the wire from the first release and were being discarded by `DeadlineDto`, which is
  why the app could show a countdown but never raise a warning. They are parsed now, so moving the
  deadline in the panel moves the phone.
- **One alarm is armed at a time and the receiver arms the next**, so the schedule walks itself
  forward and repairs itself. `BOOT_COMPLETED`, `TIME_SET`, `TIMEZONE_CHANGED` and
  `MY_PACKAGE_REPLACED` all re-arm — Android clears alarms on all four and says nothing, which is
  the fault that gets reported as "it worked for a week".
- **`SCHEDULE_EXACT_ALARM`, never `USE_EXACT_ALARM`.** The second is granted without asking but
  Google restricts it to clock and calendar apps. `canScheduleExactAlarms()` is checked and an
  inexact alarm used otherwise. A test asserts the entitlement is *absent*.
- **The working-day test is applied to the day of the deadline, not the day the alarm rings**,
  because `report_reminder_minutes` is free text in the panel and a value over 24 hours is
  somebody's to type.
- **Everything is computed in the server's timezone.** The deadline is the server's.
- **Nothing fires signed out**, and the alarms are dropped at sign-out beside the database wipe.
- The server's own notification rows are announced on sync, gated on a high-water mark rather than
  the unread flag — forty rows come down every sync and unread stays true until somebody opens the
  screen. This is what makes the follow-up and promise crons audible.

Not verified: a notification visibly appearing on a handset. There is no Android SDK or emulator in
the sandbox. The arithmetic is unit tested, the wiring is asserted against the built manifest, and
the packaging was read back out of the APK.

## Current state

- Suites: `160 / 383 / 220 / 491 / 107`
  (test-import / http-smoke / api-smoke / test-reports / test-qr).
- Both CI workflows green on the branch. Android: Kotlin compiles, 75 unit tests,
  `lintDebug` clean, release APK and AAB build.
- Room is at **version 6** (`MIGRATION_5_6` adds `status` and `syncMessage` to
  `sss_enrolments`). 42 tables server-side; a fresh install needs **0** upgrade steps, and an
  existing one needs the rename step only.
- APK **v1.6.4** is the published build, signed with the same key as every release since 1.6.0:
  `https://raw.githubusercontent.com/dhdhdh51/zetproshivrj/apk/LRMS-v1.6.4-SIGNED.apk`
  File SHA-256 `74b74a975c00b094e17de9790373ef4899bd0fde1ae35c5f563da20fcaa8bd4b`, verified by
  downloading the published file and checking it against the D2 certificate. Installs over 1.6.0
  through 1.6.3. It carries the rename, the new logo, the new server and the reminders.
  - Read back out of the built APK rather than trusted: versionName, the server URL from the
    compiled BuildConfig, the app name from the packaged resources with the old one confirmed
    gone, both reminder receivers and their permissions from the merged manifest, the Hindi
    reminder wording, and all sixteen brand images by size — R8 obfuscates resource names in a
    release build, so size is what identifies them.
  - 1.5.4 onwards all stay on the `apk` branch. A download link outlives its release.
- Web panel published to the `web-app` branch at `79c977c` — the server pulls from it.
- The repository has been renamed twice and is now `dhdhdh51/zetproshivrj`. `git push` still
  works through the old remote, GitHub redirects it; `gh api` does not follow the rename, so
  it needs the current name. Old `raw.githubusercontent.com` links do still resolve, but new
  documentation should use the current name.

## The signing key was replaced at v1.6.0

The original keystore was lost. It was never a repository secret and no copy survived, so CI
fell back to Android's debug key and the build could not be handed out.

On the user's instruction a new key was generated and v1.6.0 signed with it:

```
SHA-256  b7d11c52707969d94ac3a6c62129ab2b1453437a2c2e02064c2123339e0294a4
Alias    lrms      RSA 4096      expires 6 January 2054
```

`EXPECTED_CERT_SHA256` in the Android workflow, `docs/KEYSTORE-SETUP.md` and the steering
file all carry that fingerprint now. Releases up to v1.5.5 were signed with the old
`8bb48d4e…` key, which is why:

- **v1.6.0 cannot install over v1.5.5.** Handsets must sync, uninstall, then install v1.6.0.
  Every release after v1.6.0 installs straight over it.
- Telling supervisors to **sync first** is not optional. Uninstalling takes the local database
  with it, and until a signal returns the outbox is the only copy of a day's field work.

**The keystore is not in this repository and not in the sandbox's git history.** It was written
to `/projects/keystore/lrms-release.jks` with its password in `/projects/keystore/PASSWORD.txt`,
and the sandbox is not durable — the user was given the base64 and the password to store, and
asked to put the four signing secrets on the repository so CI can sign by itself. If a later
session finds no keystore and no secrets, ask them for it before building a release; do not
generate a third key without saying what it costs.

## Open, not blocking

- The user has not confirmed the SSS wording against the reference repo. Labels live in
  `resources/lang/{en,hi}.php`, `android/app/src/main/res/values{,-hi}/strings.xml` and
  `App\Services\Sss::schemes()` / `schemeNames()`.
- The panel's SSS screens are hardcoded English, like every other admin screen. Only
  navigation is translated (`nav.sss`, `nav.sss_targets`).
- The BC Supervisor's own panel corrections are still allowed on a closed day (attributed as
  `source = 'panel'`). That is deliberate — the lock stops a reported figure moving quietly,
  not the branch fixing a mistake.
- The QR codes point at `url('/admin/...')`, which resolves through `app.url` in
  `config/config.php`. On a site where that is still `https://yourdomain.com` the code will
  encode a host nobody can reach. It falls back to the request host, so a panel-generated PDF
  is right either way; a PDF generated from the command line on a misconfigured site is not.
- No **public** verify route was added. A code resolves through `/r/{type}/{reference}`, which
  requires a login and sends whoever scans it to the panel they are allowed to use — the admin
  record for a BC Supervisor, the branch portal for a Branch Manager, `/app-only` for a BCA.
  Scanned while signed out it stores the intended URL and goes to sign-in, so they still land
  on the record. That was the safe default: an open route would publish borrower detail off a
  discarded printout. If the client asks for something a branch officer can check without
  signing in, it has to be a page that confirms the document exists and says nothing else.
- `/r/` is deliberately short because every character is another module in a code somebody has
  to scan off a photocopy. Do not lengthen it for tidiness.

## Things that will waste your time otherwise

- **The settings screen is one form, and `saveSettings()` reads every group from it.** A post
  that leaves a field out clears it — an absent checkbox is an unticked checkbox. A test that
  posted only the office fields switched photo watermarking off and broke a later suite. Send
  the current values back with whatever you are changing, the way a browser does.
- **The steering file is wrong about one thing.** It claims a test asserts `SHOW CREATE TABLE`
  is byte-identical between `schema.sql` and `upgrade.php`. No such test exists. What exists
  is `tests/http-smoke.php` running the upgrade over HTTP against a `schema.sql`-built
  database and asserting `0 failed` — which cannot catch a definition that differs, because
  every pass short-circuits on `columnExists()`. Check new columns by hand against a
  simulated older database.
- **`upgrade.php` has no foreign-key pass.** A new column cannot carry a constraint. Either
  ship the table whole through `$newTables`, or leave the column without a key and say so
  (see `reopened_by`).
- **PDO runs with `ATTR_EMULATE_PREPARES => false`**, so a named placeholder used more than
  once in one statement is an error, not a convenience. `Reports::sssTarget()` writes its
  per-month multiplier into the SQL for exactly this reason.
- **Sandbox:** MariaDB *and* `php -S` both die between bash calls — do setup and assertions
  in **one** call. Serve on **port 8000**, because `config/config.local.php` sets `app.url`
  to `127.0.0.1:8000` and on any other port the app's own redirects point somewhere dead and
  ~21 checks fail in ways that look like flakiness. Pass
  `-d session.save_path=/projects/sessions`, because `/tmp` is wiped. And never
  `pkill -f "php -S 127.0.0.1:8000"` — the pattern matches the running script's own command
  line, so it kills its own shell.

## Talking to the user

They write in Hinglish and expect the same back. They are not a programmer: they care about
whether the app works on the handset, whether the panel shows the right figures, and whether
they have a download link. Lead with what changed for them, not with file names.

They cannot see the filesystem or terminal output. Surface anything that matters in the
reply, or push it and give them a link.

They have said they want to install **once**, after the work is complete, rather than
updating in steps.
