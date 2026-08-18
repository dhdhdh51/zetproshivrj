# /admin

The admin panel is served by the single front controller in `public/index.php`
rather than by separate PHP files in this folder, which keeps authentication,
CSRF protection and the `admin` middleware in one place.

Where the admin code actually lives:

| Concern | Path |
|---|---|
| Routes (all prefixed `/admin`, behind the `admin` middleware) | `routes/admin.php` |
| Controllers | `app/Controllers/Admin/` |
| Views | `resources/views/admin/` |
| Layout | `resources/views/layouts/admin.php` |
| Authorisation guard | `app/Middleware/AdminMiddleware.php` |

Sign in at `/login` with an account whose `role` is `admin`, then open `/admin`.
The seeded administrator is `admin@docupilot.ai` / `Admin@12345` — change that password
immediately after the first login.

This folder is blocked from direct web access by the root `.htaccess`.
