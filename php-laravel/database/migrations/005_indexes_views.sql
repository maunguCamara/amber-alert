-- ============================================================
-- Migration 005: Performance indexes & materialized stats view
-- ============================================================

BEGIN;

-- ── Composite indexes for common query patterns ───────────────────────────────

-- Map load: active cases in a county ordered by recency
CREATE INDEX cases_status_county_missing_idx
    ON cases (status, county, missing_since DESC)
    WHERE deleted_at IS NULL;

-- Officer dashboard: all cases for their county newest-first
CREATE INDEX cases_county_created_idx
    ON cases (county, created_at DESC)
    WHERE deleted_at IS NULL;

-- Admin: full-text search on child name (Swahili names included)
CREATE INDEX cases_child_name_trgm_idx
    ON cases USING GIN (child_name gin_trgm_ops);

-- (requires pg_trgm extension)
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- ── Materialized view: county alert summary ───────────────────────────────────
-- Refreshed every 5 minutes via pg_cron or application scheduler.
-- Gives the stats bar (active/review/resolved per county) in O(1).

CREATE MATERIALIZED VIEW county_alert_summary AS
SELECT
    county,
    COUNT(*)                                        AS total,
    COUNT(*) FILTER (WHERE status = 'active')       AS active,
    COUNT(*) FILTER (WHERE status = 'review')       AS under_review,
    COUNT(*) FILTER (WHERE status = 'resolved'
        AND resolved_at >= NOW() - INTERVAL '30 days') AS resolved_30d,
    MAX(missing_since)                              AS latest_report
FROM cases
WHERE deleted_at IS NULL
GROUP BY county;

CREATE UNIQUE INDEX county_alert_summary_county_idx
    ON county_alert_summary (county);

-- ── Refresh function (call from pg_cron or Go scheduler) ─────────────────────

CREATE OR REPLACE FUNCTION refresh_county_summary()
RETURNS VOID LANGUAGE sql AS $$
    REFRESH MATERIALIZED VIEW CONCURRENTLY county_alert_summary;
$$;

-- ── National stats function ───────────────────────────────────────────────────

CREATE OR REPLACE FUNCTION national_stats()
RETURNS TABLE (
    active       BIGINT,
    under_review BIGINT,
    resolved_30d BIGINT,
    total        BIGINT
) LANGUAGE sql STABLE AS $$
    SELECT
        SUM(active)::BIGINT,
        SUM(under_review)::BIGINT,
        SUM(resolved_30d)::BIGINT,
        SUM(total)::BIGINT
    FROM county_alert_summary;
$$;

-- ── Spatial: cases within radius (used by Go API NearbyActiveCases) ───────────
-- Expressive wrapper function; Go also calls ST_DWithin directly.

CREATE OR REPLACE FUNCTION active_cases_within_km(
    p_lat      DOUBLE PRECISION,
    p_lng      DOUBLE PRECISION,
    p_radius   DOUBLE PRECISION  -- km
)
RETURNS TABLE (
    id            UUID,
    reference_no  TEXT,
    child_name    TEXT,
    age           SMALLINT,
    gender        gender_type,
    county        TEXT,
    lat           DOUBLE PRECISION,
    lng           DOUBLE PRECISION,
    missing_since TIMESTAMPTZ,
    distance_km   DOUBLE PRECISION
)
LANGUAGE sql STABLE AS $$
    SELECT
        c.id,
        c.reference_no,
        c.child_name,
        c.age,
        c.gender,
        c.county,
        ST_Y(c.location::geometry)  AS lat,
        ST_X(c.location::geometry)  AS lng,
        c.missing_since,
        ST_Distance(
            c.location,
            ST_SetSRID(ST_MakePoint(p_lng, p_lat), 4326)::geography
        ) / 1000.0 AS distance_km
    FROM cases c
    WHERE c.status      = 'active'
      AND c.deleted_at  IS NULL
      AND ST_DWithin(
          c.location,
          ST_SetSRID(ST_MakePoint(p_lng, p_lat), 4326)::geography,
          p_radius * 1000   -- ST_DWithin takes metres for geography
      )
    ORDER BY c.location <->
             ST_SetSRID(ST_MakePoint(p_lng, p_lat), 4326)::geography;
$$;

COMMIT;