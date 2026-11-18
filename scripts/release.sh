#!/bin/bash
# Builds a release archive of the app for GitHub and the Nextcloud App Store.
#   scripts/release.sh                       -> dist/authentik_dav-<version>.tar.gz
#   NC_CONTAINER=<container> scripts/release.sh
#                                            -> also signs the code (appinfo/signature.json) with
#                                               "occ integrity:sign-app" inside that Nextcloud container
# Needs, outside the repository: ~/.nextcloud/certificates/authentik_dav.key (private key, never committed)
# and, for code signing, authentik_dav.crt issued by https://github.com/nextcloud/app-certificate-requests.
# The App Store signature of the archive is printed at the end ("Upload app release" form).
set -euo pipefail
export COPYFILE_DISABLE=1
APP=authentik_dav
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CERTS="${NC_CERTS:-$HOME/.nextcloud/certificates}"
KEY="$CERTS/$APP.key"; CRT="$CERTS/$APP.crt"
VER=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$ROOT/$APP/appinfo/info.xml")
OUT="$ROOT/dist/$APP-$VER.tar.gz"

STAGE=$(mktemp -d); trap 'rm -rf "$STAGE"' EXIT
mkdir "$STAGE/$APP"
tar --no-xattrs -C "$ROOT/$APP" --exclude .DS_Store --exclude '._*' -cf - . | tar -xf - -C "$STAGE/$APP"

if [ -n "${NC_CONTAINER:-}" ] && [ -f "$CRT" ] && [ -f "$KEY" ]; then
  T=/tmp/$APP-sign
  docker cp "$STAGE/$APP" "$NC_CONTAINER:$T"
  docker cp "$KEY" "$NC_CONTAINER:$T.key"; docker cp "$CRT" "$NC_CONTAINER:$T.crt"
  docker exec -u 0 "$NC_CONTAINER" chown -R www-data:www-data "$T" "$T.key" "$T.crt"
  docker exec -u www-data "$NC_CONTAINER" php /var/www/html/occ integrity:sign-app --path="$T" --privateKey="$T.key" --certificate="$T.crt"
  docker cp "$NC_CONTAINER:$T/appinfo/signature.json" "$STAGE/$APP/appinfo/signature.json"
  docker exec -u 0 "$NC_CONTAINER" rm -rf "$T" "$T.key" "$T.crt"
  echo "Code signed: appinfo/signature.json"
else
  echo "(code signing skipped: needs NC_CONTAINER, $KEY and $CRT)"
fi

mkdir -p "$ROOT/dist"
tar --no-xattrs -C "$STAGE" -czf "$OUT" "$APP"
echo "Archive: $OUT ($(wc -c < "$OUT") bytes)"
tar -tzf "$OUT" | sed 's/^/  /'
if [ -f "$KEY" ]; then
  echo "App Store signature:"
  openssl dgst -sha512 -sign "$KEY" "$OUT" | openssl base64 -A; echo
fi
