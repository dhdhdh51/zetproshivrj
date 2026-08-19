# Languages — English and Hindi

The web panel runs in English or Hindi. Anyone can switch from the toggle in the
top bar (or under the card on the sign-in page), and the choice is remembered on
that browser. An admin sets the language the panel opens in under
**Settings ▸ General ▸ Default language**.

## Where the strings live

```
resources/lang/en.php   English — the source of truth
resources/lang/hi.php   Hindi
```

Flat arrays keyed with dots, e.g. `'nav.dashboard' => 'Dashboard'`. Views print
them with the two helpers in `app/Helpers/functions.php`:

```php
<?= et('nav.dashboard') ?>                          // translate + HTML-escape
<?= et('topbar.report_deadline', ['time' => $t]) ?>  // :time is replaced
<?= __('auth.sign_in') ?>                            // raw, escape it yourself
```

`:placeholder` tokens are substituted but never escaped by the translator —
views escape on output, so escaping twice would show `&amp;` on the page.

## Adding a string

1. Add the key to `resources/lang/en.php`.
2. Add the same key to `resources/lang/hi.php`.
3. Use `et('your.key')` in the view.

`tests/http-smoke.php` fails if a key exists in English but not in Hindi, if
Hindi carries a key English has dropped, if a `:placeholder` appears in one
language but not the other, or if a Hindi string is blank. A key missing from
Hindi at runtime falls back to English rather than rendering an empty label, so
a gap degrades into a readable screen instead of a broken one.

## How the language is chosen

In order: the session (someone just used the toggle) → the `lrms_locale` cookie
(this browser's standing choice) → `default_locale` in Settings → English.

It is deliberately **not** a column on `users`. There is no incremental migration
runner in this project, so a schema change would mean hand-run SQL against a
production database that already holds live recovery data. A cookie also matches
what a language toggle is expected to do.

## Translation choices

Terms staff actually say in the branch are kept that way rather than rendered
into unfamiliar Sanskritised Hindi — a clerk looks for **ओटीएस**, not a literal
translation nobody uses. Codes printed on paperwork stay in Latin script for the
same reason: **KRM OTS**, **CKCC OD-2**, **PTP**, **BCBF** must match the forms
and spreadsheets on the desk.

## What is *not* translated yet

Being explicit so these are not mistaken for bugs:

| Area | State |
| --- | --- |
| Sign-in, app-only notice, sidebar, top bar, shared buttons and words | **Translated** |
| Individual admin/manager screen bodies (tables, filters, help text) | English only — they fall back, so nothing breaks |
| Android app | **English only.** 183 strings are hardcoded in Compose; they need extracting to `strings.xml` plus a `values-hi/` copy before the app can follow the phone's language |
| Visit and inspection form labels | English only — admins author these in the form builder, so a Hindi label needs a `label_hi` column on the form-field tables |
| Generated PDFs | **Devanagari will not render.** `PdfWriter` uses the standard PDF core fonts, which have no Devanagari glyphs. Hindi PDFs need an embedded Unicode TTF (e.g. Noto Sans Devanagari) *and* text shaping for conjuncts and matras — a substantial piece of work, not a font swap |

Hindi text in the database — borrower names, addresses, remarks typed by field
staff — already stores and displays correctly everywhere, because the schema and
connection are `utf8mb4`. Only *generated PDFs* cannot draw it.
