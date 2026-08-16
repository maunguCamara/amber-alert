-- ============================================================
-- Migration 005: Performance indexes, materialized view,
--               row-level security, and least-privilege roles
-- ============================================================
--
-- Changes from original:
--   - Added Row-Level Security (RLS) on cases so the app user cannot
--     read soft-deleted rows at the DB level, even with a raw query.
--   - Added least-privilege app role: SELECT/INSERT/UPDATE on cases,
--     SELECT on media. No DELETE privilege — soft delete only.
--   - audit log table gets INSERT only — no UPDATE or DELETE so the
--     app cannot tamper with audit history.
--   - national_stats() and refresh_county_summary() are SECURITY DEFINER
--     functions owned by the superuser so the app role can call them
--     without direct access to the underlying tables.

BEGIN;

-- ── Composite indexes ─────────────────────────────────────────────────────────

-- Already created in 003 for the common query patterns.
-- This migration adds remaining analytics indexes.

-- Admin: all cases newest-first within a county
CREATE INDEX IF NOT EXISTS cases_county_created_idx
    ON cases (county, created_at DESC)
    WHERE deleted_at IS NULL;

-- ── Row-Level Security ────────────────────────────────────────────────────────
-- Prevents soft-deleted rows from being returned even on raw queries
-- by the application database role.

ALTER TABLE cases ENABLE ROW LEVEL SECURITY;

-- App role sees only non-deleted rows
CREATE POLICY cases_no_deleted
    ON cases
    FOR ALL
    USING (deleted_at IS NULL);

-- Superuser bypasses RLS so migrations and admin tools still work
ALTER TABLE cases FORCE ROW LEVEL SECURITY;

-- ── Least-privilege app role ──────────────────────────────────────────────────
-- Create a dedicated role for the application with minimal privileges.
-- Run this as a superuser after the migrations complete.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'amber_app') THEN
        CREATE ROLE amber_app LOGIN PASSWORD 'REPLACE_WITH_STRONG_PASSWORD';
    END IF;
END;
$$;

GRANT CONNECT ON DATABASE amber_alert_dev TO amber_app;
GRANT USAGE  ON SCHEMA public TO amber_app;

-- Cases: SELECT, INSERT, UPDATE — no DELETE (soft delete only)
GRANT SELECT, INSERT, UPDATE ON TABLE cases             TO amber_app;
GRANT SELECT, INSERT         ON TABLE media             TO amber_app;
GRANT SELECT, INSERT, UPDATE ON TABLE users             TO amber_app;
GRANT SELECT, INSERT         ON TABLE broadcast_records TO amber_app;
GRANT SELECT, INSERT         ON TABLE case_tips         TO amber_app;
GRANT SELECT, INSERT         ON TABLE alert_subscribers TO amber_app;

-- Audit log: INSERT only — application cannot modify audit history
GRANT INSERT ON TABLE case_audit_log TO amber_app;
-- No UPDATE or DELETE granted — this is intentional

-- Sequences
GRANT USAGE ON SEQUENCE case_reference_seq TO amber_app;

-- ── Materialized view: county alert summary ───────────────────────────────────

CREATE MATERIALIZED VIEW IF NOT EXISTS county_alert_summary AS
SELECT
    county,
    COUNT(*)                                              AS total,
    COUNT(*) FILTER (WHERE status = 'active')             AS active,
    COUNT(*) FILTER (WHERE status = 'review')             AS under_review,
    COUNT(*) FILTER (WHERE status = 'resolved'
        AND resolved_at >= NOW() - INTERVAL '30 days')    AS resolved_30d,
    MAX(missing_since)                                    AS latest_report
FROM cases
WHERE deleted_at IS NULL
GROUP BY county;

CREATE UNIQUE INDEX IF NOT EXISTS county_alert_summary_county_idx
    ON county_alert_summary (county);

-- App role can read the materialized view
GRANT SELECT ON county_alert_summary TO amber_app;

-- ── Refresh function (SECURITY DEFINER so app role can call it) ───────────────

CREATE OR REPLACE FUNCTION refresh_county_summary()
RETURNS VOID
LANGUAGE sql
SECURITY DEFINER  -- runs as superuser so the app role can call it
SET search_path = public
AS $$
    REFRESH MATERIALIZED VIEW CONCURRENTLY county_alert_summary;
$$;

GRANT EXECUTE ON FUNCTION refresh_county_summary() TO amber_app;

-- ── National stats function ───────────────────────────────────────────────────

CREATE OR REPLACE FUNCTION national_stats()
RETURNS TABLE (
    active       BIGINT,
    under_review BIGINT,
    resolved_30d BIGINT,
    total        BIGINT
)
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
    SELECT
        SUM(active)::BIGINT,
        SUM(under_review)::BIGINT,
        SUM(resolved_30d)::BIGINT,
        SUM(total)::BIGINT
    FROM county_alert_summary;
$$;

GRANT EXECUTE ON FUNCTION national_stats() TO amber_app;

-- ── Spatial distance function ─────────────────────────────────────────────────

CREATE OR REPLACE FUNCTION active_cases_within_km(
    p_lat      DOUBLE PRECISION,
    p_lng      DOUBLE PRECISION,
    p_radius   DOUBLE PRECISION
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
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
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
          p_radius * 1000
      )
    ORDER BY c.location <->
             ST_SetSRID(ST_MakePoint(p_lng, p_lat), 4326)::geography;
$$;

GRANT EXECUTE ON FUNCTION active_cases_within_km(DOUBLE PRECISION, DOUBLE PRECISION, DOUBLE PRECISION)
    TO amber_app;

COMMIT;