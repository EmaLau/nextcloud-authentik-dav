#!/bin/bash
# Prepares the local development environment started with dev/compose.yaml:
#   - copies the app into Nextcloud's custom_apps (run this script again after changing the code)
#   - applies the Authentik blueprint (authentik/nextcloud-webdav-check.yaml)
#   - creates test users in Authentik and Nextcloud: alice (no MFA), bob (MFA), carol (not in the "webdav" group)
#   - enables the app and points it to the local Authentik
# Test users get TEST_USER_PASS from dev/.env in Authentik and a different random password in Nextcloud,
# so a successful WebDAV login can only come from the app.
set -euo pipefail
cd "$(dirname "$0")"
set -a; . ./.env; set +a
DC="docker compose -f compose.yaml --env-file .env"
OCC() { $DC exec -T -u www-data nextcloud php occ "$@"; }

echo "Copying the app into custom_apps..."
$DC exec -T -u 0 nextcloud sh -c 'rm -rf /var/www/html/custom_apps/authentik_dav && cp -r /opt/authentik_dav /var/www/html/custom_apps/authentik_dav && chown -R www-data:www-data /var/www/html/custom_apps/authentik_dav'

echo "Applying the blueprint..."
$DC exec -T authentik-worker ak apply_blueprint /blueprints/custom/nextcloud-webdav-check.yaml >/dev/null 2>&1 || { echo 'blueprint failed'; exit 1; }

echo "Creating Authentik test users..."
$DC exec -T -e TEST_USER_PASS="$TEST_USER_PASS" authentik-worker ak shell -c "
import os
from authentik.core.models import User
from authentik.stages.authenticator_static.models import StaticDevice
for name in ['alice', 'bob', 'carol']:
    u, _ = User.objects.get_or_create(username=name, defaults={'name': name.title(), 'path': 'users'})
    u.set_password(os.environ['TEST_USER_PASS']); u.is_active = True; u.save()
bob = User.objects.get(username='bob')
StaticDevice.objects.get_or_create(user=bob, name='test-mfa', defaults={'confirmed': True})
print('authentik users ready')
" 2>&1 | grep -E 'ready|Error' || true

echo "Creating Nextcloud test users..."
OCC group:add webdav >/dev/null 2>&1 || true
for name in alice bob carol; do
  if ! OCC user:info "$name" >/dev/null 2>&1; then
    $DC exec -T -u www-data -e OC_PASS="$(openssl rand -hex 24)" nextcloud php occ user:add --password-from-env "$name" >/dev/null
  fi
done
OCC group:adduser webdav alice; OCC group:adduser webdav bob

echo "Configuring the app..."
OCC app:enable authentik_dav
OCC config:app:set authentik_dav base_url --value=http://authentik-server:9000 >/dev/null
OCC config:app:set authentik_dav flow_slug --value=nextcloud-webdav-check >/dev/null
OCC config:app:set authentik_dav allow_http --value=yes >/dev/null
OCC config:app:set authentik_dav allow_local_address --value=yes >/dev/null
OCC config:app:set authentik_dav allowed_groups --value='["webdav"]' >/dev/null
# The end-to-end test makes many failed logins on purpose: switch off Nextcloud's own brute-force
# throttling in this throw-away environment only (it answers 429 after repeated failures).
OCC config:system:set auth.bruteforce.protection.enabled --type=boolean --value=false >/dev/null
OCC config:system:set memcache.local --value='\OC\Memcache\APCu' >/dev/null || true
echo "Done. Nextcloud: http://localhost:8088 (admin / NC_ADMIN_PASS) · Authentik: http://localhost:9088 (akadmin / AK_ADMIN_PASS)"
