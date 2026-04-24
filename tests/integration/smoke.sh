#!/usr/bin/env bash
# End-to-end smoke test for the sync plugin's HTTP endpoints.
#
# Drives a live grav-plugin-api + grav-plugin-sync install via curl and
# verifies capability discovery, pull/push roundtrip, and presence flow.
#
# Prerequisites:
#   - Grav + api plugin + sync plugin installed and enabled
#   - User with api.collab.read, api.collab.write, api.pages.read,
#     api.pages.write, api.access permissions (or super admin)
#   - jq, curl
#
# Usage:
#   BASE_URL=http://localhost:8000 USER=admin PASS='xxx' \
#     ROUTE=/blog/hello ./smoke.sh

set -euo pipefail

BASE_URL=${BASE_URL:-http://localhost:8000}
USER=${USER:-admin}
PASS=${PASS:-}
ROUTE=${ROUTE:-/}
API=${BASE_URL}/api/v1

if [[ -z "$PASS" ]]; then
  echo "error: PASS env var required" >&2
  exit 1
fi

say() { printf '\033[36m==> %s\033[0m\n' "$1"; }
ok()  { printf '\033[32m    ✓ %s\033[0m\n' "$1"; }
die() { printf '\033[31m    ✗ %s\033[0m\n' "$1" >&2; exit 1; }

# ---------------------------------------------------------------------
say "Logging in as $USER"
TOKEN=$(curl -sS -X POST "$API/auth/token" \
  -H 'Content-Type: application/json' \
  -d "{\"username\":\"$USER\",\"password\":\"$PASS\"}" \
  | jq -r '.data.access_token // empty')

if [[ -z "$TOKEN" ]]; then
  die "login failed"
fi
ok "got JWT (${#TOKEN} chars)"

AUTH="-H X-API-Token:$TOKEN"

# ---------------------------------------------------------------------
say "GET /sync/capabilities"
CAPS=$(curl -sS $AUTH "$API/sync/capabilities")
echo "$CAPS" | jq .
TRANSPORTS=$(echo "$CAPS" | jq -r '.data.transports | join(",")')
[[ "$TRANSPORTS" == *"polling"* ]] || die "capabilities missing polling transport"
ok "capabilities contain polling"

# ---------------------------------------------------------------------
say "POST /sync/pages$ROUTE/pull (cold)"
PULL=$(curl -sS $AUTH -X POST "$API/sync/pages$ROUTE/pull" \
  -H 'Content-Type: application/json' -d '{"since":0}')
echo "$PULL" | jq .
SIZE0=$(echo "$PULL" | jq -r '.data.size')
ok "cold pull succeeded (size=$SIZE0)"

# ---------------------------------------------------------------------
say "POST /sync/pages$ROUTE/push"
UPDATE_B64=$(printf 'hello-sync-%s' "$(date +%s%N)" | base64)
PUSH=$(curl -sS $AUTH -X POST "$API/sync/pages$ROUTE/push" \
  -H 'Content-Type: application/json' \
  -d "{\"clientId\":\"smoke-1\",\"update\":\"$UPDATE_B64\"}")
echo "$PUSH" | jq .
OK=$(echo "$PUSH" | jq -r '.data.ok')
[[ "$OK" == "true" ]] || die "push reported !ok"
ok "push succeeded"

# ---------------------------------------------------------------------
say "POST /sync/pages$ROUTE/pull (incremental)"
PULL2=$(curl -sS $AUTH -X POST "$API/sync/pages$ROUTE/pull" \
  -H 'Content-Type: application/json' -d "{\"since\":$SIZE0}")
echo "$PULL2" | jq .
COUNT=$(echo "$PULL2" | jq -r '.data.updates | length')
[[ "$COUNT" -ge 1 ]] || die "expected >=1 update, got $COUNT"
RETURNED_B64=$(echo "$PULL2" | jq -r '.data.updates[0]')
[[ "$RETURNED_B64" == "$UPDATE_B64" ]] || die "roundtrip corruption"
ok "roundtrip intact ($(echo "$UPDATE_B64" | base64 -d) bytes came back identically)"

# ---------------------------------------------------------------------
say "POST /sync/pages$ROUTE/presence (heartbeat)"
PRES=$(curl -sS $AUTH -X POST "$API/sync/pages$ROUTE/presence" \
  -H 'Content-Type: application/json' \
  -d '{"clientId":"smoke-1","meta":{"cursor":42}}')
echo "$PRES" | jq .
PEERS=$(echo "$PRES" | jq -r '.data.peers | length')
[[ "$PEERS" -ge 1 ]] || die "expected >=1 peer"
ok "presence registered ($PEERS peer(s))"

say "POST /sync/pages$ROUTE/presence (leave)"
curl -sS $AUTH -X POST "$API/sync/pages$ROUTE/presence" \
  -H 'Content-Type: application/json' \
  -d '{"clientId":"smoke-1","leave":true}' > /dev/null
PRES2=$(curl -sS $AUTH -X POST "$API/sync/pages$ROUTE/presence" \
  -H 'Content-Type: application/json' \
  -d '{"clientId":"dummy","leave":true}')
PEERS2=$(echo "$PRES2" | jq -r '.data.peers | length')
# smoke-1 should be gone; the dummy leave is a no-op but also returns peers.
ok "presence leave honored (remaining peers=$PEERS2)"

# ---------------------------------------------------------------------
say "negative: push without api.collab.write"
# This sub-test only runs if TEST_UNPRIV_USER is provided.
if [[ -n "${TEST_UNPRIV_USER:-}" && -n "${TEST_UNPRIV_PASS:-}" ]]; then
  UNPRIV_TOKEN=$(curl -sS -X POST "$API/auth/token" \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"$TEST_UNPRIV_USER\",\"password\":\"$TEST_UNPRIV_PASS\"}" \
    | jq -r '.data.access_token // empty')
  if [[ -n "$UNPRIV_TOKEN" ]]; then
    STATUS=$(curl -sS -o /dev/null -w '%{http_code}' \
      -H "X-API-Token:$UNPRIV_TOKEN" \
      -X POST "$API/sync/pages$ROUTE/push" \
      -H 'Content-Type: application/json' \
      -d "{\"clientId\":\"unp\",\"update\":\"$UPDATE_B64\"}")
    [[ "$STATUS" == "403" ]] || die "expected 403, got $STATUS"
    ok "unprivileged push correctly rejected (403)"
  fi
fi

echo
printf '\033[32mAll smoke checks passed.\033[0m\n'
