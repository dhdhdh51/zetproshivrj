# DocuPilot AI

**Create professional business documents with AI, in minutes.**

DocuPilot AI is a complete, self-hosted SaaS application for freelancers, agencies,
consultants and small businesses. Describe a job in one plain sentence and DocuPilot
drafts a client-ready quotation, invoice, proposal, estimate or purchase order —
then lets you edit every number, export a PDF, share a secure link or email it to the client.

Built with plain PHP 8.2+, MySQL 8 and PDO so it runs on ordinary cPanel / shared hosting.

---

## Table of contents

- [Feature overview](#feature-overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Database setup](#database-setup)
- [Configuration](#configuration)
- [Google Login setup](#google-login-setup)
- [OpenRouter (AI) setup](#openrouter-ai-setup)
- [SMTP setup](#smtp-setup)
- [PayU setup](#payu-setup)
- [Default administrator](#default-administrator)
- [Project structure](#project-structure)
- [How the money maths works](#how-the-money-maths-works)
- [Security](#security)
- [Testing](#testing)
- [cPanel deployment](#cpanel-deployment)

---

## Feature overview

**Documents**
- Five document types with their own numbering series: Quotation (`QT-2026-0001`),
  Invoice (`INV-…`), Proposal (`PROP-…`), Estimate (`EST-…`), Purchase Order (`PO-…`)
- Guided creation wizard: type → client → requirement → items & pricing → review
- Line items with description, quantity, unit, rate and tax percentage
- Discounts (fixed or percentage), notes, terms, currency, issue date, valid-until date
- Statuses: Draft, Final, Sent — plus duplicate, delete, search, type/status/client filters and pagination
- Three professional PDF templates: **Modern**, **Corporate**, **Minimal**
- Preview in the browser, generate/regenerate PDF, download PDF
- Secure public share links (`/documents/share/{token}`) that can be enabled and disabled
- Send to client by email with the freshest PDF attached, and a delivery log

**AI (via OpenRouter)**
- One text box: *“What do you want to create?”* — no prompt engineering
- Structured JSON generation for title, summary, line items, notes and terms
- Writing tools in the editor: Generate, Improve Writing, Rewrite, Make Professional,
  Make Shorter, Expand, Fix Grammar, Generate Client Email, Generate Terms & Conditions
- Model, temperature, max tokens and on/off switch are configurable in the admin panel
- All requests happen server-side; the API key is never exposed to the browser
- The AI is explicitly prevented from inventing GSTIN, tax numbers, bank details,
  addresses, phone numbers or prices — those always come from your own profile and inputs

**Accounts & business data**
- Registration, login, logout, email verification, forgot/reset password, remember me, Google Login
- Business profile: name, logo, contact details, full address, GSTIN, tax number,
  bank name/account/IFSC, default terms, default notes, default currency, default template, signature name
- Client book: add, edit, delete, search, view, quick-add from inside the document wizard

**Billing**
- Plans: Free (₹0), Pro (₹299/mo), Business (₹799/mo) — all editable in the admin panel
- Monthly limits for documents and AI generations, enforced server-side
- PayU hosted checkout with signature + API verification before a plan is activated
- Usage meters (`AI Generations: 32 / 100`), billing history, subscription history

**Admin panel**
- Dashboard: users, documents, AI generations, payments, revenue, active subscriptions, system status
- Users: search, filter, view detail, activate/deactivate, promote/demote, assign or cancel a plan
- Documents: search/filter every document, preview, delete
- AI settings with a real *Test AI Connection* call
- Email settings with *Send Test Email*
- PayU settings (mode, key, salt, callback URLs) with credentials masked
- Plans management, payments list with re-verification, template activation/default, system settings

---

## Requirements

| Component | Minimum |
|---|---|
| PHP | 8.2 (8.3 / 8.4 also supported) |
| MySQL | 8.0 (MariaDB 10.5+ works too) |
| PHP extensions | `pdo_mysql`, `mbstring`, `curl`, `json`, `openssl`, `fileinfo`, `gd` (recommended, for WEBP logos) |
| Composer | 2.x (to install Dompdf + PHPMailer) |
| Web server | Apache with `mod_rewrite` (nginx works with an equivalent rewrite) |

---

## Installation

```bash
git clone <your-repo-url> docupilot
cd docupilot

# 1. PHP dependencies (Dompdf for PDFs, PHPMailer for SMTP)
composer install --no-dev --optimize-autoloader

# 2. Configuration
cp config/config.example.php config/config.php
#    …then edit config/config.php (see “Configuration” below)

# 3. Database
php database/migrate.php          # creates every table and seeds plans/templates/settings/admin

# 4. Writable storage
chmod -R 775 storage
```

Point your web server's document root at **`public/`**. If you cannot change the document
root (typical on shared hosting), upload the whole project into `public_html/` — the
root `.htaccess` forwards all traffic into `public/` and blocks direct access to
`app/`, `config/`, `database/`, `resources/`, `routes/`, `storage/` and `vendor/`.

Local development:

```bash
php -S localhost:8000 -t public public/index.php
```

---

## Database setup

Two equivalent options:

**A. CLI (recommended)**

```bash
php database/migrate.php            # install + run any pending migrations
php database/migrate.php --status   # show what is installed
php database/migrate.php --fresh    # DROP all app tables and reinstall (destructive)
```

**B. phpMyAdmin**

1. Create a database (e.g. `docupilot`) with collation `utf8mb4_unicode_ci`
2. Create a user, grant it all privileges on that database
3. Import `database/schema.sql`

The schema creates all 17 tables — `users`, `business_profiles`, `clients`, `documents`,
`document_items`, `document_templates`, `ai_generations`, `ai_usage`, `plans`,
`subscriptions`, `payments`, `share_links`, `email_logs`, `settings`, `password_resets`,
`email_verifications`, `activity_logs` — with foreign keys, indexes, unique constraints
and timestamps, then seeds the three plans, the three templates, default settings and
the administrator account.

---

## Configuration

Everything lives in **`config/config.php`** (a plain PHP file — no `.env` needed):

```php
return [
    'app' => [
        'name'  => 'DocuPilot AI',
        'url'   => 'https://yourdomain.com',   // used in emails, PDFs and share links
        'debug' => false,                      // keep false in production
        'timezone' => 'Asia/Kolkata',
    ],
    'database' => [
        'host' => 'localhost',
        'database' => 'docupilot',
        'username' => 'database_user',
        'password' => 'database_password',
    ],
    'google'     => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
    'openrouter' => ['api_key' => '', 'model' => 'openai/gpt-4o-mini', 'base_url' => 'https://openrouter.ai/api/v1'],
    'smtp'       => ['host' => '', 'port' => 587, 'username' => '', 'password' => '', 'encryption' => 'tls', 'from_email' => '', 'from_name' => 'DocuPilot AI'],
    'payu'       => ['mode' => 'test', 'merchant_key' => '', 'merchant_salt' => '', 'base_url' => ''],
];
```

**Config vs. admin panel.** `config/config.php` holds the bootstrap values (database,
Google, app URL). AI, email, PayU and system settings can also be managed from the admin
panel — those are stored in the `settings` table and **override** the config file at runtime.
Anything left blank in the database falls back to the config value.

For local development you can create `config/config.local.php` (git-ignored) returning a
partial array; it is deep-merged over `config.php`.

---

## Google Login setup

1. Open <https://console.cloud.google.com/apis/credentials> and create an **OAuth client ID**
   of type *Web application*.
2. Authorised redirect URI: `https://yourdomain.com/auth/google/callback`
3. Copy the client ID and secret into the `google` section of `config/config.php`
   (set `redirect_uri` to the same URL).
4. The “Continue with Google” button appears on the login and registration pages
   automatically once both values are present.

Accounts created through Google are email-verified immediately and can add a password later
from **Account settings**.

---

## OpenRouter (AI) setup

1. Create a key at <https://openrouter.ai/keys>.
2. Either paste it into `config/config.php` → `openrouter.api_key`, or (easier)
   sign in as an administrator and go to **Admin → AI settings**.
3. Choose a model ID, e.g. `openai/gpt-4o-mini`, `anthropic/claude-3.5-sonnet`,
   `google/gemini-flash-1.5`, `meta-llama/llama-3.1-70b-instruct`.
4. Click **Test AI connection** — it performs a real OpenRouter request and reports the model
   and latency, or the exact error.
5. Optional: adjust temperature (0.3–0.5 suits documents) and max tokens, or flip
   **AI features enabled** off to hide all AI buttons.

If no key is configured the app stays fully usable — the AI buttons are hidden and the
document wizard explains why.

---

## SMTP setup

Configure in **Admin → Email settings** (or the `smtp` section of the config file):

| Field | Example |
|---|---|
| Host | `smtp.hostinger.com` |
| Port | `587` (TLS) or `465` (SSL) |
| Username / Password | your mailbox credentials |
| Encryption | `tls`, `ssl` or `none` |
| From email / name | `documents@yourdomain.com`, `Your Studio` |

Use **Send test email** to verify. Every attempt (success or failure) is written to
`email_logs` and shown in the admin email log and on each document.

SMTP powers email verification, password resets and document delivery. Until it is
configured, new accounts are auto-verified so nobody is locked out, and the
“Send to client” page warns that sending will fail.

---

## PayU setup

1. Get your **merchant key** and **salt** from the PayU dashboard.
2. Enter them in **Admin → PayU settings** and pick `test` or `live` mode
   (the payment URL switches to `test.payu.in` / `secure.payu.in` automatically).
3. Register these callback URLs in PayU (they are shown with copy buttons on that page):
   - Success: `https://yourdomain.com/billing/payu/success`
   - Failure: `https://yourdomain.com/billing/payu/failure`

**Verification flow.** A `pending` payment row is created before redirecting. On callback the
response hash is recomputed in reverse order and compared with `hash_equals`, the posted
amount must match the stored amount, and PayU's `verify_payment` API is queried
server-to-server. Only then is the subscription activated and the plan limits raised.
Administrators can re-verify any payment from **Admin → Payments**.

---

## Default administrator

```
Email:    admin@docupilot.ai
Password: Admin@12345
```

**Change this password immediately after your first login** (Account settings → Password).

---

## Project structure

```
app/
  Controllers/        Web + JSON controllers (Admin/ subfolder for the admin panel)
  Core/               Router, Database (PDO), Auth, Session, Csrf, Validator, View, Settings, …
  Helpers/            functions.php (url, money, icon, document_types, …)
  Middleware/         auth, guest, admin, verified, maintenance
  Models/             One class per table, plain-array results
  Services/           OpenRouterService, PayUService, MailService, PDFService,
                      DocumentService, UsageService, UploadService
  Validators/         (reserved for form-specific rule sets)
config/               config.php, config.example.php (+ .htaccess deny)
database/             schema.sql, migrate.php, migrations/
public/               index.php front controller, assets/css, assets/js
resources/
  views/              Layouts, pages, partials
  emails/             HTML email templates
  templates/          The three PDF document templates
routes/               web.php, api.php, admin.php
storage/              uploads/ (logos), generated/ (PDFs), logs/
tests/                smoke.php, http-smoke.php, run-smoke.sh
```

---

## How the money maths works

All amounts are recalculated on the server on every save — the browser's numbers are
only a live preview.

```
line_subtotal  = quantity × rate
subtotal       = Σ line_subtotal
discount_total = percent ? subtotal × value / 100 : min(value, subtotal)
taxable(line)  = line_subtotal × (subtotal − discount_total) / subtotal   // discount spread pro-rata
line_tax       = taxable(line) × tax_percent / 100
total          = subtotal − discount_total + Σ line_tax
```

Quantities, rates and tax percentages are clamped (no negatives, tax ≤ 100%, discount ≤ subtotal),
thousands separators are parsed, and rows without a description are dropped.

---

## Security

- PDO prepared statements everywhere (no string-interpolated SQL)
- CSRF token required on every non-GET request (PayU callbacks verify a PayU signature instead)
- Output escaped with `e()`; escaping is covered by tests
- `password_hash()` / `password_verify()` with automatic rehashing
- HTTP-only, SameSite=Lax session cookies, idle expiry, ID regeneration on login
- Remember-me tokens stored as SHA-256 hashes, compared with `hash_equals`
- Ownership checks on every document, client and PDF (403 for other users' data)
- Admin-only middleware for `/admin/*`; passwords never rendered in the admin UI
- Login, registration, password-reset and AI requests are rate limited
- Uploads validated by real MIME type + `getimagesize()`, renamed to random hex, stored
  outside the web root and streamed through PHP
- Share tokens are 24 random bytes from `random_bytes()`
- Friendly 403 / 404 / 419 / 429 / 500 pages; stack traces only when `debug` is on

---

## Testing

Manual test plans live in [TESTING.md](TESTING.md). Two automated suites are included:

```bash
# Everything at once: fresh DB, dev server, both suites, cleanup
./tests/run-smoke.sh

# Or individually
php tests/smoke.php                              # services, models, calculations, PDFs, PayU hashing
php tests/http-smoke.php http://127.0.0.1:8000   # real HTTP: routing, CSRF, documents, admin, sharing
```

`tests/run-smoke.sh` accepts `DP_DB_NAME`, `DP_DB_USER`, `DP_DB_PASS`, `DP_DB_HOST`,
`DP_DB_PORT`, `DP_DB_SOCKET` and `DP_PORT` environment variables. It writes a temporary
`config/config.local.php`, installs a **fresh** schema (destructive — use a test database)
and removes the temporary config afterwards.

---

## cPanel deployment

See [DEPLOYMENT.md](DEPLOYMENT.md) for the full 14-step walkthrough
(database, upload, PHP version, config, schema import, Composer, permissions, SSL,
Google, OpenRouter, SMTP, PayU, verification).

---

## Licence

Proprietary — © DocuPilot AI. All rights reserved.
