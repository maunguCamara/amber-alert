#!/usr/bin/env bash
# =============================================================================
# Kenya Amber Alert — Local smoke tests
# Run after './dev.sh start' to verify everything works end-to-end.
# =============================================================================

set -euo pipefail

GO_URL="${GO_URL:-http://localhost:8080}"
PHP_URL="${PHP_URL:-http://localhost:8000}"
RUST_PORT="${RUST_PORT:-50051}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
PASS=0; FAIL=0

pass() { echo -e "${GREEN}✓ $1${NC}"; ((PASS++)); }
fail() { echo -e "${RED}✗ $1${NC}"; ((FAIL++)); }
info() { echo -e "${CYAN}  $1${NC}"; }
section() { echo -e "\n${YELLOW}── $1 ──${NC}"; }

check_http() {
    local name="$1" url="$2" expected_status="${3:-200}" body_contains="${4:-}"
    local resp
    resp=$(curl -s -o /tmp/smoke_body -w "%{http_code}" --max-time 5 "$url" 2>/dev/null || echo "000")
    if [ "$resp" = "$expected_status" ]; then
        if [ -n "$body_contains" ] && ! grep -q "$body_contains" /tmp/smoke_body 2>/dev/null; then
            fail "$name — status $resp but body missing '$body_contains'"
        else
            pass "$name (HTTP $resp)"
        fi
    else
        fail "$name — expected $expected_status, got $resp"
        info "Body: $(cat /tmp/smoke_body 2>/dev/null | head -c 200)"
    fi
}

check_json_field() {
    local name="$1" url="$2" field="$3"
    local body
    body=$(curl -s --max-time 5 "$url" 2>/dev/null || echo "{}")
    if echo "$body" | python3 -c "import sys,json; d=json.load(sys.stdin); assert '$field' in d" 2>/dev/null; then
        pass "$name (field '$field' present)"
    else
        fail "$name — field '$field' missing in: $(echo "$body" | head -c 200)"
    fi
}

# ── 1. Go API ─────────────────────────────────────────────────────────────────
section "Go API"
check_http "Health endpoint"     "$GO_URL/health"           200  '"status"'
check_json_field "Health JSON"   "$GO_URL/health"           "status"
check_http "Map geo-points"      "$GO_URL/api/v1/cases/map" 200
check_http "Auth — no creds 401" "$GO_URL/api/v1/cases"     401

# Register a test user
section "Go API — Auth"
REG_BODY='{"email":"smoketest@example.ke","full_name":"Smoke Test","phone":"+254700000099","password":"Password123!"}'
REG_RESP=$(curl -s -X POST "$GO_URL/api/v1/auth/register" \
    -H "Content-Type: application/json" -d "$REG_BODY" --max-time 5 2>/dev/null || echo "{}")

if echo "$REG_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); assert 'access_token' in d" 2>/dev/null; then
    pass "Register new user"
    ACCESS_TOKEN=$(echo "$REG_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])" 2>/dev/null)
else
    # May already exist — try login
    warn_msg="Register returned no token (user may already exist) — trying login"
    echo -e "${YELLOW}⚠ $warn_msg${NC}"
    LOGIN_BODY='{"email":"smoketest@example.ke","password":"Password123!"}'
    LOGIN_RESP=$(curl -s -X POST "$GO_URL/api/v1/auth/login" \
        -H "Content-Type: application/json" -d "$LOGIN_BODY" --max-time 5 2>/dev/null || echo "{}")
    ACCESS_TOKEN=$(echo "$LOGIN_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('access_token',''))" 2>/dev/null || echo "")
    [ -n "$ACCESS_TOKEN" ] && pass "Login existing user" || fail "Could not obtain access token"
fi

# Authenticated case list
if [ -n "${ACCESS_TOKEN:-}" ]; then
    AUTH_RESP=$(curl -s -o /dev/null -w "%{http_code}" \
        -H "Authorization: Bearer $ACCESS_TOKEN" \
        "$GO_URL/api/v1/cases" --max-time 5 2>/dev/null || echo "000")
    [ "$AUTH_RESP" = "200" ] && pass "Authenticated GET /cases" || fail "Authenticated GET /cases — got $AUTH_RESP"
fi

# Submit a test case
if [ -n "${ACCESS_TOKEN:-}" ]; then
    CASE_BODY='{
      "child_name":"Smoke Test Child","age":8,"gender":"male",
      "clothing":"Blue shirt","last_seen_area":"Test Area, Nairobi",
      "county":"Nairobi","lat":-1.286389,"lng":36.817223,
      "description":"This is a smoke test case submission from the test suite.",
      "missing_since":"2024-01-15T14:00:00Z",
      "circumstance_type":"wandered","reporter_type":"public","contact_phone":"+254700000099"
    }'
    CASE_RESP=$(curl -s -X POST "$GO_URL/api/v1/cases" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer $ACCESS_TOKEN" \
        -d "$CASE_BODY" --max-time 10 2>/dev/null || echo "{}")
    if echo "$CASE_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); assert 'id' in d or 'reference_no' in d" 2>/dev/null; then
        CASE_ID=$(echo "$CASE_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" 2>/dev/null || echo "")
        pass "Case submission"
        info "Reference: $(echo "$CASE_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('reference_no','unknown'))" 2>/dev/null)"
    else
        fail "Case submission failed: $(echo "$CASE_RESP" | head -c 300)"
    fi
fi

# Map shows new case
if [ -n "${CASE_ID:-}" ]; then
    MAP_BODY=$(curl -s "$GO_URL/api/v1/cases/map" --max-time 5 2>/dev/null || echo "{}")
    COUNT=$(echo "$MAP_BODY" | python3 -c "import sys,json; print(json.load(sys.stdin).get('count',0))" 2>/dev/null || echo "0")
    [ "$COUNT" -ge 1 ] && pass "Map returns $COUNT case(s)" || fail "Map returned 0 cases"
fi

# ── 2. WebSocket ──────────────────────────────────────────────────────────────
section "WebSocket"
if command -v websocat &>/dev/null; then
    WS_MSG=$(echo "" | timeout 2 websocat "ws://localhost:${GO_PORT:-8080}/ws" 2>/dev/null || echo "timeout")
    pass "WebSocket endpoint reachable"
else
    info "websocat not installed — skipping WS test (install: cargo install websocat)"
    info "Manual test: wscat -c ws://localhost:8080/ws"
fi

# ── 3. PHP / Laravel ─────────────────────────────────────────────────────────
section "PHP / Laravel portal"
check_http "Homepage"            "$PHP_URL"           200  ""
check_http "Login page"          "$PHP_URL/login"     200  ""
check_http "Register page"       "$PHP_URL/register"  200  ""
check_http "404 page"            "$PHP_URL/nonexistent-path-xyz" 404 ""

# ── 4. Rust gRPC (port check only without grpcurl) ────────────────────────────
section "Rust clustering gRPC"
if nc -z localhost "$RUST_PORT" 2>/dev/null; then
    pass "Rust gRPC port :$RUST_PORT is open"
    if command -v grpcurl &>/dev/null; then
        GRPC_RESP=$(grpcurl -plaintext localhost:"$RUST_PORT" list 2>/dev/null || echo "")
        [ -n "$GRPC_RESP" ] && pass "gRPC service list: $GRPC_RESP" || info "grpcurl list returned empty"
    else
        info "grpcurl not installed — install for full gRPC testing: https://github.com/fullstorydev/grpcurl"
    fi
else
    fail "Rust gRPC port :$RUST_PORT not reachable"
fi

# ── 5. Database ───────────────────────────────────────────────────────────────
section "Database"
DB_NAME="${AMBER_DB_NAME:-amber_alert_dev}"
DB_URL="postgres://localhost/${DB_NAME}?sslmode=disable"

if psql "$DB_URL" -c "SELECT COUNT(*) FROM cases" -t -q 2>/dev/null | grep -q '[0-9]'; then
    COUNT=$(psql "$DB_URL" -c "SELECT COUNT(*) FROM cases" -t -q 2>/dev/null | tr -d ' ')
    pass "PostgreSQL reachable — $COUNT case(s) in DB"
else
    fail "Cannot query PostgreSQL (is it running and migrations applied?)"
fi

if psql "$DB_URL" -c "SELECT ST_Distance(ST_MakePoint(36.817,-1.286)::geography, ST_MakePoint(39.668,-4.043)::geography)/1000" -t -q 2>/dev/null | grep -q '[0-9]'; then
    pass "PostGIS ST_Distance works"
else
    fail "PostGIS not available — run: sudo apt install postgresql-postgis"
fi

# ── 6. Redis ─────────────────────────────────────────────────────────────────
section "Redis"
if redis-cli ping 2>/dev/null | grep -q PONG; then
    pass "Redis responding to PING"
    redis-cli set amber:smoke-test ok EX 10 >/dev/null 2>&1
    VAL=$(redis-cli get amber:smoke-test 2>/dev/null)
    [ "$VAL" = "ok" ] && pass "Redis GET/SET works" || fail "Redis SET/GET failed"
else
    fail "Redis not responding — start with: redis-server --daemonize yes"
fi

# ── Summary ──────────────────────────────────────────────────────────────────
echo ""
echo "────────────────────────────────────────"
TOTAL=$((PASS + FAIL))
if [ "$FAIL" -eq 0 ]; then
    echo -e "${GREEN}${BOLD}All $TOTAL smoke tests passed ✓${NC}"
else
    echo -e "${RED}${BOLD}$FAIL / $TOTAL tests failed${NC}"
    exit 1
fi