#!/usr/bin/env bash
# =============================================================================
# Kenya Amber Alert — Local Development Script (Ubuntu/Debian, no Docker)
#
# Usage:
#   ./dev.sh setup      — install deps, create DB, run migrations, seed data
#   ./dev.sh start      — start all 4 services in tmux panes (or bg processes)
#   ./dev.sh stop       — stop all background services
#   ./dev.sh reset-db   — drop and recreate the dev database
#   ./dev.sh logs       — tail all service logs
#   ./dev.sh status     — show which services are running
# =============================================================================

set -euo pipefail

# ── Colours ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'
ok()   { echo -e "${GREEN}✓ $*${NC}"; }
fail() { echo -e "${RED}✗ $*${NC}"; exit 1; }
info() { echo -e "${CYAN}▶ $*${NC}"; }
warn() { echo -e "${YELLOW}⚠ $*${NC}"; }
banner() { echo -e "\n${BOLD}${CYAN}══ $* ══${NC}\n"; }

# ── Paths ─────────────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"          # amber-alert/
GO_DIR="$PROJECT_ROOT/go-api"
PHP_DIR="$PROJECT_ROOT/php-laravel"
RUST_DIR="$PROJECT_ROOT/rust-clustering"
DB_DIR="$PROJECT_ROOT/database/migrations"
LOG_DIR="$SCRIPT_DIR/logs"
PID_DIR="$SCRIPT_DIR/pids"

mkdir -p "$LOG_DIR" "$PID_DIR"

# ── Config (edit these or export before running) ──────────────────────────────
DB_NAME="${AMBER_DB_NAME:-amber_alert_dev}"
DB_USER="${AMBER_DB_USER:-$(whoami)}"
DB_PASS="${AMBER_DB_PASS:-}"
DB_HOST="${AMBER_DB_HOST:-localhost}"
DB_PORT="${AMBER_DB_PORT:-5432}"

REDIS_HOST="${AMBER_REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${AMBER_REDIS_PORT:-6379}"
REDIS_PASS="${AMBER_REDIS_PASS:-}"

GO_PORT="${AMBER_GO_PORT:-8080}"
PHP_PORT="${AMBER_PHP_PORT:-8000}"
RUST_PORT="${AMBER_RUST_PORT:-50051}"

JWT_SECRET="${AMBER_JWT_SECRET:-dev-secret-key-change-in-production-32}"

# Build DATABASE_URL
if [ -n "$DB_PASS" ]; then
    DATABASE_URL="postgres://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}?sslmode=disable"
else
    DATABASE_URL="postgres://${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}?sslmode=disable"
fi

if [ -n "$REDIS_PASS" ]; then
    REDIS_URL="redis://:${REDIS_PASS}@${REDIS_HOST}:${REDIS_PORT}/0"
else
    REDIS_URL="redis://${REDIS_HOST}:${REDIS_PORT}/0"
fi

# ─────────────────────────────────────────────────────────────────────────────
# SETUP
# ─────────────────────────────────────────────────────────────────────────────
cmd_setup() {
    banner "Installing system dependencies"
    install_system_deps
    install_postgis
    install_php_extensions
    install_protobuf
    create_database
    run_migrations
    setup_go
    setup_rust
    setup_php
    write_env_files
    ok "Setup complete — run './dev.sh start' to launch all services"
}

install_system_deps() {
    info "Updating apt and installing base tools..."
    sudo apt-get update -qq
    sudo apt-get install -y -qq \
        curl git build-essential pkg-config libssl-dev \
        libpq-dev protobuf-compiler \
        php8.2-cli php8.2-pgsql php8.2-redis php8.2-curl \
        php8.2-mbstring php8.2-xml php8.2-zip php8.2-intl \
        php8.2-gd php8.2-bcmath php8.2-sqlite3 \
        2>/dev/null
    ok "System packages installed"
}

install_postgis() {
    info "Installing PostGIS..."
    sudo apt-get install -y -qq postgresql-postgis postgresql-postgis-scripts 2>/dev/null \
        || sudo apt-get install -y -qq postgis 2>/dev/null \
        || warn "Could not install PostGIS via apt — try: sudo apt-get install postgresql-14-postgis-3"
    ok "PostGIS ready"
}

install_php_extensions() {
    # Check PHP version and install matching redis extension
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "8.2")
    info "PHP $PHP_VER detected"
    if ! php -m 2>/dev/null | grep -q redis; then
        sudo apt-get install -y -qq "php${PHP_VER}-redis" 2>/dev/null \
            || warn "php-redis not available via apt; install via PECL: sudo pecl install redis"
    fi
    ok "PHP extensions ready"
}

install_protobuf() {
    if ! command -v protoc &>/dev/null; then
        info "Installing protoc..."
        sudo apt-get install -y -qq protobuf-compiler 2>/dev/null
    fi
    PROTOC_VER=$(protoc --version 2>/dev/null || echo "unknown")
    ok "protoc: $PROTOC_VER"
}

create_database() {
    banner "Database setup"
    info "Creating database '$DB_NAME' (if not exists)..."

    # Try createdb as current user first (peer auth), then with sudo -u postgres
    if createdb "$DB_NAME" 2>/dev/null; then
        ok "Database '$DB_NAME' created"
    elif sudo -u postgres createdb -O "$DB_USER" "$DB_NAME" 2>/dev/null; then
        ok "Database '$DB_NAME' created via postgres user"
    else
        warn "Database may already exist — continuing"
    fi

    info "Enabling PostGIS extensions..."
    psql_exec "CREATE EXTENSION IF NOT EXISTS postgis;"
    psql_exec "CREATE EXTENSION IF NOT EXISTS postgis_topology;"
    psql_exec "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
    psql_exec "CREATE EXTENSION IF NOT EXISTS pgcrypto;"
    ok "Extensions enabled"
}

run_migrations() {
    banner "Running migrations"
    for f in "$DB_DIR"/00[1-5]_*.sql; do
        info "→ $(basename $f)"
        psql_exec_file "$f"
    done
    ok "All migrations applied"

    read -rp "$(echo -e "${YELLOW}Seed demo data? (8 cases across Kenya) [y/N]: ${NC}")" seed
    if [[ "${seed,,}" == "y" ]]; then
        info "Seeding..."
        psql_exec_file "$DB_DIR/006_seed.sql"
        ok "Demo data seeded"
    fi
}

setup_go() {
    banner "Go API dependencies"
    cd "$GO_DIR"
    info "Downloading Go modules..."
    go mod download 2>&1 | tail -5
    info "Verifying build..."
    go build -o /tmp/amber-api-check ./cmd/server 2>&1 && rm -f /tmp/amber-api-check
    ok "Go build OK"
    cd - >/dev/null
}

setup_rust() {
    banner "Rust clustering service"
    cd "$RUST_DIR"
    info "Building Rust binary (this takes ~90s on first run)..."
    cargo build 2>&1 | tail -10
    ok "Rust build OK"
    cd - >/dev/null
}

setup_php() {
    banner "PHP / Laravel setup"
    cd "$PHP_DIR"

    if [ ! -f composer.json ]; then
        warn "No composer.json found in $PHP_DIR — skipping PHP setup"
        return
    fi

    info "Installing Composer packages..."
    composer install --no-interaction --prefer-dist --quiet 2>&1 | tail -5

    if [ ! -f .env ]; then
        cp "$SCRIPT_DIR/.env.php" .env 2>/dev/null || cp .env.example .env 2>/dev/null || true
    fi

    info "Generating Laravel app key..."
    php artisan key:generate --force --quiet 2>/dev/null || true

    info "Running Laravel migrations (SQLite fallback for dev if pgsql unavailable)..."
    php artisan migrate --force --quiet 2>/dev/null \
        || warn "Laravel migrations failed — check .env DB settings"

    ok "Laravel ready"
    cd - >/dev/null
}

write_env_files() {
    banner "Writing .env files"

    # ── Go API .env ───────────────────────────────────────────────────────────
    cat > "$SCRIPT_DIR/.env.go" << GOENV
AMBER_ENVIRONMENT=development
AMBER_PORT=${GO_PORT}
AMBER_DATABASE_URL=${DATABASE_URL}
AMBER_REDIS_URL=${REDIS_URL}
AMBER_JWT_SECRET=${JWT_SECRET}
AMBER_JWT_ACCESS_TOKEN_TTL=15m
AMBER_JWT_REFRESH_TOKEN_TTL=168h
# Local filesystem storage (no MinIO needed for dev)
AMBER_S3_ENDPOINT=
AMBER_S3_BUCKET=amber-alert-dev
AMBER_S3_ACCESS_KEY=dev
AMBER_S3_SECRET_KEY=dev
AMBER_S3_REGION=us-east-1
AMBER_S3_FORCE_PATH_STYLE=true
# Africa's Talking sandbox (get free sandbox key at africastalking.com)
AMBER_AT_API_KEY=sandbox_key
AMBER_AT_USERNAME=sandbox
AMBER_AT_SHORT_CODE=22384
AMBER_CLUSTER_SERVICE_ADDR=localhost:${RUST_PORT}
AMBER_ALLOWED_ORIGINS=http://localhost:${PHP_PORT},http://localhost:${GO_PORT}
AMBER_RATE_LIMIT_RPM=300
GOENV
    ok "Written: .env.go"

    # ── PHP .env ──────────────────────────────────────────────────────────────
    cat > "$SCRIPT_DIR/.env.php" << PHPENV
APP_NAME="Kenya Amber Alert Dev"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:${PHP_PORT}

DB_CONNECTION=pgsql
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}

CACHE_DRIVER=redis
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

REDIS_HOST=${REDIS_HOST}
REDIS_PASSWORD=${REDIS_PASS}
REDIS_PORT=${REDIS_PORT}

AMBER_API_URL=http://localhost:${GO_PORT}
AMBER_WS_URL=ws://localhost:${GO_PORT}/ws
AMBER_API_TIMEOUT=10

AT_API_KEY=sandbox_key
AT_USERNAME=sandbox
AT_SHORT_CODE=22384
PHPENV

    # Copy to Laravel project
    cp "$SCRIPT_DIR/.env.php" "$PHP_DIR/.env" 2>/dev/null || true
    ok "Written: .env.php → php-laravel/.env"

    # ── Rust env ──────────────────────────────────────────────────────────────
    cat > "$SCRIPT_DIR/.env.rust" << RUSTENV
RUST_LOG=amber_clustering=debug
CLUSTER_LISTEN_ADDR=127.0.0.1:${RUST_PORT}
RUSTENV
    ok "Written: .env.rust"
}

# ─────────────────────────────────────────────────────────────────────────────
# START
# ─────────────────────────────────────────────────────────────────────────────
cmd_start() {
    banner "Starting Kenya Amber Alert (local)"

    check_deps_installed

    # Prefer tmux for a nice split-pane view
    if command -v tmux &>/dev/null && [ -n "${TMUX:-}" ]; then
        start_with_tmux
    else
        start_background
    fi
}

start_with_tmux() {
    info "Starting services in tmux panes..."
    SESSION="amber-alert"

    # Kill existing session if any
    tmux kill-session -t "$SESSION" 2>/dev/null || true
    tmux new-session -d -s "$SESSION" -n "services" -x 220 -y 50

    # Pane 0 (top-left): Rust clustering
    tmux send-keys -t "$SESSION:0" "$(rust_cmd)" Enter
    tmux split-window -h -t "$SESSION:0"

    # Pane 1 (top-right): Go API
    tmux send-keys -t "$SESSION:0.1" "$(go_cmd)" Enter
    tmux split-window -v -t "$SESSION:0.0"

    # Pane 2 (bottom-left): PHP Laravel
    tmux send-keys -t "$SESSION:0.2" "$(php_cmd)" Enter
    tmux split-window -v -t "$SESSION:0.1"

    # Pane 3 (bottom-right): status / logs
    tmux send-keys -t "$SESSION:0.3" "watch -n2 './dev.sh status'" Enter

    tmux select-layout -t "$SESSION:0" tiled
    tmux attach-session -t "$SESSION"
}

start_background() {
    info "Starting services in background (no tmux found or not inside tmux session)..."
    info "Tip: run inside a tmux session for split-pane view"

    # 1. Rust clustering service
    info "Starting Rust clustering service on :${RUST_PORT}..."
    env $(cat "$SCRIPT_DIR/.env.rust" | xargs) \
        "$RUST_DIR/target/debug/amber-clustering" \
        >> "$LOG_DIR/rust.log" 2>&1 &
    echo $! > "$PID_DIR/rust.pid"
    sleep 1
    check_port "$RUST_PORT" "Rust clustering" || true

    # 2. Go API
    info "Starting Go API on :${GO_PORT}..."
    cd "$GO_DIR"
    env $(cat "$SCRIPT_DIR/.env.go" | xargs) \
        go run ./cmd/server \
        >> "$LOG_DIR/go.log" 2>&1 &
    echo $! > "$PID_DIR/go.pid"
    cd - >/dev/null
    sleep 2
    check_port "$GO_PORT" "Go API" || true

    # 3. PHP Laravel
    info "Starting Laravel dev server on :${PHP_PORT}..."
    cd "$PHP_DIR"
    env $(cat "$SCRIPT_DIR/.env.php" | xargs) \
        php artisan serve --host=127.0.0.1 --port="$PHP_PORT" \
        >> "$LOG_DIR/php.log" 2>&1 &
    echo $! > "$PID_DIR/php.pid"
    cd - >/dev/null
    sleep 1
    check_port "$PHP_PORT" "PHP/Laravel" || true

    echo ""
    ok "All services started"
    print_urls
    echo ""
    info "Logs: tail -f $LOG_DIR/*.log"
    info "Stop: ./dev.sh stop"
}

# ── Service command strings (used by both tmux and background modes) ──────────
rust_cmd() {
    echo "cd '$RUST_DIR' && \
        RUST_LOG=amber_clustering=debug \
        CLUSTER_LISTEN_ADDR=127.0.0.1:${RUST_PORT} \
        cargo run 2>&1 | tee '$LOG_DIR/rust.log'"
}

go_cmd() {
    echo "cd '$GO_DIR' && \
        $(cat "$SCRIPT_DIR/.env.go" 2>/dev/null | grep -v '^#' | grep '=' | sed 's/^/export /' | tr '\n' ' ') \
        go run ./cmd/server 2>&1 | tee '$LOG_DIR/go.log'"
}

php_cmd() {
    echo "cd '$PHP_DIR' && \
        php artisan serve --host=127.0.0.1 --port=${PHP_PORT} 2>&1 | tee '$LOG_DIR/php.log'"
}

# ─────────────────────────────────────────────────────────────────────────────
# STOP
# ─────────────────────────────────────────────────────────────────────────────
cmd_stop() {
    banner "Stopping services"
    for service in rust go php; do
        PID_FILE="$PID_DIR/${service}.pid"
        if [ -f "$PID_FILE" ]; then
            PID=$(cat "$PID_FILE")
            if kill -0 "$PID" 2>/dev/null; then
                kill "$PID" && ok "Stopped $service (PID $PID)"
            else
                warn "$service was not running"
            fi
            rm -f "$PID_FILE"
        fi
    done

    # Kill tmux session if open
    tmux kill-session -t "amber-alert" 2>/dev/null && ok "tmux session closed" || true

    # Kill any stray cargo run / go run / artisan processes
    pkill -f "amber-clustering"    2>/dev/null || true
    pkill -f "amber-api"           2>/dev/null || true
    pkill -f "artisan serve"       2>/dev/null || true
    ok "All services stopped"
}

# ─────────────────────────────────────────────────────────────────────────────
# STATUS
# ─────────────────────────────────────────────────────────────────────────────
cmd_status() {
    echo ""
    echo -e "${BOLD}Kenya Amber Alert — Service Status${NC}"
    echo "────────────────────────────────────────"
    check_port_quiet "$RUST_PORT" && ok "Rust clustering  :${RUST_PORT}" || echo -e "${RED}✗ Rust clustering  :${RUST_PORT} (not running)${NC}"
    check_port_quiet "$GO_PORT"   && ok "Go API           :${GO_PORT}"   || echo -e "${RED}✗ Go API           :${GO_PORT} (not running)${NC}"
    check_port_quiet "$PHP_PORT"  && ok "PHP/Laravel      :${PHP_PORT}"  || echo -e "${RED}✗ PHP/Laravel      :${PHP_PORT} (not running)${NC}"
    echo "────────────────────────────────────────"

    # Postgres
    if psql "$DATABASE_URL" -c "SELECT 1" -q -t 2>/dev/null | grep -q 1; then
        ok "PostgreSQL       :${DB_PORT} (db: ${DB_NAME})"
    else
        echo -e "${RED}✗ PostgreSQL       :${DB_PORT} (cannot connect)${NC}"
    fi

    # Redis
    if redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" ping 2>/dev/null | grep -q PONG; then
        ok "Redis            :${REDIS_PORT}"
    else
        echo -e "${RED}✗ Redis            :${REDIS_PORT} (not running — start with: redis-server)${NC}"
    fi

    echo ""
    print_urls
}

print_urls() {
    echo -e "${BOLD}URLs:${NC}"
    echo -e "  ${CYAN}Public map:${NC}         http://localhost:${PHP_PORT}"
    echo -e "  ${CYAN}Report case:${NC}        http://localhost:${PHP_PORT}/report"
    echo -e "  ${CYAN}Admin dashboard:${NC}    http://localhost:${PHP_PORT}/dashboard"
    echo -e "  ${CYAN}Go API health:${NC}      http://localhost:${GO_PORT}/health"
    echo -e "  ${CYAN}Go API cases:${NC}       http://localhost:${GO_PORT}/api/v1/cases/map"
    echo -e "  ${CYAN}WebSocket:${NC}          ws://localhost:${GO_PORT}/ws"
    echo -e "  ${CYAN}Rust gRPC:${NC}          localhost:${RUST_PORT}"
    echo ""
    echo -e "${BOLD}Demo credentials (after seeding):${NC}"
    echo -e "  Public login:   citizen@amberalert.go.ke / Password123!"
    echo -e "  Officer login:  officer.nairobi@amberalert.go.ke / Officer@2024"
    echo -e "  Admin login:    admin@amberalert.go.ke / Admin@2024"
}

# ─────────────────────────────────────────────────────────────────────────────
# RESET DB
# ─────────────────────────────────────────────────────────────────────────────
cmd_reset_db() {
    warn "This will DROP and recreate '$DB_NAME'. All data will be lost."
    read -rp "$(echo -e "${RED}Are you sure? [y/N]: ${NC}")" confirm
    [[ "${confirm,,}" != "y" ]] && { info "Aborted."; exit 0; }

    info "Dropping database..."
    dropdb "$DB_NAME" 2>/dev/null \
        || sudo -u postgres dropdb "$DB_NAME" 2>/dev/null \
        || warn "Could not drop — may not exist"

    cmd_setup
}

# ─────────────────────────────────────────────────────────────────────────────
# LOGS
# ─────────────────────────────────────────────────────────────────────────────
cmd_logs() {
    if [ -n "${1:-}" ]; then
        tail -f "$LOG_DIR/${1}.log"
    else
        tail -f "$LOG_DIR"/*.log
    fi
}

# ─────────────────────────────────────────────────────────────────────────────
# Helpers
# ─────────────────────────────────────────────────────────────────────────────
psql_exec() {
    psql "$DATABASE_URL" -c "$1" -q 2>/dev/null \
        || sudo -u postgres psql -d "$DB_NAME" -c "$1" -q 2>/dev/null \
        || fail "psql command failed: $1"
}

psql_exec_file() {
    psql "$DATABASE_URL" -f "$1" -q 2>/dev/null \
        || sudo -u postgres psql -d "$DB_NAME" -f "$1" -q 2>/dev/null \
        || fail "psql file failed: $1"
}

check_port() {
    local port=$1; local name=$2
    sleep 0.5
    if nc -z localhost "$port" 2>/dev/null; then
        ok "$name is up on :$port"
    else
        warn "$name not yet responding on :$port — check $LOG_DIR/${name,,}.log"
        return 1
    fi
}

check_port_quiet() {
    nc -z localhost "$1" 2>/dev/null
}

check_deps_installed() {
    local missing=()
    command -v go       &>/dev/null || missing+=("Go (go)")
    command -v cargo    &>/dev/null || missing+=("Rust (cargo)")
    command -v php      &>/dev/null || missing+=("PHP (php)")
    command -v psql     &>/dev/null || missing+=("PostgreSQL (psql)")
    command -v redis-cli &>/dev/null || missing+=("Redis (redis-cli)")
    if [ ${#missing[@]} -gt 0 ]; then
        fail "Missing: ${missing[*]}. Run './dev.sh setup' first."
    fi

    # Check env files exist
    if [ ! -f "$SCRIPT_DIR/.env.go" ]; then
        warn ".env.go not found — run './dev.sh setup' or './dev.sh write-env' first"
        write_env_files
    fi
}

# ─────────────────────────────────────────────────────────────────────────────
# Entry point
# ─────────────────────────────────────────────────────────────────────────────
case "${1:-help}" in
    setup)     cmd_setup   ;;
    start)     cmd_start   ;;
    stop)      cmd_stop    ;;
    status)    cmd_status  ;;
    reset-db)  cmd_reset_db ;;
    logs)      cmd_logs "${2:-}" ;;
    write-env) write_env_files ;;
    help|*)
        echo ""
        echo -e "${BOLD}Kenya Amber Alert — Local Dev Script${NC}"
        echo ""
        echo "  ./dev.sh setup       Install deps, create DB, run migrations"
        echo "  ./dev.sh start       Start all 4 services (tmux or background)"
        echo "  ./dev.sh stop        Stop all background services"
        echo "  ./dev.sh status      Show running services and URLs"
        echo "  ./dev.sh reset-db    Drop and recreate the dev database"
        echo "  ./dev.sh logs        Tail all service logs"
        echo "  ./dev.sh logs go     Tail just the Go API log"
        echo "  ./dev.sh write-env   Regenerate .env files without full setup"
        echo ""
        echo -e "${CYAN}First time? Run: ./dev.sh setup && ./dev.sh start${NC}"
        echo ""
        ;;
esac
