# Where things stand

Last updated: 20 August 2026. Delete or rewrite this once it stops being true — a stale
handover is worse than none.

Working branch: `feat/lrms-loan-recovery-system`, open as PR #2.

## Shipped and verified

Everything below is on the branch, green in CI, and live-ready.

- **SSS enrolments** — the four Social Security Scheme counts (APY, PMJJBY, PMSBY, PMJDY)
  per BC Supervisor per day, in the app and the panel. One row per supervisor per day,
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

## Current state

- Suites: `160 / 257 / 200 / 346` (test-import / http-smoke / api-smoke / test-reports).
- Both CI workflows green on the branch. Android: Kotlin compiles, 46 unit tests,
  `lintDebug` clean, release APK and AAB build.
- Room is at **version 6** (`MIGRATION_5_6` adds `status` and `syncMessage` to
  `sss_enrolments`). 42 tables server-side; a fresh install needs **0** upgrade steps.
- Web panel published to the `web-app` branch — the server pulls from it.
- APK **v1.5.5** is still the published build:
  `https://raw.githubusercontent.com/dhdhdh51/zetprobbbvHGY/apk/LRMS-v1.5.5-SIGNED.apk`
  It does **not** contain the target screens. See below.

## Blocking: there is no way to sign v1.6.0

The repository has **no signing secrets**, and no keystore is present in the sandbox. CI
therefore signed the v1.6.0 build with the Android debug key: certificate
`402dc5fff243bb4cbf0e34f50158b2f29cd568876defd4403675867b47ed5b38`, not the D2 release
certificate `8bb48d4ef31a3504c40d7268a8d2bd3da6b06c19ad5004340354f15c6a324355`.

It is on `staging/v1.6.0` as `LRMS-v1.6.0-DEBUGKEY-DO-NOT-DISTRIBUTE.apk` and was
deliberately **not** published to the `apk` branch. It cannot install over v1.5.5, and
installing it means uninstalling the old app first — which wipes any unsynced outbox entries
on that handset.

To finish the release, one of:

- put `lrms-release.jks` back on the machine and run
  `deploy/sign-apk.sh <apk> <keystore>`, or
- add the `KEYSTORE_BASE64`, `KEYSTORE_PASSWORD`, `KEY_ALIAS` and `KEY_PASSWORD` secrets to
  the repository and re-run the workflow.

Then confirm the certificate SHA-256 matches the D2 fingerprint above before publishing.

The server half is complete and needs no APK: the panel, the targets, the register and the
reports all work today.

## Open, not blocking

- The user has not confirmed the SSS wording against the reference repo. Labels live in
  `resources/lang/{en,hi}.php`, `android/app/src/main/res/values{,-hi}/strings.xml` and
  `App\Services\Sss::schemes()` / `schemeNames()`.
- The panel's SSS screens are hardcoded English, like every other admin screen. Only
  navigation is translated (`nav.sss`, `nav.sss_targets`).
- The Admin's own panel corrections are still allowed on a closed day (attributed as
  `source = 'panel'`). That is deliberate — the lock stops a reported figure moving quietly,
  not the branch fixing a mistake.

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
