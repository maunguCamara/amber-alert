#!/usr/bin/env bash
# ============================================================
# Kenya Amber Alert — test runner
# Usage: ./run_tests.sh [go|php|rust|db|all]
# ============================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

pass() { echo -e "${GREEN}✓ $1${NC}"; }
fail() { echo -e "${RED}✗ $1${NC}"; exit 1; }
info() { echo -e "${CYAN}▶ $1${NC}"; }
banner() { echo -e "\n${BOLD}${YELLOW}══════════════════════════════════════${NC}"; echo -e "${BOLD}${YELLOW}  $1${NC}"; echo -e "${BOLD}${YELLOW}══════════════════════════════════════${NC}\n"; }

# ── Go tests ──────────────────────────────────────────────────────────────────
run_go() {
    banner "Go API Tests"
    cd "$ROOT/go-api"

    info "Running unit + integration tests with race detector..."
    if go test -race -count=1 -timeout=60s ./...; then
        pass "All Go tests passed"
    else
        fail "Go tests failed"
    fi

    info "Running with coverage report..."
    go test -coverprofile=coverage.out -covermode=atomic ./... 2>/dev/null || true
    if [ -f coverage.out ]; then
        go tool cover -func=coverage.out | tail -1
    fi
}

# ── PHP tests ─────────────────────────────────────────────────────────────────
run_php() {
    banner "PHP / Laravel Tests"
    cd "$ROOT/php-laravel"

    if ! command -v php &>/dev/null; then
        echo -e "${YELLOW}⚠ PHP not found — skipping (run inside Docker: docker compose exec php php artisan test)${NC}"
        return
    fi

    if [ ! -d vendor ]; then
        info "Installing Composer dependencies..."
        composer install --no-interaction --prefer-dist --quiet
    fi

    info "Running PHPUnit test suites..."
    if php artisan test --parallel 2>/dev/null || ./vendor/bin/phpunit; then
        pass "All PHP tests passed"
    else
        fail "PHP tests failed"
    fi
}

# ── Rust tests ────────────────────────────────────────────────────────────────
run_rust() {
    banner "Rust Clustering Service Tests"
    cd "$ROOT/rust-clustering"

    if ! command -v cargo &>/dev/null; then
        echo -e "${YELLOW}⚠ Cargo not found — skipping (run inside Docker: docker compose exec clustering cargo test)${NC}"
        return
    fi

    info "Running unit tests (inline #[cfg(test)] blocks)..."
    if cargo test --lib -- --test-thread=1 2>&1; then
        pass "Rust unit tests passed"
    else
        fail "Rust unit tests failed"
    fi

    info "Running integration tests (tests/ directory)..."
    if cargo test --tests 2>&1; then
        pass "Rust integration tests passed"
    else
        fail "Rust integration tests failed"
    fi
}

# ── Database tests ────────────────────────────────────────────────────────────
run_db() {
    banner "Database (pgTAP) Tests"

    if [ -z "${TEST_DATABASE_URL:-}" ]; then
        echo -e "${YELLOW}⚠ TEST_DATABASE_URL not set — skipping DB tests${NC}"
        echo "  Set it to a PostgreSQL + PostGIS database, e.g.:"
        echo "  export TEST_DATABASE_URL=postgres://amber:pass@localhost:5432/amber_test"
        return
    fi

    info "Running pgTAP tests against $TEST_DATABASE_URL..."
    if psql "$TEST_DATABASE_URL" \
        -c "CREATE EXTENSION IF NOT EXISTS pgtap;" \
        -f "$ROOT/database/test_database.sql" \
        | grep -E "(not ok|FAILED)" | head -20; then
        fail "Some database tests failed"
    else
        pass "All database tests passed"
    fi
}

# ── Entry point ───────────────────────────────────────────────────────────────
TARGET="${1:-all}"

case "$TARGET" in
    go)    run_go   ;;
    php)   run_php  ;;
    rust)  run_rust ;;
    db)    run_db   ;;
    all)
        FAILED=0
        run_go   || FAILED=1
        run_php  || FAILED=1
        run_rust || FAILED=1
        run_db   || FAILED=1

        echo ""
        if [ $FAILED -eq 0 ]; then
            echo -e "${GREEN}${BOLD}✓ All test suites passed${NC}"
        else
            echo -e "${RED}${BOLD}✗ One or more test suites failed${NC}"
            exit 1
        fi
        ;;
    *)
        echo "Usage: $0 [go|php|rust|db|all]"
        exit 1
        ;;
esac

#rememer to make it executable with chmod +x run_tests.sh and run with ./run_tests.sh or specify a target like ./run_tests.sh php