# LRMS Field — Android build and release

The BC Supervisor field application. Kotlin + Jetpack Compose, offline-first,
built entirely from AndroidX — **no Google Play Services dependency**, so it runs
on the cheap handsets field staff actually carry.

Source: [`android/`](../android). Package `in.lrms.field`.

---

## Toolchain

| | Version | Why it is pinned |
| --- | --- | --- |
| JDK | **17** | AGP 8.7 requires 17; newer JDKs fail the Gradle wrapper check |
| Gradle | 8.9 (wrapper committed) | matches AGP 8.7.3 |
| Android Gradle Plugin | 8.7.3 | |
| Kotlin | 2.0.21 | with the Compose compiler plugin (no separate compiler version) |
| KSP | 2.0.21-1.0.28 | Room code generation |
| compileSdk / targetSdk | 35 | |
| minSdk | **24** (Android 7.0) | covers the low-end devices in use |

Dependency versions live in
[`android/gradle/libs.versions.toml`](../android/gradle/libs.versions.toml).
Key libraries: Room, WorkManager, Retrofit + Moshi, `androidx.security-crypto`
(EncryptedSharedPreferences), `androidx.exifinterface`, Navigation Compose,
Material 3.

---

## Building locally

```bash
cd android
echo "sdk.dir=/path/to/Android/sdk" > local.properties   # or set ANDROID_HOME

./gradlew testDebugUnitTest        # 25 unit tests
./gradlew assembleDebug            # app/build/outputs/apk/debug/
./gradlew lintDebug
```

`local.properties`, `keystore.properties`, `*.jks`, `*.keystore` and all build
directories are git-ignored.

Release build:

```bash
./gradlew assembleRelease bundleRelease lintRelease \
  -PlrmsApiUrl=https://lrms.example.com/api/v1/ \
  -PlrmsVersionName=1.0.0 -PlrmsVersionCode=1
```

Outputs land in `app/build/outputs/apk/release/` and
`app/build/outputs/bundle/release/`.

---

## Build types and the API URL

The base URL is **never hard-coded in Kotlin**. It is a `buildConfigField` per
build type, and `-PlrmsApiUrl` overrides any of them.

| Build type | applicationId | Default API URL | Cleartext HTTP |
| --- | --- | --- | --- |
| `debug` | `in.lrms.field.debug` | `http://10.0.2.2:8000/api/v1/` (emulator → host `php -S`) | allowed |
| `staging` | `in.lrms.field.staging` | `https://staging.example.com/api/v1/` | refused |
| `release` | `in.lrms.field` | `https://lrms.example.com/api/v1/` | refused |

All three can be installed side by side, so a supervisor can keep production
while testing a staging build.

Resolution order, from
[`app/build.gradle.kts`](../android/app/build.gradle.kts):

| Build type | 1st | 2nd | 3rd |
| --- | --- | --- | --- |
| `debug` | `-PlrmsApiUrl` | `lrmsDebugApiUrl` in `gradle.properties` | `http://10.0.2.2:8000/api/v1/` |
| `staging`, `release` | `-PlrmsApiUrl` | — | the build type's default (HTTPS placeholder) |

The developer convenience default is deliberately a **separate property**
(`lrmsDebugApiUrl`) that only the debug build reads. A development URL therefore
cannot reach a build handed to field staff even by accident.

**Shipped builds must be HTTPS, and the build enforces it.** If the resolved URL
for `staging` or `release` is not `https://`, Gradle fails with an explanatory
message rather than producing an APK that sends credentials in the clear:

```
$ ./gradlew assembleRelease -PlrmsApiUrl=http://10.0.2.2:8000/api/v1/
Refusing to build 'staging' with the non-HTTPS API URL http://10.0.2.2:8000/api/v1/.
Pass -PlrmsApiUrl=https://your-server/api/v1/ …
BUILD FAILED
```

Belt and braces: staging and release also set `usesCleartextTraffic=false` and
ship a
[`network_security_config.xml`](../android/app/src/main/res/xml/network_security_config.xml)
that requires HTTPS at runtime.

A trailing slash is added if missing — Retrofit requires it.
`BuildConfig.ENVIRONMENT` (`development` / `staging` / `production`) is shown on
the sign-in screen and the About screen, so a supervisor can tell support which
build they are running.

> **Set a real URL for production.** The release default is the placeholder
> `https://lrms.example.com/api/v1/`. Configure the repository variable
> `LRMS_API_URL` (below) or pass `-PlrmsApiUrl`; the workflow prints a warning
> when neither is present.

---

## Signing

Nothing secret is ever committed. `app/build.gradle.kts` looks for the keystore
in this order:

1. `android/keystore.properties` (git-ignored, for a local release build);
2. Gradle properties `-PlrmsKeystoreFile`, `-PlrmsKeystorePassword`,
   `-PlrmsKeyAlias`, `-PlrmsKeyPassword`;
3. environment variables `LRMS_KEYSTORE_FILE`, `LRMS_KEYSTORE_PASSWORD`,
   `LRMS_KEY_ALIAS`, `LRMS_KEY_PASSWORD`.

If no usable keystore is found, the release build **falls back to the debug
signing key** so the pipeline still produces an installable APK for testing. Such
a build must never be distributed — the workflow emits a warning and the build
summary records which key was used.

### Creating a keystore (once, and keep it safe forever)

```bash
keytool -genkeypair -v -keystore lrms-release.jks \
  -alias lrms -keyalg RSA -keysize 2048 -validity 10000
```

Losing this file means you can never update an already-installed app. Back it up
outside the repository.

### Local release signing

`android/keystore.properties` (git-ignored):

```properties
LRMS_KEYSTORE_FILE=/absolute/path/lrms-release.jks
LRMS_KEYSTORE_PASSWORD=…
LRMS_KEY_ALIAS=lrms
LRMS_KEY_PASSWORD=…
```

### CI signing — GitHub Actions Secrets

```bash
base64 -w0 lrms-release.jks > keystore.b64    # macOS: base64 -i lrms-release.jks
```

Add under **Settings ▸ Secrets and variables ▸ Actions**:

| Secret | Value |
| --- | --- |
| `KEYSTORE_BASE64` | contents of `keystore.b64` |
| `KEYSTORE_PASSWORD` | keystore password |
| `KEY_ALIAS` | key alias (e.g. `lrms`) |
| `KEY_PASSWORD` | key password |

And one **variable** (not a secret — it is not sensitive and is easier to see):

| Variable | Value |
| --- | --- |
| `LRMS_API_URL` | `https://your-server/api/v1/` |

The workflow decodes the keystore into `$RUNNER_TEMP`, uses it, and **shreds it in
an `if: always()` step** so a decoded key is never left on the runner even when
the build fails.

---

## The pipeline

[`.github/workflows/android-build.yml`](../.github/workflows/android-build.yml)

| Trigger | What runs |
| --- | --- |
| pull request touching `android/**` | `verify` only — unit tests, `lintDebug`, `assembleDebug` |
| push to `main` | `verify`, then signed `assembleRelease` + `bundleRelease` + `lintRelease` |
| tag `v*` | the above, plus a **GitHub Release** with the artifacts attached |
| **Run workflow** (manual) | same as a push; optional `api_url` and `version_name` inputs |

Two jobs, and `release` `needs: verify` — nothing is published unless the tests
and lint pass. `lint` runs with `abortOnError = true` and
`warningsAsErrors = false`: real errors fail the build, style warnings do not.

After building, the pipeline runs `apksigner verify --verbose` on the APK, so a
mis-supplied password is caught in CI rather than on a supervisor's phone.

### Versioning

| Trigger | `versionName` | `versionCode` |
| --- | --- | --- |
| tag `v1.2.3` | `1.2.3` | workflow run number |
| manual with `version_name` | that value | run number |
| otherwise | `1.0.<run number>` | run number |

Using the run number for `versionCode` guarantees it only ever increases, which
the Play Console requires.

### Artifacts

Downloaded from the run page, or attached to the Release for a tag:

| Artifact | Purpose |
| --- | --- |
| `LRMS-v1.2.3-release.apk` | sideload / direct install |
| `LRMS-v1.2.3-release.aab` | Play Console upload |
| `LRMS-v1.2.3-mapping.txt` | de-obfuscate R8 crash reports — **keep this for every release** |
| `LRMS-v1.2.3-checksums.txt` | SHA-256 of each file |
| `android-reports` | test and lint HTML/XML reports (also on failure) |

### Cutting a release

```bash
git tag v1.0.0
git push origin v1.0.0
```

Then check **Actions ▸ Android build**, and the Release under **Releases**.

---

## How the app works

Worth knowing before changing it.

**Offline-first.** Room is the source of truth for the UI. Field work is written
locally and queued in an `outbox` table; nothing blocks on the network.
`SyncWorker` (WorkManager) drains it every **15 minutes** when a connection is
available, with exponential backoff, plus an immediate one-shot run after each
save and on manual sync. Entities: `accounts`, `visits`, `visit_photos`,
`outbox`, `notifications`, `form_fields`, `attendance`.

**Idempotent replay.** Every queued row carries a client-generated UUID with a
unique index. The server reports a repeat as `duplicate`, so a retry after a
timeout can never double-count a recovery. Items the server rejects as
non-retryable are surfaced to the supervisor instead of retried forever.

**Credentials.** The bearer token, device UUID and profile live in
EncryptedSharedPreferences (AES256-SIV keys, AES256-GCM values). An OkHttp
interceptor attaches `Authorization` and `X-Device-Id` to every call. A `401`
clears the session and returns to sign-in — but queued work is first put back in
the outbox untouched, so re-authenticating resumes the sync rather than losing a
day's visits.

**Location.** Platform `LocationManager`, not Play Services — no Google
dependency. GPS is mandatory to start a visit; accuracy, mock-location and `0,0`
are rejected by the server as well as the app. Positions are reported when the app
talks to the server: this is **last-known reporting, not continuous tracking**, and
the app does not claim otherwise when offline or when permission is denied.

**Photographs.** `ACTION_IMAGE_CAPTURE` through a `FileProvider` into app-private
storage (no external-storage permission, and more reliable across cheap OEM camera
apps than CameraX). The server re-encodes, strips EXIF, watermarks with name,
timestamp and coordinates, and hashes for duplicate detection.

**Dynamic forms.** The visit form is fetched from the server and rendered from its
definition, including conditional fields — an Admin/Supervisor can change the form
without shipping a new APK. `FormLogic.kt` mirrors the server's validation and
visibility rules, so the supervisor is told about a problem while still standing
with the customer.

**Deadline countdown.** Seeded from the server's `seconds_remaining` and advanced
with `SystemClock.elapsedRealtime()` (monotonic). Changing the device clock buys
no extra minutes.

**DI.** A hand-written `ServiceLocator` in `LrmsApp.kt` — no Hilt, so there is one
fewer annotation processor and a faster build. Moshi uses reflection rather than
codegen for the same reason; R8 keep rules are in
[`proguard-rules.pro`](../android/app/proguard-rules.pro).

---

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| Gradle fails with a Java version error such as `25.0.2` | Wrong JDK. `export JAVA_HOME=…/jdk-17` |
| `SDK location not found` | Create `android/local.properties` with `sdk.dir=…`, or set `ANDROID_HOME` |
| App reports "server unreachable" on the emulator | Use `10.0.2.2`, not `localhost`; the host must be serving on that port |
| Release build installs but is rejected by the Play Console | It was signed with the debug fallback — the signing secrets are missing |
| `INSTALL_FAILED_UPDATE_INCOMPATIBLE` | A build signed with a different key is installed; uninstall first |
| Cleartext HTTP fails in staging/release | By design. Serve the API over HTTPS |
| Crash report is unreadable | Retrieve `LRMS-<version>-mapping.txt` from that release's artifacts |
| `-PlrmsApiUrl` seems ignored | It needs the full `/api/v1/` path. Note `lrmsDebugApiUrl` in `gradle.properties` affects the debug build only, by design |
| `Refusing to build 'release' with the non-HTTPS API URL …` | Working as intended — supply an `https://` URL |

## Not included

- **Instrumentation (UI) tests.** The 25 unit tests cover the offline form rules,
  conditional visibility (including the `contains` operator used by the
  verification report checklists) and money/date formatting; the server contract
  is covered by `tests/api-smoke.php` against a real server. There is no Espresso
  suite and the pipeline runs no emulator.
- **Push notifications.** The `fcm_token` field is sent at sign-in and stored, but
  no Firebase dependency is wired up; notifications are delivered on sync.
