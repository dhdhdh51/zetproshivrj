# LRMS operations runbook

Day-to-day running of LRMS, for the Admin/Supervisor who owns the system.
Installation is in [`DEPLOYMENT.md`](DEPLOYMENT.md).

Throughout: **Admin/Supervisor** is one role with full control — there is no
separate super admin. Admin/Supervisors do **not** perform customer recovery
visits; their field activity is BC Supervisor inspection only.

---

## Setup, once

| Step | Where | Notes |
| --- | --- | --- |
| 1. Change the seeded admin password | prompted at first sign-in | `admin@lrms.local` / `ChangeMe@123` must not survive day one |
| 2. Create branches | **Branches ▸ Add** | code, name, district, and the branch centroid (latitude/longitude) used for GPS drift checks |
| 3. Create Branch Managers | **Staff ▸ Branch Managers** | one branch each; they see nothing outside it |
| 4. Create BC Supervisors | **Staff ▸ BC Supervisors** | the **BC code** must match the code used in the bank's Excel sheets, or auto-allocation cannot match on it |
| 5. Review the visit form | **Forms ▸ Visit form** | 21 fields are seeded; add or reorder to suit |
| 6. Review the inspection form | **Forms ▸ Inspection form** | 11 fields seeded |
| 7. Set the deadline and rules | **Settings** | see the settings table below |
| 8. Install the cron entry | server | nothing below the "Automated" heading happens without it |
| 9. Set targets | **Targets** | daily/monthly, per supervisor or per branch |

Hand each supervisor their username and first password. They sign in on the
Android app; the app forces a password change, then binds to that handset.

---

## The daily cycle

### Morning — load and allocate work

Loan data arrives as a spreadsheet from the bank. **Imports ▸ New import**:

1. **Upload** `.xlsx` or `.csv`. (Legacy `.xls` is not supported — re-save it as
   `.xlsx`.)
2. **Mapping.** Columns are matched automatically with confidence scoring —
   `A/C No` → Account Number, `OD Amount` → Overdue, `NPA Dt` → NPA Date. Every
   system field has a dropdown of the detected headers; fix anything wrong.
   *Save as template* if this layout recurs — templates match on column caption,
   so they survive columns moving.
3. **Preview.** Nothing has been written yet. The preview lists mapped values and
   every problem found: missing required fields, unparseable amounts and dates,
   duplicates inside the file, unknown branch codes, invalid BC codes.
4. **Import.** Accounts that already exist are **updated, not duplicated**.
   Rejected rows are kept against the import so they can be fixed and re-uploaded.

Then **Accounts ▸ Allocation**:

- Rows carrying a BC code go to that supervisor automatically.
- The rest are distributed by current workload.
- **Balance branch** evens out an unbalanced branch.
- Manual and bulk reassignment require a reason, which is audited.

An account has exactly **one** live owner — the database enforces it, not just the
application. Cross-branch allocation is refused.

### During the day — watch the field

**Monitoring** shows each supervisor as online or offline, their last known
position and **how old it is**, and today's visit count. Click a supervisor for
their route.

> This is last-known reporting, not live tracking. While a handset is offline or
> location permission is off, nothing arrives — the screen shows the age of the
> last point rather than pretending otherwise. Judge a supervisor on the age, not
> on a dot standing still.

Work arriving from the app: **Visits** (register, drill into any visit for its
form answers, photographs, GPS points and a PDF), **Accounts ▸ account page**
(recovery, PTP and follow-up history).

### Evening — the deadline

`report_deadline_time` (default 18:00) on `report_working_days`. Supervisors get
reminders at `report_reminder_minutes` before it (default 60/30/10). The **server
clock decides** lateness; a supervisor changing their device time gains nothing.

**Deadline ▸ Late submissions** is the approval queue. Each row shows the
supervisor, the date, how late, their stated reason and the day's counts. Approve
or reject with remarks — both are recorded against the submission with your name
and the time.

Visits submitted after the deadline are flagged `is_late` and appear in the
reports as such, whether or not the daily report itself was approved.

To close a day off manually: **Deadline ▸ Lock**. The cron does this at 01:00
anyway.

---

## BC Supervisor inspection (TYPE B)

Verification that a supervisor actually did the allocated work. This is an
**Admin/Supervisor** activity done from the web panel while in the field, and is
kept entirely separate from the customer visit report, from KRM OTS and from CKCC
OD-2 — separate table, separate form, separate report.

1. **Inspections ▸ Supervisors** — pick a supervisor and review their allocated
   accounts, visits, recoveries and coverage before going out.
2. **Start inspection** — captures *your* GPS.
3. Fill the inspection form, upload inspection photographs
   (`min_inspection_photos`), record the verification result and remarks.
4. **Submit.** The report records the distance between your point and the
   supervisor's recorded point for that work, which is the evidence that the
   inspection happened where the work was claimed.

Inspections have their own register and PDF, and feed **BC Supervisor
Performance**.

---

## Reports

**Reports** lists 13 reports, grouped as Field work, Work streams, Money and
Performance. Each has its **own** filters — date range, branch, supervisor,
status, category, amount — and exports to **PDF, Excel or CSV**.

| Report | Answers |
| --- | --- |
| Customer Visit | what was visited, by whom, with what evidence |
| BC Supervisor Inspection | which supervisors were verified, and the outcome |
| KRM OTS | one-time-settlement tracking |
| CKCC OD-2 Renewal | renewal tracking |
| Recovery | money collected, by mode/date/branch/supervisor |
| PTP | promises and whether they were kept |
| Follow-up | pending and completed actions |
| Attendance | check in/out, hours, visits per day |
| GPS | coordinates, accuracy, validation results |
| Photo | photographic evidence captured |
| Target | target vs achievement, pending, percentage |
| Branch Performance | branch visits, recovery, coverage |
| BC Supervisor Performance | supervisor visits, recovery, inspection outcomes |

The day-end submissions themselves live under **Deadline**, not here. Per-record
PDFs exist for a single Customer Visit and a single Inspection.

Branch Managers see the same reports scoped to their own branch — enforced in
`App\Core\Acl` and covered by tests that assert another branch's rows never
appear.

**PDF and non-Latin script.** The built-in PDF writer uses the standard Helvetica
fonts, so Devanagari names are transliterated. When a report must preserve
non-Latin script exactly, export Excel or CSV.

Generated export files are purged after 14 days by the cron (they can always be
re-run) and their history after 60 days.

---

## Staff and device administration

**Staff ▸ BC Supervisors ▸ (supervisor)**

| Action | When |
| --- | --- |
| **Reset device** | new or replaced handset. Required — with device binding on, a second device is refused until you do this |
| **Block device** | lost or stolen handset. Revokes its tokens immediately |
| **Reset password** | forces a change at next sign-in |
| **Unlock account** | after repeated failed sign-ins locked it |
| Set status inactive | the person has left. Revokes every active token |

Every one of these is audited with your name, the time and the reason.

A BC Supervisor who tries the web portal is told the work happens in the app,
rather than being shown a half-working UI.

---

## Settings

**Settings** — changes are audited with before/after values.

| Setting | Default | Effect |
| --- | --- | --- |
| `report_deadline_time` | `18:00` | when the day closes |
| `report_working_days` | Mon–Sat | non-working days are never counted late |
| `report_reminder_minutes` | 60, 30, 10 | reminder points before the deadline |
| `allow_late_submission_requests` | on | off = late submission is refused outright, not queued |
| `min_visit_photos` | 1 | a visit cannot be submitted with fewer |
| `min_inspection_photos` | 1 | same, for inspections |
| `require_borrower_signature` | off | on = a visit needs the borrower's signature |
| `watermark_photos` | on | stamps name, time and coordinates into the image |
| `gps_max_accuracy_metres` | 200 | worse readings are rejected |
| `gps_max_drift_metres` | — | optional distance limit from the branch centroid |
| `gps_mock_location_allowed` | off | leave off in production |
| `supervisor_offline_minutes` | — | when Monitoring calls a supervisor offline |
| `payment_modes` | Cash, Bank Transfer, UPI, Cheque, Other | offered in the app |
| `device_binding` | on | one handset per supervisor |
| `api_token_ttl_days` | 30 | how often a supervisor must sign in again |
| `otp_app_login` / `otp_web_login` | off | **needs an SMS gateway** — see below |
| `maintenance_mode` | off | web portal shows a maintenance page; the API reports it in `/ping` |

**OTP.** Without an SMS gateway configured, codes are written to the application
log so staging works. Never enable OTP in production without a gateway — staff
will be locked out.

---

## Automated tasks

One cron entry; the script decides what is due:

```cron
*/5 * * * * /usr/bin/php /path/to/lrms/bin/cron.php >> /path/to/lrms/storage/logs/cron.log 2>&1
```

| Task | When | Does |
| --- | --- | --- |
| `reminders` | every run | pre-deadline reminders to supervisors |
| `followups` | ~09:00 | reminds supervisors of follow-ups due today |
| `promises` | 01:00 | marks promises whose date has passed broken or partly kept |
| `lock` | 01:00 | locks yesterday's unsubmitted reports |
| `attendance` | 01:00 | marks yesterday's absentees |
| `housekeeping` | 02:00 | purges expired OTPs, export files > 14 days, export history > 60 days |

Any task can be run by hand: `php bin/cron.php promises`. A failure exits non-zero
and writes to `storage/logs/`, so a monitored cron will tell you.

---

## The audit log

**Audit** records every sign-in and failure, import, allocation and reassignment
(with the reason), visit, inspection, recovery, promise, approval, device reset or
block, settings change and export — with the actor, time, IP and **before/after
values**. Passwords and tokens are redacted.

Filter by user, entity type, action or date range. This is the first place to look
when someone asks "who changed this account's supervisor, and why".

---

## Health checks

| Question | Where |
| --- | --- |
| Is the app talking to the server? | `GET /api/v1/ping` |
| Are supervisors syncing? | **Monitoring** — last seen age per device |
| Did anything fail overnight? | `storage/logs/cron.log` and `storage/logs/` |
| Is a report late without a reason? | **Deadline ▸ Late submissions** |
| Are photographs arriving? | **Reports ▸ Photo** for today |
| Did an import silently drop rows? | **Imports ▸ (import)** — accepted vs error rows |

After changing anything on the server:

```bash
php tests/test-import.php    # import, allocation, exports   (91 checks)
php tests/http-smoke.php     # every screen, all reports     (79 checks)
php tests/api-smoke.php      # the Android API end to end   (101 checks)
```

These run against a real database, so point them at a scratch one — they migrate
it fresh.

---

## Common situations

| Situation | Do this |
| --- | --- |
| Supervisor has a new phone | **Staff ▸ BC Supervisors ▸ Reset device**, then they sign in again |
| Phone lost | **Block device** — tokens are revoked immediately |
| Supervisor says the app will not submit a visit | Photograph count below `min_visit_photos`, no valid GPS point, or a required form field empty. The app states which |
| "GPS rejected" in the field | Accuracy worse than `gps_max_accuracy_metres`, indoors, or mock location on |
| Import shows "unknown branch" | The sheet's branch code does not match any branch code. Fix the branch, or map the column to the right field |
| Auto-allocation ignored the BC code | The code in the sheet does not match the supervisor's **BC code** exactly |
| Supervisor left the organisation | Set status inactive (revokes tokens), then reassign their accounts with a reason |
| Deadline was missed for a real reason | Approve the late submission with remarks — the reason and your decision are both recorded |
| A recovery was recorded twice | It cannot be, from the app: replays are rejected by UUID. A genuine duplicate entered twice with different receipt numbers is rejected on the receipt number |
| Numbers look wrong in a PDF for a Hindi name | Export Excel or CSV instead; the PDF fonts are Latin-only |
