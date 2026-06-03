-- ============================================================
-- Migration 002: Users
-- ============================================================

BEGIN;

CREATE TABLE users (
    id            UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    email         TEXT        NOT NULL UNIQUE,
    phone         TEXT,
    full_name     TEXT        NOT NULL,
    role          user_role   NOT NULL DEFAULT 'public',

    -- County assignment for officers (NULL = national access)
    county        TEXT,

    password_hash TEXT        NOT NULL,
    is_verified   BOOLEAN     NOT NULL DEFAULT FALSE,
    is_active     BOOLEAN     NOT NULL DEFAULT TRUE,
    last_login_at TIMESTAMPTZ,

    -- Soft delete
    deleted_at    TIMESTAMPTZ,

    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Partial index: only active, non-deleted users in the lookup hot path
CREATE UNIQUE INDEX users_email_active_idx
    ON users (email)
    WHERE deleted_at IS NULL AND is_active = TRUE;

CREATE INDEX users_role_idx    ON users (role);
CREATE INDEX users_county_idx  ON users (county) WHERE county IS NOT NULL;

-- ── Alert subscribers (opted-in phones for county SMS blasts) ─────────────────

CREATE TABLE alert_subscribers (
    id           UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    phone        TEXT        NOT NULL,
    county       TEXT        NOT NULL,
    opted_in_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    opted_out_at TIMESTAMPTZ,           -- NULL = still subscribed
    source       TEXT        NOT NULL DEFAULT 'ussd'  -- ussd | web | whatsapp
);

CREATE UNIQUE INDEX alert_subscribers_phone_county_idx
    ON alert_subscribers (phone, county)
    WHERE opted_out_at IS NULL;

CREATE INDEX alert_subscribers_county_idx
    ON alert_subscribers (county)
    WHERE opted_out_at IS NULL;

COMMIT;