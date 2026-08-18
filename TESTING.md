# Testing DocuPilot AI

Two layers: **automated smoke suites** (run them first — they cover the risky logic) and
**manual flows** that mirror how a real user works.

---

## 1. Automated suites

```bash
./tests/run-smoke.sh
```

That single command creates a fresh schema, starts a PHP dev server, runs both suites and
cleans up. Override the database with environment variables:

```bash
DP_DB_NAME=docupilot_test DP_DB_USER=root DP_DB_PASS=secret DP_PORT=8123 ./tests/run-smoke.sh
```

> `run-smoke.sh` installs a **fresh** schema (`--fresh` drops the app tables). Always point it
> at a throwaway database, never production.

You can also run each suite by hand:

| Suite | Command | What it proves |
|---|---|---|
| Functional | `php tests/smoke.php` | Models and services against a real database |
| HTTP | `php tests/http-smoke.php http://127.0.0.1:8000` | Routing, middleware, CSRF, sessions, real page output |

### What the functional suite covers (172 checks)

- Environment: DB connection, Dompdf, PHPMailer, writable storage, seeded plans/templates/admin
- Auth: hashing, wrong/right password, remember-token hashing, verification and reset tokens
  (single use, expiry, stored as SHA-256), verification email dispatch
- Business profile: save, completeness, GSTIN stored verbatim, one profile per user
- Logo upload: accepted image, generated filename, stored outside the web root, data-URI for PDFs,
  **a PHP file renamed `.png` is rejected**
- Clients: create, update, search, search never leaks another user's clients
- Calculations: subtotal, fixed and percentage discounts, pro-rata tax, clamping of negative
  values / tax > 100% / discount > subtotal, parsing of `1,500.50`, rows without a description dropped
- Documents: creation, per-type numbering series (`QT/INV/PROP/EST/PO-YYYY-0001`), increment,
  persisted item line totals, update recalculation, number preserved across edits, duplicate,
  filters (type/status/search), pagination
- Ownership: 403 for another user's document/client, 404 for unknown ids, empty lists for other users
- PDFs: all three templates render, contain the number, total, GSTIN and signature, produce a
  valid `%PDF-` file > 8 KB, stored PDFs are discoverable and invalidated on edit,
  the bundled font contains the rupee sign
- Share links: 48-hex token, resolve by token, unknown token fails, re-enable reuses the row,
  view counter, disable
- Email: document delivery with attachment logged as `sent`, invalid recipient logged as `failed`,
  template renders, HTML is escaped
- Plans: Free defaults, email/templates gating, limits enforced at 5, Pro upgrade raises limits,
  usage summary and percentages, renewal date, cancellation returns to Free
- PayU: request hash matches the documented sequence, valid response hash accepted,
  tampered amount rejected, missing hash rejected, failed status never treated as paid,
  pending payment row, revenue statistics
- Settings/validation/logging: DB override + config fallback, validator rules including
  `unique` with ignore, activity log, admin listings and statistics
- Deletion: document delete cascades items, client delete keeps documents and NULLs the link

### What the HTTP suite covers (165 checks)

- Public pages (landing, pricing, privacy, terms, contact) plus `robots.txt`, `sitemap.xml`,
  `/health`, CSS asset, SEO meta/OG/canonical/JSON-LD
- 404 for unknown routes and invalid share tokens
- Guests redirected from `/dashboard` and `/admin`; **POST without a CSRF token returns 419**
- Registration → onboarding → business profile → dashboard
- Clients: create, list, search (hit and empty state), JSON lookup
- Documents: wizard renders all types/templates/default terms, create, server-side totals
  (`60,000 − 5% + 18% tax = 67,260.00`), preview with GSTIN and bank details, edit,
  recalculation after edit, PDF generate/download (`application/pdf`, `%PDF-`, attachment header),
  share enable → public page → public PDF → disable returns 403, duplicate, list filters
- AI endpoints return actionable JSON when no key is configured; `/api/documents/calculate` maths
- Billing: pricing highlights the current plan, billing page usage + empty history
- Isolation: a second account gets 403 on view/edit/download of the first account's document,
  and 403 on `/admin`
- Admin: sign-in, dashboard counters, every admin page, user search, **no password hashes anywhere**,
  deactivate/reactivate, plan assignment form, AI settings save + key masking + Test AI,
  email settings save, PayU masking + callback URLs, template default switch, system settings persistence
- Maintenance mode: visitors get 503, administrators keep working, then it is switched back off

---

## 2. Manual test flows

### Authentication
`Register → Email verification → Login → Logout → Password reset`

1. Register with a new email. With SMTP configured you get a confirmation mail; without it the
   account is auto-verified (by design, so nobody is locked out).
2. Click the confirmation link → “your email address is confirmed”. Re-using the link fails.
3. Sign out, sign in again. Tick **Keep me signed in**, close the browser, reopen → still signed in.
4. *Forgot password* → open the emailed link → set a new password → the old one stops working.
5. Enter a wrong password 5 times → the 6th attempt is blocked with a 429 page.
6. If Google Login is configured: **Continue with Google** creates or links the account.

### Business profile
`Create business profile → Upload logo → Edit profile`

1. Fill business name, address, GSTIN, bank details, default terms, currency, template.
2. Upload a PNG/JPG/WEBP logo → it appears immediately; try a `.txt` renamed to `.png` → rejected.
3. Re-open the page → all values persisted. Remove the logo → placeholder returns.
4. Create a document → GSTIN and bank details appear on the PDF exactly as typed.

### Clients
`Add → Edit → Delete → Select client`

1. Add a client; search for it by name, company, email and phone.
2. Edit and confirm the change; open the client page and see their documents.
3. Inside the document wizard pick the client from *Saved clients* → the client fields auto-fill.
4. Use **New client** in the wizard (modal, no page reload) → it is selected immediately.
5. Delete a client → their documents remain, with the link cleared.

### AI
`Configure OpenRouter → Select model → Generate document → Edit → Save`

1. **Admin → AI settings** → add the key, choose the model, **Test AI connection**.
2. In the wizard type: *“Create a professional quotation for ABC Technologies for website
   development worth ₹40,000 including 3 months maintenance.”* → **Generate with AI**.
3. Confirm the title, summary, line items, notes and terms fill in and the totals add up to
   the amount you mentioned. Nothing invented in the GSTIN/bank/address fields.
4. Use **Improve / Rewrite / Make professional / Make shorter / Expand / Fix grammar** on the
   summary, notes and terms. Use **Generate terms with AI**.
5. On the send page use **Draft with AI** for the covering email.
6. Save and confirm the AI counter increased (dashboard and Billing pages).
7. Exhaust the AI limit on a Free account → further AI calls are refused with an upgrade prompt.

### Documents
`Create → Edit → Duplicate → PDF → Download → Share → Email → Delete`

1. Create one of each type and check the numbering series and increments.
2. Add/remove line items, change quantity, rate, tax and discount type → totals update live and
   match after saving (the server is authoritative).
3. Switch template between Modern, Corporate and Minimal → preview and PDF change.
4. **Generate PDF**, **Download PDF** → the file opens, has your logo, items, totals, notes,
   terms and a signature block.
5. **Create public link** → open it in a private window (no login) → view and download.
   Disable the link → the page returns 403.
6. **Send to client** (Pro/Business) → the email arrives with the latest PDF attached, the
   document status becomes *Sent*, and the send is listed in the email history.
   Break the SMTP password on purpose → sending reports the real error and does **not** claim success.
7. Duplicate a document → a new draft with its own number and the same items.
8. Filter the list by type, status and client; search by number and title; page through results.
9. Delete a document → gone from the list, and its stored PDF removed.

### Payment
`Select Pro → PayU test payment → Verify payment → Activate subscription`

1. **Pricing → Upgrade to Pro** → you are handed to PayU (test mode).
2. Complete a test payment → back on Billing you see *Payment confirmed*, plan **Pro**,
   limits 100/100, email delivery unlocked, all templates unlocked.
3. Cancel/fail a payment → the payment is recorded as `failed` with the reason and the plan
   does not change.
4. **Admin → Payments** → the transaction is listed; **Re-verify with PayU** re-checks the status.

### Admin
`Login → Users → AI settings → Test AI → Email settings → Test Email → PayU settings → Plans → Payments`

1. Sign in as an administrator → you land on `/admin`.
2. Dashboard shows total users, documents, AI generations, payments/revenue,
   active subscriptions and System status.
3. **Users** → search, open a user, deactivate them (they can no longer sign in), reactivate,
   assign a plan for N months, cancel a subscription. Passwords are never displayed.
4. **AI settings** → save, **Test AI connection**. **Email settings** → save, **Send test email**.
5. **PayU settings** → key/salt masked after saving; callback URLs copyable.
6. **Plans** → change the Pro price and limits, save, and confirm the pricing page reflects it.
7. **Templates** → deactivate one (it disappears from the pickers), set another as default.
8. **System settings** → change the site name, upload a site logo, turn registration off
   (the register page shows “Registration is closed”), turn maintenance mode on
   (visitors see the maintenance page, administrators keep working), then turn both back.

### Security
`Verify one user cannot access another user's documents`

1. Sign in as user A, create a document, note its id (e.g. `/documents/12`).
2. Sign in as user B and request `/documents/12`, `/documents/12/edit`, `/documents/12/download`,
   `/documents/12/send` → every one returns **403**.
3. Request `/admin` as user B → **403**.
4. Submit any form after deleting the `_token` field → **419**.
5. Request a share link with a random token → **404**; a disabled link → **403**.
6. Try uploading a `.php` file as a logo → rejected.
7. Hit `/config/config.php`, `/storage/logs/`, `/app/Core/Auth.php` directly → **403**
   (root `.htaccess`).

---

## 3. Responsive checks

Test at 390×844 (phone), 768×1024 (tablet) and 1440×900 (desktop):

- Dashboard cards stack; the sidebar collapses behind the hamburger with a backdrop
- Document wizard: type cards, item rows and the totals box remain usable and readable
- Document editor and preview iframe fit the viewport
- Tables scroll horizontally instead of breaking the layout
- Admin dashboard and settings forms stack cleanly

---

## 4. Error pages

| URL / action | Expected |
|---|---|
| `/no-such-page` | 404 “We can't find that page” |
| Another user's document | 403 “You don't have access to this” |
| Form POST without a CSRF token | 419 “Your session expired” |
| 6+ failed logins | 429 “Slow down for a moment” |
| Forced exception with `debug=false` | 500 “Something went wrong on our side” (no stack trace) |
| Maintenance mode on, as a visitor | 503 “We'll be right back” |
