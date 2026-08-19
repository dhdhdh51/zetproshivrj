# LRMS Field — signed APK

This branch exists only to give the app a download link. GitHub release assets
cannot be uploaded from the environment that builds these, so the file is committed
here instead. Nothing else lives on this branch.

## Download

**[LRMS-v1.4.1-SIGNED.apk](https://raw.githubusercontent.com/dhdhdh51/zetprobb/apk/LRMS-v1.4.1-SIGNED.apk)**
— open that link on the phone and Android will offer to install it.

## What it is

Built by CI from `feat/lrms-loan-recovery-system`, then signed with the D2 release
key. Verified before publishing: zipalign ok, signature schemes **v2 and v3**.

| | |
| --- | --- |
| Version | 1.4.1 |
| Server | https://cvbuilder.bharatseo.site/api/v1/ |
| Signed by | D2 Recovery Solutions and Services, Katihar, Bihar, IN |
| Certificate SHA-256 | `8B:B4:8D:4E:F3:1A:35:04:C4:0D:72:68:A8:D2:BD:3D:A6:B0:6C:19:AD:50:04:34:03:54:F1:5C:6A:32:43:55` |
| File SHA-256 | `026b08f956234114e8cd9d3e63c689f61312d5bbef17541ea5c3ffd67a2dfb9d` |
| Size | 3.2M |

## Check it before installing

On any machine with Java:

```
sha256sum LRMS-v1.4.1-SIGNED.apk     # must match the File SHA-256 above
```

If the certificate fingerprint ever differs from the one above, it was signed with a
different key and will not install over an existing copy — do not sideload it
without asking why.

## Installing

Android will warn about installing from outside Play. Allow it for the browser once.
If a previous build was signed with the debug key, uninstall that first — a
differently-signed APK cannot replace it. Every build from here on shares this key,
so future updates install straight over the top.
