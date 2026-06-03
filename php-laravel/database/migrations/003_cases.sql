-- ============================================================
-- Migration 003: Cases (missing children)
-- ============================================================

BEGIN;

CREATE TABLE cases (
    id              UUID              PRIMARY KEY DEFAULT gen_random_uuid(),
    reference_no    TEXT              NOT NULL UNIQUE,   -- KE-2024-00042

    -- ── Child details ───────────────────────────────────────────────────────
    child_name      TEXT              NOT NULL,
    age             SMALLINT          NOT NULL CHECK (age BETWEEN 0 AND 17),
    gender          gender_type       NOT NULL DEFAULT 'unknown',
    height_cm       NUMERIC(5,1),
    weight_kg       NUMERIC(5,1),
    complexion      TEXT,
    clothing        TEXT              NOT NULL,
    distinctive     TEXT,             -- scars, birthmarks, disability
    languages       TEXT[]            NOT NULL DEFAULT '{}',

    -- ── Last known location ─────────────────────────────────────────────────
    last_seen_area  TEXT              NOT NULL,
    county          TEXT              NOT NULL,
    sub_county      TEXT,

    -- PostGIS geography column: POINT(lng lat) in WGS-84
    -- geography type gives true spherical distance calculations (vs geometry)
    location        GEOGRAPHY(POINT, 4326) NOT NULL,

    -- ── Circumstances ───────────────────────────────────────────────────────
    description     TEXT              NOT NULL,
    missing_since   TIMESTAMPTZ       NOT NULL,
    circumstance_type circumstance_type NOT NULL DEFAULT 'unknown',

    -- ── Status & resolution ─────────────────────────────────────────────────
    status          case_status       NOT NULL DEFAULT 'review',
    resolved_at     TIMESTAMPTZ,
    resolution      TEXT,             -- narrative: "found at relative's home"

    -- ── Reporter ────────────────────────────────────────────────────────────
    reporter_id     UUID              NOT NULL REFERENCES users(id),
    reporter_type   reporter_type     NOT NULL DEFAULT 'public',
    contact_phone   TEXT,

    -- ── Audit ───────────────────────────────────────────────────────────────
    created_by      UUID              NOT NULL REFERENCES users(id),
    updated_by      UUID              REFERENCES users(id),
    deleted_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ       NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ       NOT NULL DEFAULT NOW()
);

-- ── Indexes ───────────────────────────────────────────────────────────────────

-- Spatial index — powers ST_DWithin and KNN queries in milliseconds
CREATE INDEX cases_location_gist_idx
    ON cases USING GIST (location)
    WHERE deleted_at IS NULL;

-- Status filter (map load: WHERE status = 'active')
CREATE INDEX cases_status_idx
    ON cases (status)
    WHERE deleted_at IS NULL;

-- County filter
CREATE INDEX cases_county_idx
    ON cases (county)
    WHERE deleted_at IS NULL;

-- Most-recent cases first
CREATE INDEX cases_missing_since_idx
    ON cases (missing_since DESC)
    WHERE deleted_at IS NULL;

-- Reporter lookup
CREATE INDEX cases_reporter_idx ON cases (reporter_id);

-- ── updated_at trigger ────────────────────────────────────────────────────────

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;

CREATE TRIGGER cases_updated_at
    BEFORE UPDATE ON cases
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ── Useful view: active cases with lat/lng extracted ──────────────────────────

CREATE VIEW active_cases_geo AS
SELECT
    c.id,
    c.reference_no,
    c.child_name,
    c.age,
    c.gender,
    c.status,
    c.county,
    c.last_seen_area,
    c.missing_since,
    c.clothing,
    ST_Y(c.location::geometry) AS lat,
    ST_X(c.location::geometry) AS lng,
    m.url         AS photo_url,
    m.thumb_url   AS thumbnail_url
FROM cases c
LEFT JOIN LATERAL (
    SELECT url, thumb_url
    FROM   media
    WHERE  case_id = c.id AND is_primary = TRUE
    LIMIT  1
) m ON TRUE
WHERE c.status = 'active'
  AND c.deleted_at IS NULL
ORDER BY c.missing_since DESC;

COMMIT;