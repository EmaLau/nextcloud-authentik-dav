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

# Helpers for the Authentik API (blueprint options, groups, reputation scores).
AKAPI=http://127.0.0.1:9088/api/v3
ak() { python3 - "$AKAPI" "$AK_TOKEN" "$@" <<'PY'
import json, sys, time, urllib.parse, urllib.request
api, token, action, args = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4:]
def call(method, path, body=None):
    req = urllib.request.Request(api + path, method=method, data=None if body is None else json.dumps(body).encode(),
                                 headers={"Authorization": "Bearer " + token, "Content-Type": "application/json"})
    with urllib.request.urlopen(req) as r:
        raw = r.read()
        return json.loads(raw) if raw else {}
def flow_bindings():
    flow = call("GET", "/flows/instances/nextcloud-webdav-check/")["pk"]
    return call("GET", "/flows/bindings/?target=%s" % flow)["results"]
def drop_instance():
    for b in call("GET", "/managed/blueprints/?name=e2e%20options")["results"]:
        call("DELETE", "/managed/blueprints/%s/" % b["pk"])
if action == "apply":            # apply <context JSON> <order of the binding to wait for>
    drop_instance()
    content = open("../authentik/nextcloud-webdav-check.yaml").read()
    bp = call("POST", "/managed/blueprints/", {"name": "e2e options", "content": content, "context": json.loads(args[0]), "enabled": True})
    call("POST", "/managed/blueprints/%s/apply/" % bp["pk"])
    for _ in range(60):          # the worker applies blueprints asynchronously
        if any(b["order"] == int(args[1]) for b in flow_bindings()):
            break
        time.sleep(1)
    else:
        sys.exit("the stage binding at order %s did not appear" % args[1])
elif action == "unbind":         # unbind <order>: switch an option off again
    for b in flow_bindings():
        if b["order"] == int(args[0]):
            call("DELETE", "/flows/bindings/%s/" % b["pk"])
    drop_instance()
elif action == "clear":          # clear <username>: forget reputation scores
    for s in call("GET", "/policies/reputation/scores/?identifier=" + args[0])["results"]:
        call("DELETE", "/policies/reputation/scores/%s/" % s["pk"])
elif action == "ips":            # ips <username>: addresses of the reputation scores
    print(" ".join(sorted({s["ip"] for s in call("GET", "/policies/reputation/scores/?identifier=" + args[0])["results"]})))
elif action == "group":          # group <name> <member>: create a group with one member
    user = call("GET", "/core/users/?username=" + args[1])["results"][0]["pk"]
    call("POST", "/core/groups/", {"name": args[0], "users": [user]})
elif action == "ungroup":        # ungroup <name>
    for g in call("GET", "/core/groups/?name=" + urllib.parse.quote(args[0]))["results"]:
        call("DELETE", "/core/groups/%s/" % g["pk"])
PY
}
last_reason() { nclog | grep "login failed for user '$1'" | tail -1 | sed "s/.*login failed for user '$1' from '[^']*': //; s/\".*//"; }
SET cache_ttl 0

# Optional limit on failed attempts (blueprint context rate_limit: true).
ak clear alice
ak apply '{"rate_limit": true}' 15
for i in 1 2 3 4 5; do dav alice wrong-password >/dev/null; done
expect "rate limit: alice refused after 5 failures, even with the right password" 401 "$(dav alice "$TEST_USER_PASS")"
case "$(last_reason alice)" in "Authentik denied access to this user: Too many failed attempts"*) echo "ok    rate limit: reason in the Nextcloud log";; *) echo "FAIL  rate limit: reason is '$(last_reason alice)'"; fails=$((fails + 1));; esac
dav bob "$TEST_USER_PASS" >/dev/null
case "$(last_reason bob)" in *"has MFA"*) echo "ok    rate limit: other users are not affected";; *) echo "FAIL  rate limit: bob refused because '$(last_reason bob)'"; fails=$((fails + 1));; esac
ak unbind 15; ak clear alice
expect "rate limit switched off again: alice accepted" 207 "$(dav alice "$TEST_USER_PASS")"

# Optional Authentik group (blueprint context required_group).
ak group webdav-authentik alice
ak apply '{"required_group": "webdav-authentik"}' 16
expect "Authentik group: alice (member) accepted" 207 "$(dav alice "$TEST_USER_PASS")"
dav bob "$TEST_USER_PASS" >/dev/null
case "$(last_reason bob)" in "Authentik denied access to this user: This account may not use"*) echo "ok    Authentik group: bob (not a member) refused before his password is checked";; *) echo "FAIL  Authentik group: bob refused because '$(last_reason bob)'"; fails=$((fails + 1));; esac
ak unbind 16; ak ungroup webdav-authentik

# Client address passed on to Authentik (forward_client_ip).
ak clear alice
SET forward_client_ip yes
dav alice wrong-password >/dev/null
client_ip=$(nclog | grep "login failed for user 'alice'" | tail -1 | sed "s/.*from '\([^']*\)'.*/\1/")
case "${client_ip:-none} $(ak ips alice) " in none*) echo "FAIL  forward_client_ip: no client address in the log"; fails=$((fails + 1));; *" $client_ip "*|*" ::ffff:$client_ip "*) echo "ok    forward_client_ip: Authentik saw the client address ($client_ip)";; *) echo "FAIL  forward_client_ip: Authentik saw '$(ak ips alice)', client was $client_ip"; fails=$((fails + 1));; esac
SET forward_client_ip no
ak clear alice

# Personal setting: a user switches the Authentik password off for their own account.
OCC user:setting alice authentik_dav disabled yes
expect "personal setting off: alice refused" 401 "$(dav alice "$TEST_USER_PASS")"
expect "personal setting off: reason in the log" "the user switched it off in their personal settings" "$(last_reason alice)"
OCC user:setting alice authentik_dav disabled no
expect "personal setting on again: alice accepted" 207 "$(dav alice "$TEST_USER_PASS")"

# occ authentik_dav:test
occtest() { $DC exec -T -u www-data -e AUTHENTIK_DAV_PASSWORD="$2" nextcloud php occ authentik_dav:test "$1" --password-from-env 2>&1; }
out=$(occtest alice "$TEST_USER_PASS"); expect "occ test: alice accepted" 1 "$(echo "$out" | grep -c 'is accepted')"
out=$(occtest alice wrong-password); expect "occ test: wrong password explained" 1 "$(echo "$out" | grep -c 'Refused: wrong password')"
out=$(occtest bob "$TEST_USER_PASS"); expect "occ test: MFA explained" 1 "$(echo "$out" | grep -c 'has MFA')"
out=$(occtest nobody x); expect "occ test: unknown account explained" 1 "$(echo "$out" | grep -c 'no Nextcloud account')"
if echo "$(occtest alice "$TEST_USER_PASS")$(occtest alice wrong-password)" | grep -q -e "$TEST_USER_PASS" -e wrong-password; then echo "FAIL  occ test printed a password"; fails=$((fails + 1)); else echo "ok    occ test never prints the password"; fi
SET cache_ttl 60

[ "$fails" -eq 0 ] && echo "All end-to-end checks passed." || { echo "$fails check(s) failed."; exit 1; }
