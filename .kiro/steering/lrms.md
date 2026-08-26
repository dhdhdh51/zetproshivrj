# LRMS — what to know before changing anything

Loan Recovery Management System for a bank's BC (Business Correspondent) network in Bihar.
Two halves of one product, and a change usually touches both:

- **Web panel** — plain PHP, no framework, no Composer dependencies. It has to boot on bare
  shared hosting where `composer install` was never run.
- **Android app** — Kotlin, Compose, Room, Retrofit/Moshi, single Activity, single ViewModel,
  no DI framework. Lives in `android/`.

The people using it are BCAs on cheap handsets in villages with bad signal, and
branch staff on Windows machines. Both of those shape more decisions here than any
architectural preference.

## Standing rules — do not re-litigate these

These came from the client directly and have each been stated more than once.

- **No cash, no payments, no money collection.** "हमको payment ही नहीं लेनी, हमारा काम बस visit
  का है." The field job is visiting and reporting. Recovery amounts are recorded by the
  branch, never taken by an agent. The `recovery` outbox type still exists only because a
  handset updating from an old build may be holding one.
- **Nothing is mandatory in the app.** No required photograph, no required GPS fix, no
  required field. A village with no signal is not a reason to throw away a real visit. If
  something must be encouraged, encourage it — do not block the submit button on it.
- **No signature capture.** Signatures stay on paper.
- **Never remove or weaken the offline outbox.** It is not a cache. Until a signal returns it
  is the only copy of a day's field work. Anything that could wipe it — a destructive Room
  migration, a "clear local data" convenience — is a data-loss bug.
- **Hindi must actually render.** The single Activity must stay an `AppCompatActivity` and the
  window theme a `Theme.AppCompat` descendant. `AppCompatDelegate.setApplicationLocales` is a
  support-library backport below Android 13 and reaches a screen only through AppCompat's
  delegate — with a plain `ComponentActivity` the choice saves and every label comes back in
  English. Every user-visible string goes in **both** `values/strings.xml` and
  `values-hi/strings.xml`; nothing warns you about a missing Hindi key.
- **KRM OTS and CKCC OD-2 are separate work streams.** Separate lists, separate registers,
  separate report columns. Do not merge them.
- **Printed forms: only what was chosen is marked.** A tick on the chosen option, an empty box
  on everything else. Un-chosen options used to carry a muted cross so a reader could see the
  option had been offered rather than skipped; the client reversed that — "jis par tick kar
  rahe hai vo bhi cross aata hai, true nahi" — because a row of four options came out as four
  marked boxes and the tick stopped standing out. Every option is still printed, so a group
  nobody answered is visible as a group with no tick in it.
- **The bank's letterhead goes in the header of every page of every PDF**, not just page one:
  these sheets get unstapled and filed, and a loose page has to still be the bank's.
  `public/assets/img/cbi-logo.jpg` is the shipped default; a `site_logo` setting pointing at a
  readable file overrides it. `PdfWriter` resolves it itself, so no call site has to remember.
  It sits on its own white strip above the band, not inside it — the page is already white, so
  no panel is needed and the mark can be letterhead-sized.
- **No organisation name on a printed page.** The letterhead already carries the bank's name in
  Hindi and English; printing the system's name over it said nothing and crowded the block.
  `documentHeader()` takes no organisation, `header()` is passed an empty subtitle, and the
  footer is the confidentiality notice alone.
- **Leading, not just font size.** Advancing a cursor by the point size puts baselines exactly
  one em apart, which is tighter than any face is drawn for: descenders land in the ascenders
  below and the block reads as tangled without technically overlapping. Around 1.3 em is
  ordinary text leading. `pdf_tight_leading()` in tests/lib.php fails any pair of stacked lines
  set closer than 1.15 em.
- **British spelling** in identifiers and copy: `organisation`, `enrolment`.
- App name is **D2 RECOVERY SOLUTION**.
- **Who is called what.** The agent at the outlet is the **BCA** (Business Correspondent
  Agent) and uses the Android app. The panel account that monitors them, inspects their
  outlet and approves their visits is the **BC Supervisor** — what the code used to call
  "Admin / Supervisor". The client was explicit: "Bc Supervisor Ko Hata Ke Har Jagh BCA
  KARDE AND JO HAMARA ADMIN HAI VO HAI ASLI BC SUPERVISOR."

  The rename is **user-visible text only**. These all keep the old names and must not be
  "tidied up": the `bc_supervisors` table, every `bc_supervisor_id` column, the `admin` and
  `bc_supervisor` role slugs, the form field keys, and the API routes. Handsets in the field
  post to those routes with those keys, and every record already filed points at those
  columns — renaming them breaks the app mid-shift and rewrites history.

  Three things carry the old wording in the **database** rather than the code —
  `roles.name`, `report_types.name` and `inspection_forms.name`. A fresh install gets the new
  wording from `seed.php`; an existing one gets it from the rename step in `upgrade.php`.
  Note the swap: the admin role takes the name the agent role used to have, so anything
  matching on the old name must also check the slug or it will rename the wrong row.

  One exception stays: item 11 of the inspection form reads "District Coordinator / BC
  Supervisor, with contact number" because that is what the client's printed paper says.

## Updating a live site

Never reinstall to update. `public/install.php` drops all 41 tables before building an empty
system, and its guard recognises an existing installation by `config/config.local.php` and
`storage/installed.lock` — so deleting the files to "start clean" is exactly what gets past
the guard and destroys the data.

The supported paths, both running the same script:

- Panel: **Settings ▸ Update the database** (Admin only) — preview, then apply.
- Terminal: `php database/upgrade.php --dry-run` then `php database/upgrade.php`.

`database/upgrade.php` only adds what is missing, checks `INFORMATION_SCHEMA` before every
step and never drops a column or rewrites a row. It must stay includable, so: no shebang
(it pushes `declare(strict_types=1)` off the first line) and no `exit()` unless
`PHP_SAPI === 'cli'`.

**A new table needs a `$newTables` entry in `upgrade.php` as well as `schema.sql`.** A fresh
install runs one and a live database runs the other; a test asserts `SHOW CREATE TABLE` is
byte-identical between them.

**A new setting needs a row in `database/seed.php` and an input on the settings screen.** It
is read through a code default until the row exists, so a seeded-but-unrendered setting is a
constant with extra steps.

## Tests

Five suites, in this order, always against a freshly migrated database. The order is not a
style choice: `test-import.php` is what puts the loan accounts in, and `api-smoke.php` reads
them — run it first and the API suite quietly reports zero allocated accounts.

```
bash /projects/sandbox/mysql-up.sh          # sandbox only; MariaDB is not persistent
php database/migrate.php --fresh --demo
php tests/test-import.php
PHP_CLI_SERVER_WORKERS=16 php -S 127.0.0.1:8000 -t public &   # http- and api-smoke need a server
php tests/http-smoke.php http://127.0.0.1:8000
php tests/api-smoke.php  http://127.0.0.1:8000
php tests/test-reports.php
php tests/test-qr.php                       # pure computation, no server or database
```

Counts: `160 / 374 / 220 / 444 / 107`.

`PHP_CLI_SERVER_WORKERS` is not decoration. `api-smoke.php` has a section that fires fourteen
sign-ins at once to prove an account cannot end up with two live handsets, and the built-in
server is single-process by default — it would serve them in turn and the section would pass
whether or not the code guards against it. The suite sets the variable itself when it starts its
own server; hand it a base URL and it is yours to set.

Lint everything:

```
find app config database public routes resources tests bin deploy -name '*.php' -print0 \
  | xargs -0 -n1 php -l | grep -v "No syntax errors"
```

`tests/test-reports.php` is not re-runnable on a dirty database (it uses fixed inspection
UUIDs) — migrate fresh first. New test sections should clear their own rows so they do not
end up testing the previous run.

## Testing deployment and the upgrade path

- **`php -S` cannot be used to test hosting layouts.** When a request matches no file it walks
  up the tree looking for an `index.php` to hand it to, which turns the 404s that every
  deployment complaint is about into 200s. Use a router script that serves a real file, serves a
  directory's own `index.php`, and 404s on everything else — that is OpenLiteSpeed with
  `autoLoadHtaccess` off, which is CyberPanel's default.
- **The symptom that looks like something else:** with a *correct* document root but rewrite
  rules not applied, `/` works (it resolves to `public/index.php` as a directory index) and
  every other URL including `/login` returns 404. `preflight.php` probes `/health` to catch it;
  checking that `public/.htaccess` exists on disk does not, because the file is present and
  ignored.
- **To test the upgrade path, build the old database with the old code.** `git worktree add
  --detach /somewhere <old-commit>`, point its `config.local.php` at a scratch database, and run
  its own `migrate.php --fresh --seed`. Then run the current `upgrade.php` against it.
- **`migrate.php --fresh` only drops the tables in its own `schema.sql`.** Re-running an old
  `--fresh` over a database that a newer upgrade has touched leaves the newer tables behind, and
  the result is not a pristine old install. `DROP DATABASE` to get one.
- Measured on the `f16f4de` → HEAD path: an upgraded old database and a fresh install are
  byte-identical for all 42 tables once `AUTO_INCREMENT` is normalised, and every row survives.

## Sandbox facts that will waste your time otherwise

- **MariaDB dies between bash calls and `/tmp` is wiped.** Do setup and assertions in **one**
  call. Write artefacts to `/projects/tools/`, never `/tmp`.
- **There is no Android SDK and no `kotlinc` here.** `./gradlew` cannot run. Kotlin only
  compiles in CI: push a `build/X.Y.Z` branch, the workflow leaves the APK on
  `staging/vX.Y.Z`, fetch it with git.
- `Auth::setUser()` in a CLI script needs the role joined, or `Acl` breaks:
  `SELECT u.*, r.slug AS role FROM users u JOIN roles r ON r.id = u.role_id`.
- The panel login field is named `login`, not `email`.

## Release

- Web: `bash deploy/publish-web-branch.sh --push` → the `web-app` branch, which the live
  server pulls. Never commit to that branch by hand; it would reject the next publish.
- APK: push `build/X.Y.Z` → collect from `staging/vX.Y.Z` → sign with
  `deploy/sign-apk.sh <apk> /projects/keystore/lrms-release.jks` → publish to the `apk`
  branch. Every release must be signed with that same key or it will not install over the
  previous build. Confirm the certificate SHA-256 is
  `b7d11c52707969d94ac3a6c62129ab2b1453437a2c2e02064c2123339e0294a4`.
- **Never commit the keystore or any password.**
- The GitHub repo has been renamed more than once; read the current name from
  `git remote -v` rather than assuming. Use `gh api` for pull requests and issues —
  `gh pr create` and the other GraphQL-backed `gh pr`/`gh issue` subcommands always fail in
  this environment. Delete `build/*` and `staging/*` branches once the APK is collected.
