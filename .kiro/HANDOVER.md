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

## Current state

- Suites: `160 / 309 / 216 / 406 / 93`
  (test-import / http-smoke / api-smoke / test-reports / test-qr).
- Both CI workflows green on the branch. Android: Kotlin compiles, 46 unit tests,
  `lintDebug` clean, release APK and AAB build.
- Room is at **version 6** (`MIGRATION_5_6` adds `status` and `syncMessage` to
  `sss_enrolments`). 42 tables server-side; a fresh install needs **0** upgrade steps, and an
  existing one needs the rename step only.
- APK **v1.6.2** is the published build, signed with the new key:
  `https://raw.githubusercontent.com/dhdhdh51/zetprobbbvHGY/apk/LRMS-v1.6.2-SIGNED.apk`
  File SHA-256 `bc9c02d59900d34afa857f7b54d6935a5ab8fd398b8348c365ed2b24fe2fec3e`, verified by
  downloading the published file. It carries the target screens and the BCA rename in both
  `values/strings.xml` and `values-hi/strings.xml`. Installs over 1.6.0 and 1.6.1.
- Web panel published to the `web-app` branch at `d674da9` — the server pulls from it.

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
