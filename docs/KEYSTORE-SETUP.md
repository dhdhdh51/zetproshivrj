# The release signing key

An Android app is identified by the key that signed it, not by its name. A build signed with
a different key is a different app as far as the phone is concerned: it refuses to install
over the one already there, and the only way in is to uninstall first — which takes the
handset's local data with it, including any field work still waiting in the outbox for a
signal.

So a key should never change, and never go in this repository. It has changed twice: once
because it was lost, and once because the client asked for the company's own name on it
knowing what that costs. Both are recorded below, because a chain of keys nobody wrote down
is how the first one got lost.

## The current certificate

```
SHA-256  1bc1c332a319c53c432488f844bb2321df9156cde5bbe0c87c81127c08518323
Subject  CN=D2 SQUARE CREDIT SOLUTIONS, OU=LRMS Field, O=D2 Square Credit Solutions, L=Patna, ST=Bihar, C=IN
Alias    lrms
Key      RSA 4096, SHA384withRSA
Expires  15 January 2054
Signs    v1.7.0 onwards
```

`EXPECTED_CERT_SHA256` in `.github/workflows/android-build.yml` holds that fingerprint, and
the workflow refuses to publish a tag signed by anything else.

The locality still reads Patna, Bihar. It was carried over from the previous certificate
because no new address was given, and inventing one would have been worse than leaving the old
one. Nothing validates it; correct it at the next reissue if there ever is one.

Every published APK is verified against it. To check one by hand:

```bash
java -jar uber-apk-signer.jar -y -a LRMS-v1.7.0-SIGNED.apk
```

### Why the subject was changed, when the advice was not to

An earlier version of this file argued the subject should be left alone after the rename, and
the argument still holds on its own terms: a certificate is a fingerprint, not a letterhead, the
name people see comes from `app_name`, and nothing technical depends on the wording. Changing it
buys nothing a phone can observe and costs every handset an uninstall.

The client was told that and asked for it anyway — they want the company's own name on what signs
their software, and accepted the reinstall. That is a legitimate call to make; it is theirs, not
this file's. What matters now is that the cost is paid once and paid properly, which means every
supervisor syncing to empty **before** uninstalling.

This is the honest reason, recorded so that nobody later reads the change as a mistake and
"corrects" it back — which would be a third break in the chain for no reason at all.

## The chain of keys, and the two breaks in it

| Releases | Certificate SHA-256 | Why it changed |
| --- | --- | --- |
| up to v1.5.5 | `8bb48d4ef31a3504c40d7268a8d2bd3da6b06c19ad5004340354f15c6a324355` | — |
| v1.6.0 – v1.6.4 | `b7d11c52707969d94ac3a6c62129ab2b1453437a2c2e02064c2123339e0294a4` | the one above was lost: never a repository secret, no copy survived |
| v1.7.0 onwards | `1bc1c332a319c53c432488f844bb2321df9156cde5bbe0c87c81127c08518323` | reissued so the certificate carries the company's name after the rename |

A build cannot install over one signed by a different key, so each row is a forced uninstall:

- **v1.6.0 could not install over v1.5.5.**
- **v1.7.0 cannot install over v1.6.x.**

Within a row, every release installs straight over the last.

### What a forced uninstall actually costs

Not the app — that reinstalls in a minute. What goes is the handset's local database, and the
outbox is part of it. Anything a supervisor recorded and has not yet managed to sync is the only
copy of that work, and uninstalling deletes it.

So the order is not negotiable: **open the app, sync until nothing is waiting, then uninstall,
then install the new build.** On a handset with no signal, that means waiting for one rather
than uninstalling anyway.

If a phone is handed the new build without uninstalling first, Android refuses with "App not
installed". Nothing is damaged and the old app carries on working, so a mistake here is
recoverable — which is the one piece of good news.

## Where the key lives

Not here. The keystore and its password are held by the client, and the only copy that
matters is theirs. Losing it again means another forced uninstall for every supervisor, so it
belongs in two places at once: a password manager and an offline backup.

The four values CI needs:

| Secret | Value |
| --- | --- |
| `KEYSTORE_BASE64` | the `.jks` file, base64-encoded on a single line |
| `KEYSTORE_PASSWORD` | the keystore password |
| `KEY_ALIAS` | `lrms` |
| `KEY_PASSWORD` | the key password (the same as the keystore password for this key) |

Set them under **Settings ▸ Secrets and variables ▸ Actions** on the repository. Once they
are there, CI signs every release build by itself and `deploy/sign-apk.sh` is only needed for
signing something CI already built.

To produce the base64 from the keystore:

```bash
base64 -w 0 lrms-release-2.jks > keystore.b64
```

## Signing a build by hand

When CI has produced an unsigned or debug-signed APK and the secrets are not set yet:

```bash
export LRMS_KEYSTORE_PASSWORD='…'
export LRMS_KEY_ALIAS=lrms
deploy/sign-apk.sh path/to/LRMS-v1.7.0-release.apk /path/to/lrms-release-2.jks
```

It fetches `uber-apk-signer`, which bundles `zipalign` and `apksigner`, so no Android SDK is
needed — a JDK is enough. The signed file lands in `signed/` next to the input, and the
script prints the certificate so the fingerprint can be checked against the table above
before anything is handed out.

## Generating a key, if there is ever no other choice

Only when the current one is lost, or when somebody who owns this software decides the name on
it matters more than the reinstall — and knowing it forces an uninstall on every handset:

```bash
keytool -genkeypair -v \
  -keystore lrms-release-2.jks -storetype JKS -alias lrms \
  -keyalg RSA -keysize 4096 -validity 10000 \
  -dname "CN=D2 SQUARE CREDIT SOLUTIONS, OU=LRMS Field, O=D2 Square Credit Solutions, L=Patna, ST=Bihar, C=IN"
```

That is the exact command the current key was made with. Then, in the same change:

1. `EXPECTED_CERT_SHA256` in `.github/workflows/android-build.yml`
2. the certificate block and the chain table in this file
3. the four GitHub secrets, if they are set
4. a **minor version bump**, because the break deserves a version number somebody can point at —
   v1.6.0 and v1.7.0 were both key changes
5. the `apk` branch README, leading with sync-then-uninstall rather than burying it

A validity of 10,000 days is deliberate: a certificate that expires is a release that cannot be
made.
