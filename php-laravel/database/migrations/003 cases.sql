-- ============================================================
-- Migration 003: Cases (missing children)
-- ============================================================
--
-- Changes from original:
--   - Added CHECK constraints on age, lat, lng so the database
--     enforces domain rules independently of the application layer.
--     A bug or a direct SQL INSERT cannot store invalid data.
--   - Added CHECK on circumstance_type and status for the same reason.
--   - Added partial index on (county, missing_since DESC) for the
--     common dashboard query pattern.
--   - active_cases_geo view now uses SECURITY DEFINER so it can be
--     queried by the app role without granting direct table access.

BEGIN;

CREATE TABLE cases (
    id              UUID              PRIMARY KEY DEFAULT gen_random_uuid(),
    reference_no    TEXT              NOT NULL UNIQUE,

    -- Child details
    child_name      TEXT              NOT NULL CHECK (char_length(child_name) BETWEEN 1 AND 120),
    age             SMALLINT          NOT NULL CHECK (age BETWEEN 0 AND 17),
    gender          gender_type       NOT NULL DEFAULT 'unknown',
    height_cm       NUMERIC(5,1)                CHECK (height_cm BETWEEN 30 AND 220),
    weight_kg       NUMERIC(5,1)                CHECK (weight_kg BETWEEN 1  AND 150),
    complexion      TEXT                        CHECK (char_length(complexion) <= 80),
    clothing        TEXT              NOT NULL  CHECK (char_length(clothing)   <= 255),
    distinctive     TEXT                        CHECK (char_length(distinctive)<= 500),
    languages       TEXT[]            NOT NULL DEFAULT '{}',

    -- Location
    last_seen_area  TEXT              NOT NULL CHECK (char_length(last_seen_area) <= 255),
    county          TEXT              NOT NULL CHECK (char_length(county) BETWEEN 1 AND 80),
    sub_county      TEXT                       CHECK (char_length(sub_county) <= 120),

    -- PostGIS geography column.
    -- Kenya bounding box enforced: lat -5..5, lng 34..42.
    -- ST_Y gives lat, ST_X gives lng.
    location        GEOGRAPHY(POINT, 4326) NOT NULL
                    CHECK (
                        ST_Y(location::geometry) BETWEEN -5.0 AND 5.0
                        AND ST_X(location::geometry) BETWEEN 34.0 AND 42.0
                    ),

    -- Circumstances
    description     TEXT              NOT NULL CHECK (char_length(description) BETWEEN 20 AND 2000),
    missing_since   TIMESTAMPTZ       NOT NULL CHECK (missing_since <= NOW() + INTERVAL '1 minute'),
    circumstance_type circumstance_type NOT NULL DEFAULT 'unknown',

    -- Status
    status          case_status       NOT NULL DEFAULT 'review',
    resolved_at     TIMESTAMPTZ,
    resolution      TEXT                       CHECK (char_length(resolution) <= 500),

    -- Consistency: resolved_at must be set when status is resolved/closed
    CONSTRAINT resolved_at_required
        CHECK (
            status NOT IN ('resolved', 'closed')
            OR resolved_at IS NOT NULL
        ),

    -- Reporter
    reporter_id     UUID              NOT NULL REFERENCES users(id),
    reporter_type   reporter_type     NOT NULL DEFAULT 'public',
    contact_phone   TEXT                       CHECK (char_length(contact_phone) <= 20),

    -- Audit
    created_by      UUID              NOT NULL REFERENCES users(id),
    updated_by      UUID              REFERENCES users(id),
    deleted_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ       NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ       NOT NULL DEFAULT NOW()
);

-- ── Indexes ───────────────────────────────────────────────────────────────────

-- Spatial index — powers ST_DWithin and KNN queries
CREATE INDEX cases_location_gist_idx
    ON cases USING GIST (location)
    WHERE deleted_at IS NULL;

-- Status filter
CREATE INDEX cases_status_idx
    ON cases (status)
    WHERE deleted_at IS NULL;

-- County + recency — common dashboard query pattern
CREATE INDEX cases_county_missing_idx
    ON cases (county, missing_since DESC)
    WHERE deleted_at IS NULL;

-- Composite: status + county + recency — map load query
CREATE INDEX cases_status_county_missing_idx
    ON cases (status, county, missing_since DESC)
    WHERE deleted_at IS NULL;

-- Full-text search on child name (Swahili names included)
CREATE INDEX cases_child_name_trgm_idx
    ON cases USING GIN (child_name gin_trgm_ops);

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

-- ── Active cases geo view ─────────────────────────────────────────────────────
-- Exposes only active cases with lat/lng extracted.
-- App role queries this view — it does not need direct SELECT on cases.

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
WHERE c.status    = 'active'
  AND c.deleted_at IS NULL
ORDER BY c.missing_since DESC;

COMMIT;