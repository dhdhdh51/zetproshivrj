# The release signing key

An Android app is identified by the key that signed it, not by its name. A build signed with
a different key is a different app as far as the phone is concerned: it refuses to install
over the one already there, and the only way in is to uninstall first — which takes the
handset's local data with it, including any field work still waiting in the outbox for a
signal.

So there is one key, it never changes, and it never goes in this repository.

## The current certificate

```
SHA-256  b7d11c52707969d94ac3a6c62129ab2b1453437a2c2e02064c2123339e0294a4
Subject  CN=D2 RECOVERY SOLUTION, OU=LRMS Field, O=D2 Recovery Solution, L=Patna, ST=Bihar, C=IN
Alias    lrms
Key      RSA 4096, SHA384withRSA
Expires  6 January 2054
```

`EXPECTED_CERT_SHA256` in `.github/workflows/android-build.yml` holds that fingerprint, and
the workflow refuses to publish a tag signed by anything else.

### The subject still says the old company name, and has to

The company was renamed to **D2 Square Credit Solutions**, and the app was renamed with it. This
certificate was not, and must not be: a subject cannot be edited, so changing it means generating
a new key, and a new key is a new app to every handset in the field. Every one of them would have
to be uninstalled — taking any field work still waiting in the outbox with it — to install the
next release.

Nothing depends on the wording. The first line of this file is the reason: a phone identifies an
app by the key, not by the name on it. The name people see comes from `app_name`, which has been
changed. Leave the subject alone; it is a fingerprint, not a letterhead.

Every published APK is verified against it. To check one by hand:

```bash
java -jar uber-apk-signer.jar -y -a LRMS-v1.6.0-SIGNED.apk
```

## The key was replaced once, at v1.6.0

Releases up to **v1.5.5** were signed with an earlier key,
`8bb48d4ef31a3504c40d7268a8d2bd3da6b06c19ad5004340354f15c6a324355`. That keystore was lost —
it was never a repository secret and no copy survived — so v1.6.0 was signed with the key
above.

**v1.6.0 therefore cannot install over v1.5.5.** It is the one break in the chain. Handsets
running v1.5.5 or older have to sync, then uninstall, then install v1.6.0. Every release
after v1.6.0 installs straight over it.

If a phone is handed v1.6.0 without uninstalling first, Android simply refuses with "App not
installed" — nothing is damaged, and the old app carries on working.

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
base64 -w 0 lrms-release.jks > keystore.b64
```

## Signing a build by hand

When CI has produced an unsigned or debug-signed APK and the secrets are not set yet:

```bash
export LRMS_KEYSTORE_PASSWORD='…'
export LRMS_KEY_ALIAS=lrms
deploy/sign-apk.sh path/to/LRMS-v1.6.0-release.apk /path/to/lrms-release.jks
```

It fetches `uber-apk-signer`, which bundles `zipalign` and `apksigner`, so no Android SDK is
needed — a JDK is enough. The signed file lands in `signed/` next to the input, and the
script prints the certificate so the fingerprint can be checked against the table above
before anything is handed out.

## Generating a key, if there is ever no other choice

Only when the current one is lost, and knowing it forces an uninstall on every handset:

```bash
keytool -genkeypair -v \
  -keystore lrms-release.jks -storetype JKS -alias lrms \
  -keyalg RSA -keysize 4096 -validity 10000 \
  -dname "CN=D2 RECOVERY SOLUTION, OU=LRMS Field, O=D2 Recovery Solution, L=Patna, ST=Bihar, C=IN"
```

Then update `EXPECTED_CERT_SHA256` in the workflow, the fingerprint in this file, and tell
every supervisor to sync before they uninstall. A validity of 10,000 days is deliberate: a
certificate that expires is a release that cannot be made.
