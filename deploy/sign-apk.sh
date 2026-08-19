#!/usr/bin/env bash
#
# Sign a release APK with the LRMS release keystore.
#
# WHY THIS EXISTS
#
# CI signs automatically once the four signing secrets are set on the repository
# (see KEYSTORE-SETUP.md). Until then it falls back to Android's debug key, and a
# debug-signed APK cannot be installed over one signed with a different key — which
# is why the app has had to be uninstalled before each new build.
#
# This takes the APK that CI produced and re-signs it with the real key, so the
# build can be handed out today and every later build signed with the same key
# installs straight over it.
#
# It needs no Android SDK: uber-apk-signer bundles zipalign and apksigner, and is
# fetched on first use.
#
# USAGE
#   deploy/sign-apk.sh LRMS-v1.3.2-release.apk /path/to/lrms-release.jks
#
# The keystore password is read from LRMS_KEYSTORE_PASSWORD, or prompted for. It is
# never written to disk or echoed, and never committed: the keystore and its
# password do not belong in this repository.

set -euo pipefail

APK="${1:-}"
KEYSTORE="${2:-}"
ALIAS="${LRMS_KEY_ALIAS:-lrms}"
SIGNER_VERSION="1.3.0"
SIGNER_JAR="${TMPDIR:-/tmp}/uber-apk-signer-${SIGNER_VERSION}.jar"

if [[ -z "$APK" || -z "$KEYSTORE" ]]; then
    echo "usage: $0 <release.apk> <keystore.jks>" >&2
    exit 64
fi

for path in "$APK" "$KEYSTORE"; do
    if [[ ! -f "$path" ]]; then
        echo "error: $path does not exist." >&2
        exit 66
    fi
done

if ! command -v java >/dev/null 2>&1; then
    echo "error: java is not on PATH. A JDK 17 is enough." >&2
    exit 69
fi

if [[ -z "${LRMS_KEYSTORE_PASSWORD:-}" ]]; then
    read -rsp "Keystore password: " LRMS_KEYSTORE_PASSWORD
    echo
fi

if [[ ! -f "$SIGNER_JAR" ]]; then
    echo "Fetching uber-apk-signer ${SIGNER_VERSION}…"
    curl -fsSL \
        "https://github.com/patrickfav/uber-apk-signer/releases/download/v${SIGNER_VERSION}/uber-apk-signer-${SIGNER_VERSION}.jar" \
        -o "$SIGNER_JAR"
fi

OUT="$(dirname "$APK")/signed"
mkdir -p "$OUT"

# --allowResign because the input already carries the debug signature; apksigner
# replaces it rather than adding a second one.
java -jar "$SIGNER_JAR" \
    -a "$APK" \
    --ks "$KEYSTORE" \
    --ksAlias "$ALIAS" \
    --ksPass "$LRMS_KEYSTORE_PASSWORD" \
    --ksKeyPass "$LRMS_KEYSTORE_PASSWORD" \
    --allowResign \
    -o "$OUT"

SIGNED="$(find "$OUT" -name '*-signed.apk' -newer "$APK" -print -quit)"

if [[ -z "$SIGNED" ]]; then
    echo "error: signing produced no output." >&2
    exit 70
fi

echo
echo "Verifying what will actually be installed:"
java -jar "$SIGNER_JAR" -y -a "$SIGNED" | grep -Ei 'verified|Subject|SHA256|Expires' || true

echo
echo "Signed APK: $SIGNED"
echo
echo "Check the SHA-256 above matches the fingerprint in KEYSTORE-SETUP.md. If it"
echo "does not, this was signed with a different key and it will not install over"
echo "the previous build."
