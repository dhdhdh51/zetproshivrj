# Migrations

`database/schema.sql` is the **baseline** — it creates every table and seeds plans,
templates, settings and the default administrator.

Anything that changes the schema *after* the baseline goes in this folder as a
timestamped `.sql` file, for example:

```
2026_09_01_000001_add_invoice_due_date.sql
```

Apply them with:

```bash
php database/migrate.php          # install baseline if needed + run pending files
php database/migrate.php --status # list applied / pending migrations
php database/migrate.php --fresh  # drop all app tables and re-install (destructive)
```

Applied filenames are recorded in the `settings` table under the
`migrations_applied` key, so each file runs only once.

On shared hosting without SSH you can import `schema.sql` (and any migration
file) through phpMyAdmin → Import.
