# LRMS Field — signed APK

This branch exists only to give the app a download link. Nothing else lives on it.

## Download

**[LRMS-v1.6.2-SIGNED.apk](https://raw.githubusercontent.com/dhdhdh51/zetprobbbvHGY/apk/LRMS-v1.6.2-SIGNED.apk)**
— open that link on the phone and Android will offer to install it.

> **Coming from 1.6.0 or 1.6.1:** it installs straight over it. Nothing to uninstall, nothing lost.
>
> **Coming from 1.5.5 or older:** 1.6.0 changed the signing key, so the app has to be
> replaced once. On each handset: **open the app and sync until nothing is waiting**, then
> uninstall **D2 RECOVERY SOLUTION**, then install from the link above and sign in again.
> Uninstalling deletes the phone's local data, and until a signal returns the outbox is the
> only copy of that day's work. After this one time, every release installs over the last.
>
> Certificate: `b7d11c52707969d94ac3a6c62129ab2b1453437a2c2e02064c2123339e0294a4`

## 1.6.2 — the app calls you a BCA, because that is what you are

The app had the two job titles the wrong way round. It called the person holding the phone a
"BC Supervisor" and the office account a "Admin/Supervisor". In the branch it is the other way
round: the person at the outlet is the **BCA** — the Business Correspondent Agent — and the
**BC Supervisor** is the one in the panel who sets the targets, inspects the outlet and
approves a late report.

So every screen that named either of them now names them the way the branch does, in English
and in Hindi. The sign-in screen, the home page, the device-binding note, the late-report
warning, the SSS lock message, and every error that tells you who to inform.

Nothing else in the app changed. This build is only worth installing so that what the phone
says matches what the panel says and what people call each other.

The panel side of this release is larger:

- **A phone can be handed to a second BCA.** It could not before, and the failure was
  permanent: once a handset had been used by one BCA, releasing it was not enough and the
  next person's sign-in was refused for ever. Now a released handset moves to whoever signs
  in next. A handset that is still bound refuses, and says whose it is and their BC code, so
  you know who to ask. One live handset per account still holds.
- **The monthly inspection form arrives part-filled.** The BCA's name, qualification, age,
  address, IIBF number and how long they have been at the outlet come from what was typed
  when the BCA was added, or from last month's sheet if it was corrected there. Everything
  the inspection is *for* still starts blank — yesterday's transactions, the remuneration,
  the villagers' feedback, the boards, the registers, the equipment, the photographs, the
  grade, and whether the appointment letter and identity card were produced.
- **Every PDF now carries a QR code.** Scan it and the panel opens the record that sheet was
  printed from. A panel login is needed, so a printout left on a desk is not a way into
  customer data. The reference is printed next to the code for whoever has no phone.
- The inspection report's signature line now reads **Visiting official (BC Supervisor)**,
  since that is who visits the outlet and signs it.

## 1.6.1 — errors you can act on

When the server refused something the app used to say *"The server rejected the request
(HTTP 403)."* A number is not a message, and the 403 people were seeing does not even come
from LRMS — it comes from the hosting, which is why it arrived with no words in it.

Every refusal now says what happened, whether the work on the phone is safe, and who to
tell, in whichever language the app is set to. The number stays at the end, for whoever
gets telephoned about it. Where the server does send its own explanation — a day already
submitted, an entry too old to backdate — that is what shows, as before.

Nothing else in the app changed. The panel side of this release also puts the device
**Release** button next to the device it releases, on **Staff ▸ BC supervisors**, with the
action each state actually has: Release when bound, Unblock when blocked, Block when
released. A released device now reads as a deliberate act rather than a fault, and says that
the supervisor can sign in on any handset.

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
| Version | 1.6.2 |
| Server | https://cvbuilder.bharatseo.site/api/v1/ |
| Signed by | D2 Recovery Solution, Patna, Bihar, IN |
| Certificate SHA-256 | `b7d11c52707969d94ac3a6c62129ab2b1453437a2c2e02064c2123339e0294a4` |
| File SHA-256 | `bc9c02d59900d34afa857f7b54d6935a5ab8fd398b8348c365ed2b24fe2fec3e` |
| Size | 3.3M |

Same certificate as 1.6.0 and 1.6.1, so this installs straight over either without
uninstalling. Builds up to 1.5.5 used a different key — see the note at the top.

```
sha256sum LRMS-v1.6.2-SIGNED.apk     # must match the File SHA-256 above
```

If the certificate fingerprint ever differs from the one above, it was signed with a
different key and will not install over an existing copy — do not sideload it without
asking why.

## Older builds

Superseded APKs are removed rather than left downloadable. Every version is rebuildable
from its commit.
