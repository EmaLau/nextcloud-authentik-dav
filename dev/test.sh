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
# The raw Nextcloud log (occ log:tail truncates long messages).
nclog() { $DC exec -T -u www-data nextcloud sh -c 'tail -n 300 /var/www/html/data/nextcloud.log'; }
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

if nclog | grep -q "$TEST_USER_PASS"; then
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

# Optional limit on failed attempts (blueprint context rate_limit: true).
AKAPI=http://127.0.0.1:9088/api/v3
ak() { python3 - "$AKAPI" "$AK_TOKEN" "$@" <<'PY'
import json, sys, urllib.request
api, token, action = sys.argv[1], sys.argv[2], sys.argv[3]
def call(method, path, body=None):
    req = urllib.request.Request(api + path, method=method, data=None if body is None else json.dumps(body).encode(),
                                 headers={"Authorization": "Bearer " + token, "Content-Type": "application/json"})
    with urllib.request.urlopen(req) as r:
        raw = r.read()
        return json.loads(raw) if raw else {}
def clear_reputation(user):
    for s in call("GET", "/policies/reputation/scores/?identifier=" + user)["results"]:
        call("DELETE", "/policies/reputation/scores/%s/" % s["pk"])
def drop_instance():
    for b in call("GET", "/managed/blueprints/?name=e2e%20rate%20limit")["results"]:
        call("DELETE", "/managed/blueprints/%s/" % b["pk"])
if action == "enable":
    clear_reputation("alice")
    drop_instance()
    content = open("../authentik/nextcloud-webdav-check.yaml").read()
    bp = call("POST", "/managed/blueprints/", {"name": "e2e rate limit", "content": content, "context": {"rate_limit": True}, "enabled": True})
    call("POST", "/managed/blueprints/%s/apply/" % bp["pk"])
    # The worker applies blueprints asynchronously: wait for the deny stage binding (order 15).
    import time
    flow = call("GET", "/flows/instances/nextcloud-webdav-check/")["pk"]
    for _ in range(60):
        if any(b["order"] == 15 for b in call("GET", "/flows/bindings/?target=%s" % flow)["results"]):
            break
        time.sleep(1)
    else:
        sys.exit("the deny stage binding did not appear")
elif action == "disable":
    flow = call("GET", "/flows/instances/nextcloud-webdav-check/")["pk"]
    for b in call("GET", "/flows/bindings/?target=%s" % flow)["results"]:
        if b["order"] == 15:
            call("DELETE", "/flows/bindings/%s/" % b["pk"])
    drop_instance()
    clear_reputation("alice")
PY
}
SET cache_ttl 0
ak enable >/dev/null
for i in 1 2 3 4 5; do dav alice wrong-password >/dev/null; done
expect "rate limit: alice refused after 5 failures, even with the right password" 401 "$(dav alice "$TEST_USER_PASS")"
if nclog | grep -q "user 'alice'.*Authentik denied access to this user"; then echo "ok    rate limit: reason in the Nextcloud log"; else echo "FAIL  rate limit: reason not in the log"; fails=$((fails + 1)); fi
dav bob "$TEST_USER_PASS" >/dev/null
if nclog | grep "user 'bob'" | tail -1 | grep -q "has MFA"; then echo "ok    rate limit: other users are not affected"; else echo "FAIL  rate limit: bob refused for another reason"; fails=$((fails + 1)); fi
ak disable
expect "rate limit switched off again: alice accepted" 207 "$(dav alice "$TEST_USER_PASS")"
SET cache_ttl 60

[ "$fails" -eq 0 ] && echo "All end-to-end checks passed." || { echo "$fails check(s) failed."; exit 1; }
