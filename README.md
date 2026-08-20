# LRMS Field — signed APK

This branch exists only to give the app a download link. Nothing else lives on it.

## Download

**[LRMS-v1.5.5-SIGNED.apk](https://raw.githubusercontent.com/dhdhdh51/zetprobbbvHGY/apk/LRMS-v1.5.5-SIGNED.apk)**
— open that link on the phone and Android will offer to install it.

Every release here is signed with the same key, so this installs over the previous
version and keeps the data already on the handset.

## 1.5.5 — the day's social security scheme enrolments

A new screen on the home page: how many people were enrolled today for APY, PMJJBY,
PMSBY and PMJDY. Until now only doorstep visits were counted, so the work done at the
counter did not appear anywhere.

Four boxes and a remark. Nothing is compulsory and a blank box is a zero — a scheme with
no enrolments that day is a real answer, and making somebody type four zeros to say
"nothing happened" only teaches them to skip the screen.

It survives being offline like everything else here. The figures are saved on the handset
first, so the screen shows them with or without a signal, and sent at the next sync.
Saving the same day again corrects it instead of adding to it, which matters because
counting sign-ups at a busy counter is exactly the kind of thing somebody gets wrong once
and fixes a minute later.

The panel side has the same figures under **Field work ▸ SSS enrolments**: a month-to-date
total, filters by branch, supervisor and date, a per-supervisor breakdown, and a printable
report. An Admin can also record or correct a day there for a supervisor whose handset
could not file, and the entry says which of the two it came from.

Older versions remain below.

## 1.5.4 — the Hindi switch actually works now

1.5.3 was fully translated and came out in English on most phones. The language is
switched with `AppCompatDelegate.setApplicationLocales`, which Android applies
process-wide from version 13 onwards — but below that it is a support-library backport
that reaches a screen only through AppCompat's activity delegate, and the app's single
activity was a plain `ComponentActivity`. So the choice was saved, the screen reloaded,
and every label came back in English on any handset older than Android 13.

The activity is an `AppCompatActivity` now, and the window theme had to move from the
framework's `Theme.Material` to `Theme.AppCompat` alongside it, because an AppCompat
activity refuses to start under a framework theme.

**Please check this on a phone.** Sign in, switch to हिन्दी from the sign-in screen or
Profile, and the app should change language immediately. If it does not, say so — the
fix is reasoned from how the support library behaves and is guarded by tests on how the
app is declared, but only a handset can prove it.

## 1.5.3 — Hindi everywhere

Bottom navigation, attendance, the daily report, the sync queue, notifications, profile,
and the messages built outside a screen: sign-in failures, GPS errors, confirmations.
210 translatable strings, 210 Hindi. Eight network diagnostics stay in English; they name
a host or an HTTP code and exist for whoever is debugging one.

## 1.5.2 — no payments

The work is the visit. The borrower pays the bank, so the app does not ask an agent for
an amount, a mode or a receipt. Promise to pay stays: a promise is a finding of the
visit, not a transaction.

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
ok, signature schemes **v2 and v3**, and the Hindi resources confirmed present in the
built file.

| | |
| --- | --- |
| Version | 1.5.4 |
| Server | https://cvbuilder.bharatseo.site/api/v1/ |
| Signed by | D2 Recovery Solutions and Services, Katihar, Bihar, IN |
| Certificate SHA-256 | `8B:B4:8D:4E:F3:1A:35:04:C4:0D:72:68:A8:D2:BD:3D:A6:B0:6C:19:AD:50:04:34:03:54:F1:5C:6A:32:43:55` |
| File SHA-256 | `027c1fc2e903db8aae0e8534035556ed931773cf0a675cced110694984a22d1e` |
| Size | 3.3M |

Same certificate as every build since 1.4.1, so this installs straight over the one on
the phone without uninstalling.

```
sha256sum LRMS-v1.5.4-SIGNED.apk     # must match the File SHA-256 above
```

If the certificate fingerprint ever differs from the one above, it was signed with a
different key and will not install over an existing copy — do not sideload it without
asking why.

## Older builds

Superseded APKs are removed rather than left downloadable. Every version is rebuildable
from its commit.
