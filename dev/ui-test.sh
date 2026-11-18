#!/bin/bash
# Runs dev/ui-test.mjs (Playwright, Chromium) in a container attached to the environment's Docker network.
set -euo pipefail
cd "$(dirname "$0")"
set -a; . ./.env; set +a
PW=1.49.1
NET="${COMPOSE_PROJECT_NAME:-authentik-dav-dev}_default"
docker run --rm --network "$NET" -e NC_ADMIN_PASS -e TEST_USER_PASS -v "$PWD/ui-test.mjs:/work/ui-test.mjs:ro" -w /work \
  "mcr.microsoft.com/playwright:v$PW-noble" \
  sh -c "npm init -y >/dev/null 2>&1 && npm install --silent --no-audit --no-fund playwright@$PW >/dev/null 2>&1 && node ui-test.mjs"
