# LRMS Field — signed APK

This branch exists only to give the app a download link. Nothing else lives on it.

## Download

**[LRMS-v1.5.3-SIGNED.apk](https://raw.githubusercontent.com/dhdhdh51/zetprobb/apk/LRMS-v1.5.3-SIGNED.apk)**
— open that link on the phone and Android will offer to install it.

## 1.5.3 — the app is fully in Hindi

Every screen and every message now follows the language switch. What was still English:

- The bottom navigation — Home, Accounts, Attendance, Sync, Profile — which is the one
  piece of chrome visible on every screen and had never switched in either language.
- Attendance, the daily report, the sync queue, notifications and profile: titles,
  buttons, deadline warnings, empty states, the sign-out confirmation.
- The messages built outside a screen: sign-in failures, GPS errors, "saved and queued"
  confirmations, sync status. These needed a locale-aware context, because below Android
  13 the app-wide locale does not reach code that has no activity — they would have
  stayed English on exactly the cheap handsets this app is for.
- Four counts that used "(s)" to dodge plurals, which Hindi cannot express that way.

210 translatable strings, 210 Hindi, none missing.

Still English: eight network diagnostics that name a host or an HTTP code. They exist
for whoever is debugging a certificate or DNS problem.

## 1.5.2 — no payments

The work is the visit. The borrower pays the bank, so the app no longer asks an agent
for an amount, a mode or a receipt, and the "add recovery" dialog is gone. Promise to
pay stays: a promise is a finding of the visit, not a transaction.

## 1.5.0 — matching the reference app

- Case type is a dropdown; the app is named **D2 Recovery Solutions & Services**.
- Photo slots: borrower, house, Aadhaar copy, the agent's own photograph, then shop,
  land and document.
- GPS is read again when the report is filed, not only when the visit was started.
- A visit with no usable location can still be submitted.
- A warning when only half a promise has been entered.
- Occupation "Other" asks which trade, and that is what gets printed.

## Details

Built by CI, then signed with the D2 release key. Verified before publishing: zipalign
ok, signature schemes **v2 and v3**, and the APK searched to confirm the Hindi strings
are really in it.

| | |
| --- | --- |
| Version | 1.5.3 |
| Server | https://cvbuilder.bharatseo.site/api/v1/ |
| Signed by | D2 Recovery Solutions and Services, Katihar, Bihar, IN |
| Certificate SHA-256 | `8B:B4:8D:4E:F3:1A:35:04:C4:0D:72:68:A8:D2:BD:3D:A6:B0:6C:19:AD:50:04:34:03:54:F1:5C:6A:32:43:55` |
| File SHA-256 | `6f6dd4086bc57a54ac36c15239e93e2047616d13a30a55e4341122126eb04d9e` |
| Size | 3.2M |

Same certificate as every build since 1.4.1, so this installs straight over the one on
the phone without uninstalling.

```
sha256sum LRMS-v1.5.3-SIGNED.apk     # must match the File SHA-256 above
```

If the certificate fingerprint ever differs from the one above, it was signed with a
different key and will not install over an existing copy — do not sideload it without
asking why.

## Older builds

Superseded APKs are removed rather than left downloadable. Every version is rebuildable
from its commit.
