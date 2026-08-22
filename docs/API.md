# LRMS REST API (`/api/v1`)

The API exists for one client: the **LRMS Field** Android application used by BC
Supervisors. BC Supervisor and Branch Manager users work in the web portal and
have no API surface — a token for either role is refused with `403 wrong_role`.

Routes are defined in [`routes/api.php`](../routes/api.php); the controllers are
`app/Controllers/Api/{AuthController, SyncController, FieldController}.php`.

---

## Conventions

**Base URL** — `https://<host>/api/v1`. The Android build compiles this in; see
[`ANDROID.md`](ANDROID.md).

**Content type** — send `Content-Type: application/json` with a JSON body. Two
endpoints also accept `multipart/form-data`: visit photo upload and attendance
check-in (selfie).

**Headers**

| Header | When | Notes |
| --- | --- | --- |
| `Authorization: Bearer <token>` | every authenticated route | 80 hex characters |
| `X-Device-Id: <device uuid>` | every authenticated route | must match the device the token was issued to while `security.device_binding` is on |
| `Accept: application/json` | always | |

**Envelope** — every response, success or failure, uses the same shape. The app
can branch on `success` alone.

```json
{ "success": true,  "data": { }, "server_time": "2026-08-18 15:42:10" }
{ "success": false, "message": "Human readable sentence.", "code": "machine_code", "errors": { "field": "message" } }
```

`message` is written to be shown to a supervisor as-is. `errors` is present only
for `422 validation_failed`.

**Status codes and codes**

| Status | `code` | Meaning |
| --- | --- | --- |
| 200 / 201 | — | success (201 when a row was created) |
| 401 | `unauthenticated` | missing, expired, revoked or device-mismatched token |
| 401 | `invalid_credentials`, `locked`, `invalid_code` | sign-in failures |
| 403 | `wrong_role`, `forbidden`, `device_not_allowed` | authenticated but not permitted |
| 404 | `not_found` | unknown uuid or id, or an account not allocated to the caller |
| 422 | `validation_failed`, `gps_required`, `photo_required`, `device_required`, `empty_batch`, `batch_too_large` | the request was understood but rejected |
| 429 | `rate_limited` | see throttling below |
| 503 | `no_form`, maintenance | server-side configuration or maintenance |

**Throttling** — sign-in is limited per IP and per username
(`security.login_max_attempts`, default 5 per 15 minutes). Authenticated calls
are limited to `security.api_rate_per_minute` (default 90) per user per minute.

**Time** — every response carries `server_time` in the server timezone
(`Asia/Kolkata` by default). Timestamps are `YYYY-MM-DD HH:MM:SS`, dates are
`YYYY-MM-DD`. **The server clock is authoritative for the report deadline**; the
device clock is never trusted for lateness.

**Idempotency** — every record the app creates carries a client-generated `uuid`
with a `UNIQUE` index. Re-sending it returns the original row and reports
`"status": "duplicate"` instead of creating a second one. This is what makes the
offline queue safe to retry — a recovery can never be double-counted.

**GPS object** — used by visits, attendance and location pings:

```json
"gps": {
  "latitude": 26.8467, "longitude": 80.9462,
  "accuracy": 12.4, "altitude": 123.0, "speed": 0.0,
  "provider": "gps", "is_mock": false,
  "address": "optional reverse-geocoded string",
  "captured_at": "2026-08-18 10:14:02"
}
```

Validated server-side by `App\Services\Gps`: `0,0` is rejected, accuracy worse
than `gps_max_accuracy_metres` is rejected, and mock locations are rejected unless
`gps_mock_location_allowed` is on.

---

## Endpoint index

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/ping` | — | reachability, server clock, sign-in rules |
| POST | `/auth/login` | — | password sign-in + device binding |
| POST | `/auth/verify-otp` | — | second step when OTP is enabled |
| POST | `/auth/logout` | token | revoke the token |
| POST | `/auth/change-password` | token | change own password |
| GET | `/me` | token | profile, today's totals, targets, deadline |
| GET | `/sync/pull` | token | everything needed to work offline |
| POST | `/sync/push` | token | drain the outbox (batched) |
| POST | `/sync/location` | token | last-known position ping |
| GET | `/accounts` | token | allocated accounts, searchable, paged |
| GET | `/accounts/{id}` | token | one account + its history |
| GET | `/visit-form` | token | the configured visit form |
| POST | `/visits` | token | step 1 — start a visit (GPS required) |
| POST | `/visits/{uuid}/photos` | token | step 2 — one photograph per call |
| POST | `/visits/{uuid}/submit` | token | step 3 — validate and finalise |
| GET | `/visits` | token | own visits |
| POST | `/recoveries` | token | **legacy** — a payment queued by an app older than v1.5.2; no current build sends one |
| POST | `/promises` | token | record a promise to pay |
| GET | `/followups` | token | own follow-ups by status |
| POST | `/followups` | token | schedule a follow-up |
| POST | `/krm-ots` | token | KRM OTS detail |
| POST | `/ckcc` | token | CKCC OD-2 renewal detail |
| GET | `/attendance` | token | today + 30 days of history |
| POST | `/attendance/check-in` | token | start the day |
| POST | `/attendance/check-out` | token | end the day |
| GET | `/deadline` | token | countdown + today's submission |
| POST | `/reports/daily` | token | submit the daily report |
| GET | `/notifications` | token | list |
| POST | `/notifications/{id}/read` | token | mark one read |
| POST | `/notifications/read-all` | token | mark all read |

---

## Public

### `GET /ping`

Called before showing the sign-in screen, so the app can report "server
unreachable" rather than "wrong password", and can learn whether to expect an OTP
step.

```json
{ "success": true, "data": {
  "app": "LRMS", "api_version": "v1",
  "server_time": "2026-08-18 15:42:10", "timezone": "Asia/Kolkata",
  "maintenance": false, "otp_required_for_login": false, "device_binding": true
} }
```

### `POST /auth/login`

```json
{
  "username": "bc001",
  "password": "…",
  "device": {
    "uuid": "b3f1…",
    "model": "Redmi 9A", "manufacturer": "Xiaomi",
    "os_version": "11", "app_version": "1.0.0",
    "fcm_token": null
  }
}
```

`username` accepts the username, email, employee code, or — for a BCA —
their **BCBF code** (`bc_supervisors.bc_code`), which is the identifier field staff
actually know. Matching is case-insensitive, so `BC001` and `bc001` both work.
`device.uuid` is mandatory (it may also be supplied as the `X-Device-Id` header)
and is what the token is bound to.

Success:

```json
{ "success": true, "data": {
  "otp_required": false,
  "token": "…80 hex chars…", "token_type": "Bearer",
  "expires_at": "2026-09-17 15:42:10",
  "user": { "id": 12, "name": "…", "username": "bc001", "mobile": "9…", "role": "bc_supervisor", "must_change_password": false },
  "supervisor": { "id": 4, "bc_code": "BC001", "branch_id": 2, "branch_name": "…", "branch_code": "BR002" },
  "device": { "id": 7, "uuid": "b3f1…" }
} }
```

When `otp_app_login` is enabled the first response is instead
`{"otp_required": true, "user_id": 12, "destination": "9•••••1234", "expires_in": 300}`
and the app must call `/auth/verify-otp`. Tokens live
`security.api_token_ttl_days` days (default 30).

**Device binding.** With `security.device_binding` on, one active device per
supervisor. A second handset is refused with `403 device_not_allowed` and the
message tells the supervisor to ask an BC Supervisor to reset the binding
(Staff ▸ BCA ▸ Reset device). A device registered to another user, or
blocked by a BC Supervisor, is refused the same way.

### `POST /auth/verify-otp`

```json
{ "user_id": 12, "code": "483920" }
```

Returns the same payload as a successful `/auth/login`. Codes expire after
`security.otp_ttl_seconds` (300) and are limited to 10 attempts per 10 minutes.

---

## Session

### `POST /auth/logout`

No body. Revokes the presented token only — other devices are unaffected.

### `POST /auth/change-password`

```json
{ "current_password": "…", "password": "…", "password_confirmation": "…" }
```

Clears `must_change_password`. The app must call this before field work when
`/me` reports that flag.

### `GET /me`

Profile plus everything the home screen shows: `today` (visits, recovery,
promises, allocated and pending account counts), `attendance` (today's row or
`null`), `targets` (the periods covering today), `deadline` (see below) and
`unread_notifications`.

---

## Offline synchronisation

### `GET /sync/pull?since=<ISO8601>`

Omit `since` for a full refresh; pass the previous response's `synced_at` for a
delta. Returns:

| Key | Contents |
| --- | --- |
| `synced_at` | pass back as `since` next time |
| `accounts` | allocated, active accounts (max 3000, worst overdue first) |
| `removed_account_ids` | accounts reallocated away since `since` — **delete these locally** |
| `visit_form` | id, name, version, and `fields` (see below) |
| `rules` | the validations the app must also enforce locally |
| `deadline` | server-authoritative countdown |
| `notifications` | latest 40 |

`rules` carries `min_visit_photos`, `require_borrower_signature`,
`gps_max_accuracy_metres`, `gps_mock_location_allowed`, `max_backdate_days` (7)
and the enumerations the UI needs: `payment_modes`, `visit_statuses`,
`photo_types` (`customer`, `house`, `shop`, `land`, `document`, `other`) and
`recovery_possibility` (`high`, `medium`, `low`, `nil`).

Each entry in `visit_form.fields`:

```json
{
  "key": "promise_amount", "label": "Promise amount", "type": "decimal",
  "required": true, "options": ["…"], "placeholder": null, "help": null,
  "min": null, "max": null, "order": 14,
  "condition": { "field": "customer_available", "operator": "equals", "value": "yes" }
}
```

`condition` is `null` for an unconditional field. The app renders these
dynamically and evaluates `condition` locally for visibility, so an
BC Supervisor can change the form without an app release. Field types:
`section`, `text`, `textarea`, `number`, `decimal`, `date`, `time`, `dropdown`,
`radio`, `checkbox`, `yes_no`, `photo`, `signature`, `gps`, `remarks`.

Condition operators, which the app must evaluate the same way the server does
(`App\Services\Forms::isVisible` and its Kotlin mirror `FormLogic.isVisible`):

| Operator | True when |
| --- | --- |
| `equals` / `not_equals` | the parent's answer matches, case-insensitively |
| `in` | the parent's answer is one of a comma separated list |
| `contains` | the expected choice is one of the parent's **ticked** values — for a `checkbox` parent, whose answer is a comma joined list. Compared per item, so `Other` does not match `Other Land Record` |
| `filled` / `empty` | the parent has / has not been answered |

**Work-stream forms.** `GET /visit-form?visit_type=krm_ots` and
`?visit_type=ckcc_od2` return the two Field Visit Verification Report forms
(42 and 46 fields). They are deliberately separate documents: the KRM OTS form
carries no renewal fields and the CKCC form carries no settlement fields. Both
end with a `declaration_accepted` field — the server **refuses** the submission
with `422` unless it is answered `Yes`, so the app should require it before
allowing submit.

### `POST /sync/push`

The outbox drain. Up to **200 items** per batch; each item is processed
independently, so one bad row never blocks the rest.

```json
{
  "batch_uuid": "…", "app_version": "1.0.0", "network_type": "4g",
  "items": [
    { "type": "visit",         "uuid": "…", "payload": { } },
    { "type": "recovery",      "uuid": "…", "payload": { } },
    { "type": "promise",       "uuid": "…", "payload": { } },
    { "type": "followup",      "uuid": "…", "payload": { } },
    { "type": "attendance_in", "uuid": "…", "payload": { } },
    { "type": "attendance_out","uuid": "…", "payload": { } },
    { "type": "daily_report",  "uuid": "…", "payload": { } }
  ]
}
```

A `visit` item is self-contained — start, form, photographs (base64) and any
recovery/promise/followup in one payload — because the device may not get a second
chance to talk to the server.

Response:

```json
{ "success": true, "data": {
  "batch_uuid": "…", "received": 3, "accepted": 2, "duplicates": 1, "failed": 0,
  "results": [
    { "index": 0, "type": "visit", "uuid": "…", "status": "accepted", "id": 812, "photos_stored": 2, "is_late": false,
      "recovery_id": 41, "promise_id": null, "followup_id": null },
    { "index": 1, "type": "recovery", "uuid": "…", "status": "duplicate", "id": 40 },
    { "index": 2, "type": "promise", "uuid": "…", "status": "failed",
      "message": "The promise amount must be greater than zero.", "retryable": false }
  ],
  "deadline": { }
} }
```

The app deletes outbox rows whose `status` is `accepted` or `duplicate`, shows
`message` for `failed`, and **stops retrying when `retryable` is false** —
validation problems will not fix themselves. Replaying an entire batch is safe:
the `batch_uuid` is reused rather than counted twice.

### `POST /sync/location`

```json
{ "gps": { "latitude": 26.8467, "longitude": 80.9462, "accuracy": 14.0, "address": "…" } }
```

Updates the device's last-known position for the monitoring screen. This is a
periodic ping, **not continuous tracking** — while the device is offline or
location permission is off, nothing is recorded and the web UI shows the age of
the last point.

---

## Accounts

### `GET /accounts?search=&filter=&page=&per_page=`

Only accounts currently allocated to the caller. `search` matches account number,
borrower name, father's name, mobile or village. `filter` is one of `pending`
(never visited), `visited`, `ptp`, `krm_ots`, `ckcc_od2`. Worst overdue first.
Returns `accounts` and `pagination { page, per_page, total, last_page }`.

### `GET /accounts/{id}`

The account plus its last 20 `visits`, `recoveries`, `promises` and `followups`.
An account not allocated to the caller returns 404 — the API does not reveal that
it exists.

---

## The visit flow (TYPE A)

Three calls, so a device on a bad connection can make progress and resume. Each
step is idempotent.

### `GET /visit-form?visit_type=customer|krm_ots|ckcc_od2`

The active form for that visit type. `503 no_form` when none is configured.

### `POST /visits` — step 1

```json
{
  "uuid": "client-generated-uuid",
  "loan_account_id": 431,
  "visit_type": "customer",
  "visit_date": "2026-08-18",
  "started_at": "2026-08-18 10:12:44",
  "client_created_at": "2026-08-18 10:12:44",
  "gps": { "latitude": 26.8467, "longitude": 80.9462, "accuracy": 11.0, "is_mock": false }
}
```

**GPS is mandatory** — a visit without a validated point has no evidentiary
value, so this returns `422` rather than storing an unverifiable record.
`visit_date` may be backdated at most 7 days (queued offline work) and never
into the future. Returns the visit with `status: "draft"`, `created` (false on
replay) and `min_photos`.

### `POST /visits/{uuid}/photos` — step 2

One photograph per call, so a flaky connection retries a single image rather than
the whole visit. Either `multipart/form-data` with a `photo` file, or JSON with
`data` as a base64 string. Optional: `photo_type`, `caption`, `latitude`,
`longitude`, `accuracy`, `address`, `captured_at`.

The server re-encodes the image (stripping EXIF), stamps a watermark with the
supervisor's name, timestamp and coordinates, and hashes it — an identical
re-upload returns `"duplicate": true` with the original `photo_id` rather than a
second copy. Response also carries the running `photo_count`.

### `POST /visits/{uuid}/submit` — step 3

```json
{
  "form": { "customer_available": "yes", "occupation": "Farmer", "remarks": "…" },
  "visit_status": "customer_met",
  "recovery_possibility": "medium",
  "borrower_signature": "<base64 png>",
  "supervisor_signature": "<base64 png>",
  "recovery": { "…": "legacy, only from an app older than v1.5.2 — see /recoveries below" },
  "promise":  { "uuid": "…", "promise_amount": 10000, "promise_date": "2026-09-01", "remarks": "…" },
  "followup": { "uuid": "…", "followup_date": "2026-08-25", "action": "revisit", "notes": "…" },
  "krm_ots":  { },
  "ckcc_od2": { }
}
```

Refused with `422` unless at least `min_visit_photos` photographs and one valid
GPS point exist, all required form fields are answered, and — when
`require_borrower_signature` is on — a borrower signature is present. `photo` and
`gps` form fields are satisfied by the real uploads rather than by typed text.

On success the visit is `submitted`, `is_late` is set from the **server**
deadline, the nested recovery/promise/followup are created, the account roll-ups
(`visit_count`, `total_recovered`, `recovery_status`, `last_visit_at`) are
refreshed and the whole thing is audited. Re-submitting returns
`already_submitted: true` with no side effects.

### `GET /visits?date=YYYY-MM-DD`

Own visits (latest 200) with the borrower's identity, `status`, `is_late`,
`photo_count` and `gps_verified`.

---

## Money and follow-up

These are the online equivalents of the queued item types; identical service code
runs either way.

| Endpoint | Required payload | Notes |
| --- | --- | --- |
| `POST /recoveries` | `loan_account_id`, `amount`, `recovery_date`, `payment_mode` | **Legacy.** The app no longer takes or records a payment — the work is the visit and the borrower pays the bank. Still accepted so an older install can flush what it queued offline; an unrecognised `payment_mode` is stored verbatim rather than relabelled. `receipt_number` must be unique when supplied; settles any pending promise; optional `visit_uuid` links it to a visit |
| `POST /promises` | `loan_account_id`, `promise_amount`, `promise_date` | amount must be > 0; optional `followup_date` schedules the reminder; a later recovery settles it automatically, and `bin/cron.php` marks overdue promises broken |
| `POST /followups` | `loan_account_id`, `followup_date`, `action` | `action` ∈ `call`, `visit`, `notice`, `legal`, `other` (defaults to `visit`) |
| `POST /krm-ots` | `loan_account_id` + `sanctioned_amount`, `ots_amount`, `paid_amount`, `ots_status`, `promise_date`, `remarks` | separate from the visit report and from CKCC |
| `POST /ckcc` | `loan_account_id` + `limit_amount`, `outstanding`, `overdue`, `renewal_status`, `documents_status`, `customer_availability`, `renewed_on`, `remarks` | separate from the visit report and from KRM OTS |

All five accept a client `uuid` and are idempotent on it. All five refuse an
account not allocated to the caller.

---

## Attendance

| Endpoint | Payload |
| --- | --- |
| `POST /attendance/check-in` | `uuid`, `check_in_at`, `gps`, `selfie` (base64 or multipart), `remarks` |
| `POST /attendance/check-out` | `uuid`, `check_out_at`, `gps`, `remarks` |
| `GET /attendance` | — returns `attendance` (today or `null`) and 30 days of `history` |

Working minutes are computed server-side from the stored timestamps.

---

## Deadline and the daily report

### `GET /deadline`

The single source of truth for the countdown:

```json
{ "success": true, "data": {
  "deadline": {
    "report_date": "2026-08-18", "is_working_day": true,
    "working_days": ["mon","tue","wed","thu","fri","sat"],
    "deadline_time": "18:00", "deadline_at": "2026-08-18 18:00:00",
    "server_time": "2026-08-18 15:42:10", "server_timezone": "Asia/Kolkata",
    "seconds_remaining": 8270, "has_passed": false, "locked": false,
    "late_requests_allowed": true, "reminder_minutes": [60, 30, 10]
  },
  "counts": { "visits": 6, "recovery": 24500.0, "promises": 2 },
  "submission": { "id": 91, "status": "pending", "submitted_at": null, "is_late": false,
                  "deadline_at": "2026-08-18 18:00:00", "late_reason": null, "approval_remarks": null }
} }
```

The app seeds its countdown from `seconds_remaining` and then advances it with
`SystemClock.elapsedRealtime()`, so changing the device clock buys no extra
minutes.

### `POST /reports/daily`

```json
{ "report_date": "2026-08-18", "summary": "…", "late_reason": "" }
```

Counts are taken from the database, not from the request. Response `status` is:

- `submitted` — before the deadline;
- `late_pending` — after it; queued for BC Supervisor approval, and
  `late_reason` should be supplied;
- `late_approved` / `submitted` — already submitted (replay, no side effects);
- `locked` — after the deadline while `allow_late_submission_requests` is off.

---

## Notifications

`GET /notifications` returns `unread` and the list (`id`, `title`, `body`,
`type`, `link`, `is_read`, `created_at`). `POST /notifications/{id}/read` and
`POST /notifications/read-all` mark them read; a notification belonging to
another user cannot be touched.

---

## Verifying the API

[`tests/api-smoke.php`](../tests/api-smoke.php) exercises this contract
end-to-end against a real server and database — 101 assertions across eight
groups: public endpoints, sign-in and device binding, profile and `sync/pull`,
the three-step visit flow, attendance/deadline/daily report, `sync/push`
(including replaying a batch), notifications + location ping + sign-out, and the
audit trail the activity should have produced.

```bash
php database/migrate.php --fresh --demo
php tests/api-smoke.php
```
