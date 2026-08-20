# Where things stand

Last updated: 18 August 2026. Delete or rewrite this once it stops being true — a stale
handover is worse than none.

Working branch: `feat/lrms-loan-recovery-system`, open as PR #2.

## Shipped and verified

Everything below is on the branch, green in CI, and live-ready.

- **SSS enrolments** — the four Social Security Scheme counts (APY, PMJJBY, PMSBY, PMJDY)
  per BC Supervisor per day, in the app and the panel. Ported from the client's reference
  repo. One row per supervisor per day, enforced by a unique key; every write is an upsert,
  because the offline outbox can deliver the same day twice and appending would double every
  total built on it.
  - Panel: **Field work ▸ SSS enrolments** — month-to-date totals, branch/supervisor/date
    filters, a per-supervisor breakdown, and an `sss` report with PDF/CSV export. Recording a
    day that already has figures opens the correction screen rather than overwriting silently.
  - App: a screen off the home page. Blank box means none. Correcting a day while still
    offline replaces the queued entry rather than queueing a second one.
  - Backdating window is a setting (`sss_backdate_days`, default 30). The reference allowed
    only one day, which would silently discard an offline week.
- **Browser database update** — **Settings ▸ Update the database**. It exists because every
  documented upgrade path needed a terminal and this hosting has none, so the only route
  anyone found was deleting the files and reinstalling, which drops every table.
- **Field visit format** — the client's 27-item "New Field Visit Format" replaced the old
  admin inspection form, added as a new version so historic inspections still print the
  questions they were actually answered against.

## Current state

- Suites: `160 / 233 / 184 / 346` (test-import / http-smoke / api-smoke / test-reports).
- Both CI workflows green on the branch.
- Web panel published to the `web-app` branch — the server pulls from it.
- APK **v1.5.5**, signed and published:
  `https://raw.githubusercontent.com/dhdhdh51/zetprobbbvHGY/apk/LRMS-v1.5.5-SIGNED.apk`
  (v1.5.5 is server-side-plus-app; the browser update change after it is server-only and
  needs no new APK.)

## Open, not blocking

- The user has not confirmed the SSS screens against the reference repo's own wording. If
  they come back with field-name corrections, the labels live in
  `resources/lang/{en,hi}.php`, `android/app/src/main/res/values{,-hi}/strings.xml` and
  `App\Services\Sss::schemes()` / `schemeNames()`.
- No en/hi key-parity check exists for the app as a whole — only for the SSS strings
  (`SssTest`). Worth widening if Hindi drifts again.

## Talking to the user

They write in Hinglish and expect the same back. They are not a programmer: they care about
whether the app works on the handset, whether the panel shows the right figures, and whether
they have a download link. Lead with what changed for them, not with file names.

They cannot see the filesystem or terminal output. Surface anything that matters in the
reply, or push it and give them a link.
