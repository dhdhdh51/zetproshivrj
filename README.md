# LRMS Field — signed APK

This branch exists only to give the app a download link. Nothing else lives on it.

## Read this before you send the link to anyone

**1.7.0 will not install over 1.6.x.** The app was signed with a new key so that the
certificate carries the company's own name, and to a phone a new key means a different app. The
old one has to come off first.

**Uninstalling deletes the phone's local data, and the outbox is part of it.** Anything a BCA
has recorded but not yet managed to send is the only copy of that work. It goes when the app
goes.

So on every handset, in this order:

1. **Open the app and sync until nothing is left waiting.** Not "mostly synced" — empty. On a
   handset with no signal, wait for one. Do not skip ahead.
2. **Then uninstall** the old app.
3. **Then install** from the link below and sign in again.

If somebody installs without uninstalling, Android just refuses with **"App not installed"**.
Nothing is damaged, the old app carries on working, and they can go back and do step 1 — so a
mistake here is recoverable. Getting the order wrong is not.

This is the second and hopefully last time. Every release after 1.7.0 installs straight over it.

## Download

**[LRMS-v1.7.0-SIGNED.apk](https://raw.githubusercontent.com/dhdhdh51/zetproshivrj/apk/LRMS-v1.7.0-SIGNED.apk)**
— open that link on the phone, after the three steps above.

> **The certificate now carries the company name.** It reads **D2 SQUARE CREDIT SOLUTIONS**,
> matching the rename. That is the whole reason this release needs a reinstall; nothing else
> about it required one.
>
> **The server is** `server.d2squarecreditsolutions.in`, as it has been since 1.6.4. Anything
> older than that stops working when the old address is switched off, so everyone needs this
> build regardless.
>
> **Coming from 1.6.x, or 1.5.5, or anything else:** the three steps above apply. There is no
> version this installs straight over.
>
> Certificate: `1bc1c332a319c53c432488f844bb2321df9156cde5bbe0c87c81127c08518323`

## 1.7.0 — the certificate carries the company name

Nothing in the app changed. This release exists for one reason: the key that signs it now says
**D2 SQUARE CREDIT SOLUTIONS** instead of the old company name.

That name is not something a phone ever shows anyone. It sits inside the signature, and the only
way to see it is to inspect the file with developer tools. The name on the launcher and the
sign-in screen changed back in 1.6.4.

A certificate's subject cannot be edited, so putting a new name on it means a new key, and a new
key means every handset uninstalls once — which is why the previous release notes argued for
leaving it alone. The client asked for it anyway: they want their own name on what signs their
software, and accepted the reinstall. That is their call to make.

What that buys is a signature that reads correctly to anyone who ever inspects the file. What it
costs is the reinstall at the top of this page. Worth being clear that the trade is real in both
directions.

So: same app as 1.6.4, same server, same reminders, same reports. New key, and the one-time
uninstall that comes with it.

## 1.6.4 — the new name, and the phone finally reminds you

### The reminders

The app now reminds you, and it works without a signal.

- **Before the daily deadline.** An hour before, then half an hour, then ten minutes — the same
  points your BC Supervisor's panel is set to. Nothing arrives if you have already sent the day's
  report.
- **Each morning**, at a time you choose, telling you how many of your allocated accounts have
  still not been visited.
- **Whenever your BC Supervisor sends you something** — a follow-up due today, a promise that was
  not kept, a late report approved or refused.

That last one is worth explaining. The panel has been sending those messages all along and the
app was quietly filing them in a list. Nothing ever made a sound. Now they do.

Turn them on or off, and set the morning time, under **Profile ▸ Reminders**. That screen also
tells you when the next reminder is due — and if nothing is arriving, it says which of the
phone's own settings is stopping it, rather than leaving you guessing.

### The new brand

New name, new launcher icon, new mark on the sign-in screen.

### The server

This build talks to `https://server.d2squarecreditsolutions.in/api/v1/`.

## 1.6.3 — the sign-in screen is for signing in

Two things sat under the sign-in form that were never meant for the field: a **Test
connection** button, and the full server address printed underneath it. They were added to
tell a wrong server apart from a dead network, and on a developer's screen they did exactly
that. But the sign-in screen is the first thing a BCA sees, and to them a diagnostic button
and a web address are two more things that can be wrong before they have typed a password.

Both are gone. Nothing is lost by it: when the server refuses something, the message already
names the host it came from, so anyone helping over the phone still gets the same answer.

The line at the foot of the screen no longer says `production` in front of the version — the
real build has no need to announce that it is the real one. A staging build still says so.

The warning that an APK is pointed at a developer machine has deliberately **stayed**. It
only appears when the app is built against `localhost`, and it is the one thing that stops a
test build being handed out and used at an outlet by mistake.

### The printed reports

The rest of this release is the PDFs, which the client said simply looked wrong. Every page of
all four documents was rendered and read, and there were seven separate problems — all of them
about where things landed on the page rather than what they said:

- **The inspection wasted a whole sheet.** Its third page stopped just short of the bottom and
  the QR code went onto a fourth, leaving that page almost entirely blank. The office address
  and the QR now share one block at the foot of the form. Three pages instead of four.
- **Item 26 was printed twice**, and its signature lines came after item 27 — so the sheet read
  25, 26, 27, then 26 again, with the ruled lines under the second one. It now appears once, in
  its place, with the lines directly beneath it.
- **"Scan to open this record" was cut to "Scan to open this rec…"** on the inspection form.
- **"11. DECLARATION"** on the verification report ended a page with the declaration itself
  overleaf. A heading now stays with what it heads.
- **Signature captions sat right on top of the next section's heading bar.**
- **Tick boxes turned up with nothing to say what they answered** — "Sign board at the BC
  point" ended a page and its Yes / No boxes started the next, and a group of six options split
  in half across a page break. A group of boxes now stays with its question.
- **An evidence photograph printed as a solid black box** with white lettering across it,
  because the stamp burnt into the picture came out taller than the picture.

Every QR code on every document was scanned back out of the printed page to check it still
leads where it should.

### And one thing you would never have seen

Two phones signing in at the very same moment could both bind to one account, which is the
single thing device binding exists to prevent. It needed the two sign-ins to overlap within a
few milliseconds, so it would have shown up as a BCA whose phone stopped working for no
reason anybody could reproduce. Closed, and there is now a test that fires fourteen sign-ins
at once, ten times over, to keep it closed.

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

Built by CI, then signed with the release key. Everything below was read back out of the built
file rather than taken on trust: zipalign ok, signature schemes **v2 and v3**, the certificate
fingerprint straight off the signature, the version name out of the packaged manifest with 1.6.4
confirmed gone, the server address out of the compiled BuildConfig with the old host confirmed
gone, the new company name out of the packaged resources with the old one confirmed gone, both
reminder receivers in the merged manifest, and the reminder wording present in English *and*
Hindi.

What was **not** verified, here or in any previous release, is a reminder actually going off on a
real handset. There is no phone and no emulator in the build environment, so what can be checked
is that the receivers are registered, the alarms are scheduled and the wording is packaged — not
that a notification appeared on somebody's screen. That is worth confirming on one handset before
the build is sent to everybody.

| | |
| --- | --- |
| Version | 1.7.0 |
| Server | https://server.d2squarecreditsolutions.in/api/v1/ |
| Signed by | D2 SQUARE CREDIT SOLUTIONS, Patna, Bihar, IN |
| Certificate SHA-256 | `1bc1c332a319c53c432488f844bb2321df9156cde5bbe0c87c81127c08518323` |
| File SHA-256 | `2d84ff6286055afe4f8dfd699eb6d929e836af9e42cfa13b982d12ff02e9cbae` |
| Size | 3.5M |

**A different certificate from 1.6.x**, which is why this one needs an uninstall — see the top
of this page. Three keys have signed this app now: one up to 1.5.5 that was lost, one for 1.6.0
through 1.6.4, and this one from 1.7.0 onwards. Each change cost every handset a reinstall.

The certificate reads **D2 SQUARE CREDIT SOLUTIONS**, and reissuing it to say so is the entire
content of this release. Nobody should change it again without a reason worth another forced
uninstall — and the company name was, arguably, only just worth this one.

```
sha256sum LRMS-v1.7.0-SIGNED.apk     # must match the File SHA-256 above
```

If the certificate fingerprint ever differs from the one above, it was signed with a
different key and will not install over an existing copy — do not sideload it without
asking why.

## Older builds

1.5.4 onwards are all still on this branch, and stay there. A download link for a build
gets forwarded round a branch by WhatsApp and lives far longer than the release does, so
deleting one turns somebody's saved link into a 404 with nothing to explain it. The
previous build is also the only way back if a new one turns out to be wrong on a handset.

Install the newest one unless you have been told otherwise. Every version is also
rebuildable from its commit.
