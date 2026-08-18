# Deploying DocuPilot AI on cPanel / shared hosting

This walkthrough assumes a standard cPanel account with PHP 8.2+ and MySQL 8.
Total time: about 20 minutes.

---

## 1. Create the MySQL database

cPanel → **MySQL® Databases**

1. Under *Create New Database* enter `docupilot` and click **Create Database**.
   cPanel prefixes it, so the real name becomes something like `cpaneluser_docupilot`.
2. Note the full name — you need it in step 5.

## 2. Create the database user

Still in **MySQL® Databases**:

1. Under *MySQL Users → Add New User* create a user (e.g. `docupilot_app`) with a
   strong generated password. Save the password somewhere safe.
2. Under *Add User To Database* select the user and the database, click **Add**.
3. Tick **ALL PRIVILEGES** and click **Make Changes**.

## 3. Upload the project files

**Option A — Git (if Git Version Control is available)**

```
cPanel → Git Version Control → Create
Repository URL:  https://github.com/<you>/<repo>.git
Repository Path: /home/<cpaneluser>/docupilot
```

**Option B — Zip upload**

1. Zip the project locally (include `vendor/` if you cannot run Composer on the server).
2. cPanel → **File Manager** → upload the zip into `public_html/` → **Extract**.

**Which document root?**

- *Preferred:* point the domain's document root at `.../docupilot/public`
  (cPanel → **Domains** → Edit the document root). Nothing else is web-reachable.
- *If you cannot change it:* extract everything into `public_html/`. The bundled root
  `.htaccess` forwards every request into `public/` and returns 403 for
  `app/`, `config/`, `database/`, `resources/`, `routes/`, `storage/` and `vendor/`.

## 4. Configure the PHP version and extensions

cPanel → **Select PHP Version**

1. Choose **8.2** or newer.
2. Enable: `pdo_mysql`, `mbstring`, `curl`, `openssl`, `json`, `fileinfo`, `zip`, `gd`.
3. Recommended PHP options: `memory_limit` ≥ 256M, `max_execution_time` ≥ 60,
   `upload_max_filesize` ≥ 8M, `post_max_size` ≥ 12M.

## 5. Configure `config/config.php`

Copy the example file and edit it (File Manager → right-click → Edit):

```php
'app' => [
    'name'  => 'DocuPilot AI',
    'url'   => 'https://yourdomain.com',   // no trailing slash
    'debug' => false,                      // MUST be false in production
    'timezone' => 'Asia/Kolkata',
],
'database' => [
    'host'     => 'localhost',
    'database' => 'cpaneluser_docupilot',
    'username' => 'cpaneluser_docupilot_app',
    'password' => 'the-password-from-step-2',
],
```

Leave the Google / OpenRouter / SMTP / PayU sections blank for now — steps 10–13 fill them in
(either here or from the admin panel).

> `config/config.php` ships with a `.htaccess` that denies direct web access. Keep it.

## 6. Import the database schema

cPanel → **phpMyAdmin** → select your database → **Import** →
choose `database/schema.sql` → **Import**.

You should end up with 17 tables plus seeded plans, templates, settings and the
administrator account.

*If you have SSH:* `php database/migrate.php` does the same thing and also applies any
future migration files.

## 7. Install Composer dependencies

**With SSH (Terminal):**

```bash
cd ~/docupilot          # or ~/public_html
composer install --no-dev --optimize-autoloader
```

**Without SSH:** run `composer install --no-dev --optimize-autoloader` on your own machine
and upload the resulting `vendor/` folder. DocuPilot needs `dompdf/dompdf` (PDF export) and
`phpmailer/phpmailer` (SMTP). The app boots without them but PDF and email features will
report that they are unavailable.

## 8. Configure storage permissions

Make these writable (755 is usually enough on cPanel; 775 if PHP runs as a different user):

```
storage/
storage/uploads/          logos are stored here
storage/uploads/logos/
storage/generated/        generated PDFs
storage/logs/             application + throttle logs
```

In File Manager: select the folder → **Permissions** → tick the write bits →
apply recursively.

## 9. Configure SSL

cPanel → **SSL/TLS Status** → run **AutoSSL** (or install your certificate), then force HTTPS:

cPanel → **Domains** → toggle **Force HTTPS Redirect**.

Make sure `app.url` in `config/config.php` uses `https://`, otherwise share links,
verification emails and PayU callbacks will point at the wrong scheme.

## 10. Configure Google Login

1. <https://console.cloud.google.com/apis/credentials> → **Create credentials → OAuth client ID**
   → *Web application*.
2. Authorised redirect URI: `https://yourdomain.com/auth/google/callback`
3. Put the client ID and secret in `config/config.php` → `google`, and set
   `redirect_uri` to that same URL.
4. Reload the login page — a “Continue with Google” button should appear.

## 11. Configure OpenRouter

1. Create an API key at <https://openrouter.ai/keys>.
2. Sign in to DocuPilot as the administrator → **Admin → AI settings**.
3. Paste the key, choose a model (e.g. `openai/gpt-4o-mini`), keep temperature ~0.4.
4. Click **Test AI connection** — you should see “Connection successful”.

## 12. Configure SMTP

**Admin → Email settings**

| Field | Typical cPanel value |
|---|---|
| Host | `mail.yourdomain.com` |
| Port | `465` (SSL) or `587` (TLS) |
| Username | the full mailbox address |
| Password | the mailbox password |
| Encryption | `ssl` for 465, `tls` for 587 |
| From email | e.g. `documents@yourdomain.com` |

Click **Send test email** and confirm it arrives. Then optionally turn on
**Require email verification** in *Admin → System settings*.

## 13. Configure PayU

1. **Admin → PayU settings** → enter merchant key + salt, choose `test` first.
2. Copy the two callback URLs shown on the page into your PayU dashboard:
   - `https://yourdomain.com/billing/payu/success`
   - `https://yourdomain.com/billing/payu/failure`
3. Run one test payment end-to-end (see step 14), then switch the mode to `live`.

## 14. Test the system

Work through this list on the live domain:

- [ ] `https://yourdomain.com/` — landing page renders with styling
- [ ] `https://yourdomain.com/health` — returns `{"status":"ok","database":true}`
- [ ] Register a new account → you land on the business profile step
- [ ] Save the business profile with a logo → logo appears on the page
- [ ] Add a client
- [ ] Create a document with the wizard (with AI if configured) → totals look right
- [ ] Preview the document, generate the PDF, download it and open it
- [ ] Switch the template to Corporate and Minimal, regenerate the PDF
- [ ] Enable the public share link, open it in a private window, download the PDF there
- [ ] Send the document to your own email address → the PDF arrives attached
- [ ] Pricing → upgrade to Pro with a PayU test payment → plan and limits update
- [ ] Sign in as the administrator → dashboard counters populated, System status all green
- [ ] Sign in as a second user and try to open the first user's document URL → 403
- [ ] Change the default admin password

## Post-launch housekeeping

- **Change the seeded admin password** (`admin@docupilot.ai` / `Admin@12345`).
- Keep `app.debug` set to `false`.
- Back up nightly: the MySQL database plus `storage/uploads/` (logos).
  `storage/generated/` can be rebuilt from documents at any time.
- Watch `storage/logs/app-YYYY-MM-DD.log` for errors; the folder is safe to prune.
- Optional cron (daily) to expire finished subscriptions and clear stale reset tokens:

  ```
  0 3 * * * /usr/local/bin/php /home/<cpaneluser>/docupilot/database/migrate.php --status > /dev/null 2>&1
  ```

  (Expired subscriptions also fall back to Free automatically because active subscriptions
  are filtered by their end date on every request.)

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| “Configuration required” page | `config/config.php` is missing — copy it from `config.example.php`. |
| “Unable to connect to the database” | Check the DB name/user/password; on cPanel the names carry the account prefix. |
| Styling missing / 404 on `/assets/...` | The document root is not `public/`, or `mod_rewrite` is off. Confirm the root `.htaccess` uploaded (dotfiles are hidden by default in File Manager). |
| 500 on every page | Set `'debug' => true` temporarily and read the message, or check `storage/logs/`. |
| “Dompdf is not installed” | Run `composer install` (or upload `vendor/`). |
| PDF generated but empty/garbled | Ensure `storage/generated` and `storage/logs/dompdf` are writable. |
| Emails not sending | Use **Send test email**; the exact SMTP error is shown and logged in `email_logs`. |
| Payment succeeded but plan not active | **Admin → Payments** → open the payment → **Re-verify with PayU**. |
| Uploaded logo not visible | `storage/uploads/logos` must be writable; logos are streamed via `/media/logo/{file}`. |
| Locked out by rate limiting | Delete the files in `storage/logs/throttle/`. |
