# LRMS Field — signed APK

This branch exists only to give the app a download link. GitHub release assets cannot
be uploaded from the environment that builds these, so the file is committed here
instead. Nothing else lives on this branch.

## Download

**[LRMS-v1.5.2-SIGNED.apk](https://raw.githubusercontent.com/dhdhdh51/zetprobb/apk/LRMS-v1.5.2-SIGNED.apk)**
— open that link on the phone and Android will offer to install it.

## 1.5.2 — the app takes no payments

The work is the visit. The borrower pays the bank directly, so the app no longer asks
an agent how much they received: the amount, mode and receipt fields are gone from the
visit screen, and the "add recovery" dialog and its button are gone from the account
screen. Nothing in the app can create a payment record.

Promise to pay stays, and the visit screen's money card is now the promise card. A
promise is a finding of the visit, not a transaction.

An agent whose phone still holds a payment queued offline before this update will
still see it sync — those entries are accepted, because refusing them would lose the
record of money somebody already paid rather than prevent it.

## 1.5.0 — matching the reference app

- Case type is a dropdown instead of a row of chips.
- Named **D2 Recovery Solutions & Services**.
- Photo slots: borrower, house, Aadhaar copy, and the agent's own photograph, ahead of
  the shop, land and document slots a CKCC crop case needs.
- GPS is read again when the report is filed, not only when the visit was started, so
  a form left open on the doorstep no longer files the previous doorstep.
- A visit with no usable location at all can be submitted. That was the last place a
  report could be thrown away for its location.
- A warning when only half a promise has been entered — the server records a promise
  only when an amount and a date arrive together.
- Occupation "Other" asks which trade, and the answer is what gets printed.
- The visit screen is fully translated; every label follows the app language.

## Details

Built by CI from `feat/lrms-loan-recovery-system`, then signed with the D2 release
key. Verified before publishing: zipalign ok, signature schemes **v2 and v3**, and the
built APK searched to confirm no payment wording survives in either language.

| | |
| --- | --- |
| Version | 1.5.2 |
| Server | https://cvbuilder.bharatseo.site/api/v1/ |
| Signed by | D2 Recovery Solutions and Services, Katihar, Bihar, IN |
| Certificate SHA-256 | `8B:B4:8D:4E:F3:1A:35:04:C4:0D:72:68:A8:D2:BD:3D:A6:B0:6C:19:AD:50:04:34:03:54:F1:5C:6A:32:43:55` |
| File SHA-256 | `e9db07f53e2a34689b7a8a6704f9dfbb9d9c9783dbd90a99ab66c0d766dc898d` |
| Size | 3.2M |

Same certificate as every build since 1.4.1, so this installs straight over the one on
the phone without uninstalling.

## Check it before installing

```
sha256sum LRMS-v1.5.2-SIGNED.apk     # must match the File SHA-256 above
```

If the certificate fingerprint ever differs from the one above, it was signed with a
different key and will not install over an existing copy — do not sideload it without
asking why.

## Older builds

Superseded APKs are removed rather than left downloadable, so nobody installs a build
that still asks an agent to take a payment. Every version is rebuildable from its
commit.
