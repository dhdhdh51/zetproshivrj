# LRMS Field — signed APK

This branch exists only to give the app a download link. GitHub release assets cannot
be uploaded from the environment that builds these, so the file is committed here
instead. Nothing else lives on this branch.

## Download

**[LRMS-v1.5.0-SIGNED.apk](https://raw.githubusercontent.com/dhdhdh51/zetprobb/apk/LRMS-v1.5.0-SIGNED.apk)**
— open that link on the phone and Android will offer to install it.

## What is new in 1.5.0

Built to match the reference app the client asked this one to follow.

- Case type is a dropdown instead of a row of chips.
- The app is named **D2 Recovery Solutions & Services**.
- Photo slots match the reference: borrower, house, Aadhaar copy, and the agent's own
  photograph, ahead of the shop, land and document slots a CKCC crop case needs.
- GPS is read again when the report is filed, not only when the visit was started, so
  a form left open on the doorstep no longer files the previous doorstep. Both points
  are kept on the report.
- A visit with no usable location at all can now be submitted. That was the last
  place a report could be thrown away for its location.
- A warning when only half a promise has been entered. The server records a promise
  only when an amount and a date arrive together, so half of one used to disappear
  without a word.
- Occupation "Other" asks which trade, and the answer is what gets printed.
- The visit screen is fully translated: every label on it now switches with the
  app language, which it did not before.

## Details

Built by CI from `feat/lrms-loan-recovery-system`, then signed with the D2 release
key. Verified before publishing: zipalign ok, signature schemes **v2 and v3**.

| | |
| --- | --- |
| Version | 1.5.0 |
| Server | https://cvbuilder.bharatseo.site/api/v1/ |
| Signed by | D2 Recovery Solutions and Services, Katihar, Bihar, IN |
| Certificate SHA-256 | `8B:B4:8D:4E:F3:1A:35:04:C4:0D:72:68:A8:D2:BD:3D:A6:B0:6C:19:AD:50:04:34:03:54:F1:5C:6A:32:43:55` |
| File SHA-256 | `9a3ca3e338a33abf079ab7e64780d0d155d229d96f158df4cac00ade31b33127` |
| Size | 3.2M |

Same certificate as 1.4.1, so this installs straight over it without uninstalling.

## Check it before installing

```
sha256sum LRMS-v1.5.0-SIGNED.apk     # must match the File SHA-256 above
```

If the certificate fingerprint ever differs from the one above, it was signed with a
different key and will not install over an existing copy — do not sideload it without
asking why.

## Installing

Android will warn about installing from outside Play. Allow it for the browser once.
Every build from 1.4.1 onwards shares this key, so updates install over the top.

## Previous

[LRMS-v1.4.1-SIGNED.apk](https://raw.githubusercontent.com/dhdhdh51/zetprobb/apk/LRMS-v1.4.1-SIGNED.apk)
