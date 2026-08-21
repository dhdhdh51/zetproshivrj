# LRMS Field — signed APK

This branch exists only to give the app a download link. Nothing else lives on it.

## Download

**[LRMS-v1.6.0-SIGNED.apk](https://raw.githubusercontent.com/dhdhdh51/zetprobbbvHGY/apk/LRMS-v1.6.0-SIGNED.apk)**
— open that link on the phone and Android will offer to install it.

> ### Read this before installing 1.6.0
>
> **1.6.0 will not install over 1.5.5.** It is signed with a new key, because the old one was
> lost, and Android identifies an app by the key that signed it. On a phone that already has
> the app you will get "App not installed" — nothing is damaged, the old app carries on.
>
> On each handset, in this order:
>
> 1. **Open the app and sync**, with a signal, until nothing is left waiting. Uninstalling
>    deletes the app's local data, and until a signal returns the outbox is the only copy of
>    that day's work.
> 2. Uninstall **D2 RECOVERY SOLUTION**.
> 3. Install 1.6.0 from the link above and sign in again.
>
> This is a one-off. Every release after 1.6.0 installs straight over it, keeping the data on
> the handset, as 1.5.5 and earlier did among themselves.
>
> Certificate: `b7d11c52707969d94ac3a6c62129ab2b1453437a2c2e02064c2123339e0294a4`

## 1.6.0 — the day's enrolments, against a target

The SSS screen no longer just collects figures; it shows what is expected. The Admin sets a
target per scheme per working day in the panel, and the phone shows the target, what has been
done, the percentage and what is left — recalculating as the figures are typed, and cached so
it still reads with no signal. Sundays and holidays are not counted against anyone.

Once the day is submitted it is closed: the boxes are gone and the screen says to ask the
Admin to re-open it. That is deliberate — the figures feed a register the branch is measured
on, so they cannot be moved quietly afterwards. An Admin re-opening the day buys exactly one
more submission.

If the server refuses something, the screen now says why. Before, a refused day sat reading
"Sent" for ever and the reason only appeared in the outbox list.

The panel side has the whole picture under **Field work ▸ SSS enrolments**: achievement
against target with Today, Month to date and Full month, a ranked table showing every
supervisor's percentage and gap, and an **SSS Target vs Achievement** report that prints and
exports. Targets are set under **Field work ▸ SSS targets**, one daily figure applied to as
many supervisors as you like.

Also in this build: the BC Supervisor inspection is the Bank's own monthly form throughout.
Starting one asks for the supervisor and the date and nothing else — no customer visit or
account to pick — and the assessment is item 24 of the printed form, Excellent to Poor,
instead of a second verdict in the old form's words.

Older versions remain below. They are signed with the previous key, so they can be installed
over one another but not over 1.6.0.

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
