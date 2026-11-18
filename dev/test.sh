#!/bin/bash
# End-to-end test against the local environment (dev/compose.yaml + dev/setup.sh):
# real Authentik, real Nextcloud, real WebDAV requests.
set -uo pipefail
cd "$(dirname "$0")"
set -a; . ./.env; set +a
DC="docker compose -f compose.yaml --env-file .env"
OCC() { $DC exec -T -u www-data nextcloud php occ "$@"; }
# Nextcloud keeps app settings in a local memory cache for 3 seconds: wait after every change.
SET() { OCC config:app:set authentik_dav "$1" --value="$2" >/dev/null; sleep 4; }
NC=http://127.0.0.1:8088
fails=0

expect() { # expect <description> <wanted> <got>
	if [ "$2" = "$3" ]; then echo "ok    $1"; else echo "FAIL  $1: wanted $2, got $3"; fails=$((fails + 1)); fi
}
dav() { curl -s -o /dev/null -w "%{http_code}" -m 30 -X PROPFIND -H "Depth: 0" -u "$1:$2" "$NC/remote.php/dav/files/$1/"; }
logged() { OCC log:tail 200 2>/dev/null | grep -c "$1" || true; }
setupcheck() {
	OCC setupchecks --output=json 2>/dev/null | python3 -c 'import sys,json; print(json.load(sys.stdin)["security"]["OCA\\AuthentikDav\\SetupCheck\\AuthentikConnection"]["severity"])'
}

# Known starting state (every request reaches Authentik: no password cache).
OCC config:app:set authentik_dav allowed_groups --value='["webdav"]' >/dev/null
OCC config:app:set authentik_dav flow_slug --value=nextcloud-webdav-check >/dev/null
OCC config:app:set authentik_dav allow_http --value=yes >/dev/null
SET cache_ttl 0

expect "alice (no MFA, allowed group) with the Authentik password" 207 "$(dav alice "$TEST_USER_PASS")"
expect "alice with a wrong password" 401 "$(dav alice wrong-password)"
expect "bob (MFA in Authentik)" 401 "$(dav bob "$TEST_USER_PASS")"
expect "carol (not in an allowed group)" 401 "$(dav carol "$TEST_USER_PASS")"
expect "admin (administrators excluded)" 401 "$(dav admin "$TEST_USER_PASS")"
expect "unknown user" 401 "$(dav nobody "$TEST_USER_PASS")"

SET allowed_groups ""
expect "carol once every account is allowed" 207 "$(dav carol "$TEST_USER_PASS")"
SET allowed_groups '["webdav"]'

SET allow_http no
expect "plain HTTP refused without allow_http" 401 "$(dav alice "$TEST_USER_PASS")"
SET allow_http yes

if OCC log:tail 200 2>/dev/null | grep -q "$TEST_USER_PASS"; then
	echo "FAIL  a password appears in the Nextcloud log"; fails=$((fails + 1))
else
	echo "ok    no password in the Nextcloud log"
fi

expect "setup check with the blueprint flow" success "$(setupcheck)"
SET flow_slug missing-flow
expect "setup check with a missing flow" error "$(setupcheck)"
SET flow_slug default-authentication-flow
expect "setup check with the web login flow (accepts email)" warning "$(setupcheck)"
SET flow_slug nextcloud-webdav-check
SET cache_ttl 60

[ "$fails" -eq 0 ] && echo "All end-to-end checks passed." || { echo "$fails check(s) failed."; exit 1; }
