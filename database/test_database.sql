-- ============================================================
-- Database tests
-- Run with: psql $TEST_DATABASE_URL -f test_database.sql
--
-- Uses pgTAP (https://pgtap.org) for structured assertions.
-- Install: CREATE EXTENSION IF NOT EXISTS pgtap;
-- Run:     SELECT * FROM runtests();
-- ============================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pgtap;
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

SELECT plan(60);

-- ============================================================
-- 1. Schema: tables exist
-- ============================================================

SELECT has_table('public', 'users',              'users table exists');
SELECT has_table('public', 'cases',              'cases table exists');
SELECT has_table('public', 'media',              'media table exists');
SELECT has_table('public', 'broadcast_records',  'broadcast_records table exists');
SELECT has_table('public', 'case_tips',          'case_tips table exists');
SELECT has_table('public', 'case_audit_log',     'case_audit_log table exists');
SELECT has_table('public', 'alert_subscribers',  'alert_subscribers table exists');

-- ============================================================
-- 2. Schema: required columns
-- ============================================================

SELECT has_column('public', 'cases', 'id',           'cases.id exists');
SELECT has_column('public', 'cases', 'reference_no', 'cases.reference_no exists');
SELECT has_column('public', 'cases', 'location',     'cases.location (PostGIS) exists');
SELECT has_column('public', 'cases', 'status',       'cases.status exists');
SELECT has_column('public', 'cases', 'county',       'cases.county exists');
SELECT has_column('public', 'cases', 'deleted_at',   'cases.deleted_at (soft delete) exists');

SELECT has_column('public', 'users', 'password_hash', 'users.password_hash exists');
SELECT has_column('public', 'users', 'role',           'users.role exists');

-- ============================================================
-- 3. Schema: indexes exist
-- ============================================================

SELECT has_index('public', 'cases', 'cases_location_gist_idx',      'PostGIS GIST index exists');
SELECT has_index('public', 'cases', 'cases_status_idx',              'status index exists');
SELECT has_index('public', 'cases', 'cases_county_idx',              'county index exists');
SELECT has_index('public', 'cases', 'cases_status_county_missing_idx', 'composite status+county index exists');

-- ============================================================
-- 4. Schema: views exist
-- ============================================================

SELECT has_view('public', 'active_cases_geo', 'active_cases_geo view exists');

-- ============================================================
-- 5. Enum types
-- ============================================================

SELECT has_type('public', 'case_status',       'case_status enum exists');
SELECT has_type('public', 'gender_type',       'gender_type enum exists');
SELECT has_type('public', 'user_role',         'user_role enum exists');
SELECT has_type('public', 'broadcast_channel', 'broadcast_channel enum exists');
SELECT has_type('public', 'circumstance_type', 'circumstance_type enum exists');
SELECT has_type('public', 'reporter_type',     'reporter_type enum exists');

-- ============================================================
-- 6. PostGIS geography column
-- ============================================================

-- Insert a test case and verify lat/lng round-trips through ST_MakePoint
DO $$
DECLARE
    v_user_id UUID := gen_random_uuid();
    v_case_id UUID := gen_random_uuid();
    v_lat     DOUBLE PRECISION;
    v_lng     DOUBLE PRECISION;
BEGIN
    INSERT INTO users (id, email, full_name, role, password_hash, is_verified, is_active)
    VALUES (v_user_id, 'db_test@example.ke', 'DB Test', 'public', 'hash', TRUE, TRUE);

    INSERT INTO cases (
        id, reference_no, child_name, age, gender, clothing,
        last_seen_area, county, location,
        description, missing_since, circumstance_type,
        status, reporter_id, reporter_type, created_by
    ) VALUES (
        v_case_id, 'TEST-0001', 'Test Child', 8, 'male', 'Blue uniform',
        'Mathare', 'Nairobi',
        ST_SetSRID(ST_MakePoint(36.817223, -1.286389), 4326)::geography,
        'Test case for DB validation', NOW() - INTERVAL '1 day', 'wandered',
        'active', v_user_id, 'public', v_user_id
    );

    SELECT ST_Y(location::geometry), ST_X(location::geometry)
    INTO v_lat, v_lng
    FROM cases WHERE id = v_case_id;

    IF ABS(v_lat - (-1.286389)) > 0.0001 THEN
        RAISE EXCEPTION 'Latitude round-trip failed: expected -1.286389, got %', v_lat;
    END IF;
    IF ABS(v_lng - 36.817223) > 0.0001 THEN
        RAISE EXCEPTION 'Longitude round-trip failed: expected 36.817223, got %', v_lng;
    END IF;

    DELETE FROM cases WHERE id = v_case_id;
    DELETE FROM users WHERE id = v_user_id;
END;
$$;

SELECT pass('PostGIS lat/lng round-trip test passed');

-- ============================================================
-- 7. ST_DWithin spatial query
-- ============================================================

DO $$
DECLARE
    v_user_id UUID := gen_random_uuid();
    v_near_id UUID := gen_random_uuid();
    v_far_id  UUID := gen_random_uuid();
    v_count   INT;
BEGIN
    INSERT INTO users (id, email, full_name, role, password_hash, is_verified, is_active)
    VALUES (v_user_id, 'spatial_test@example.ke', 'Spatial Test', 'public', 'hash', TRUE, TRUE);

    -- Near point: ~500 m from Nairobi CBD
    INSERT INTO cases (id, reference_no, child_name, age, gender, clothing,
        last_seen_area, county, location, description, missing_since,
        circumstance_type, status, reporter_id, reporter_type, created_by)
    VALUES (v_near_id, 'NEAR-0001', 'Near Child', 7, 'female', 'Red dress',
        'Nairobi', 'Nairobi',
        ST_SetSRID(ST_MakePoint(36.821, -1.290), 4326)::geography,
        'Near point', NOW() - INTERVAL '1 day', 'wandered',
        'active', v_user_id, 'public', v_user_id);

    -- Far point: Mombasa (~440 km away)
    INSERT INTO cases (id, reference_no, child_name, age, gender, clothing,
        last_seen_area, county, location, description, missing_since,
        circumstance_type, status, reporter_id, reporter_type, created_by)
    VALUES (v_far_id, 'FAR-0001', 'Far Child', 6, 'male', 'Blue shirt',
        'Mombasa', 'Mombasa',
        ST_SetSRID(ST_MakePoint(39.668, -4.043), 4326)::geography,
        'Far point', NOW() - INTERVAL '1 day', 'wandered',
        'active', v_user_id, 'public', v_user_id);

    -- Query: points within 5 km of Nairobi CBD
    SELECT COUNT(*) INTO v_count
    FROM cases
    WHERE ST_DWithin(
        location,
        ST_SetSRID(ST_MakePoint(36.817, -1.286), 4326)::geography,
        5000  -- 5 km in metres
    ) AND deleted_at IS NULL;

    IF v_count < 1 THEN
        RAISE EXCEPTION 'ST_DWithin should find at least 1 nearby point, found %', v_count;
    END IF;

    DELETE FROM cases WHERE id IN (v_near_id, v_far_id);
    DELETE FROM users WHERE id = v_user_id;
END;
$$;

SELECT pass('ST_DWithin proximity query test passed');

-- ============================================================
-- 8. active_cases_within_km function
-- ============================================================

DO $$
DECLARE
    v_user_id UUID := gen_random_uuid();
    v_case_id UUID := gen_random_uuid();
    v_count   INT;
BEGIN
    INSERT INTO users (id, email, full_name, role, password_hash, is_verified, is_active)
    VALUES (v_user_id, 'fn_test@example.ke', 'Fn Test', 'public', 'hash', TRUE, TRUE);

    INSERT INTO cases (id, reference_no, child_name, age, gender, clothing,
        last_seen_area, county, location, description, missing_since,
        circumstance_type, status, reporter_id, reporter_type, created_by)
    VALUES (v_case_id, 'FN-0001', 'Function Child', 9, 'male', 'Green shirt',
        'Nairobi', 'Nairobi',
        ST_SetSRID(ST_MakePoint(36.820, -1.288), 4326)::geography,
        'Function test', NOW() - INTERVAL '1 hour', 'wandered',
        'active', v_user_id, 'public', v_user_id);

    SELECT COUNT(*) INTO v_count
    FROM active_cases_within_km(-1.286, 36.817, 10.0);

    IF v_count < 1 THEN
        RAISE EXCEPTION 'active_cases_within_km should return >=1 result';
    END IF;

    DELETE FROM cases WHERE id = v_case_id;
    DELETE FROM users WHERE id = v_user_id;
END;
$$;

SELECT pass('active_cases_within_km function test passed');

-- ============================================================
-- 9. Status update trigger: audit log
-- ============================================================

DO $$
DECLARE
    v_user_id   UUID := gen_random_uuid();
    v_case_id   UUID := gen_random_uuid();
    v_audit_cnt INT;
BEGIN
    INSERT INTO users (id, email, full_name, role, password_hash, is_verified, is_active)
    VALUES (v_user_id, 'audit_test@example.ke', 'Audit Test', 'officer', 'hash', TRUE, TRUE);

    INSERT INTO cases (id, reference_no, child_name, age, gender, clothing,
        last_seen_area, county, location, description, missing_since,
        circumstance_type, status, reporter_id, reporter_type, created_by)
    VALUES (v_case_id, 'AUD-0001', 'Audit Child', 10, 'male', 'White shirt',
        'Nakuru', 'Nakuru',
        ST_SetSRID(ST_MakePoint(36.080, -0.303), 4326)::geography,
        'Audit test', NOW() - INTERVAL '2 hours', 'wandered',
        'review', v_user_id, 'public', v_user_id);

    -- Trigger an update
    UPDATE cases
    SET status = 'active', updated_by = v_user_id
    WHERE id = v_case_id;

    SELECT COUNT(*) INTO v_audit_cnt
    FROM case_audit_log
    WHERE case_id = v_case_id
      AND field = 'status'
      AND old_value = 'review'
      AND new_value = 'active';

    IF v_audit_cnt <> 1 THEN
        RAISE EXCEPTION 'Expected 1 audit log row, found %', v_audit_cnt;
    END IF;

    -- Update again: review → active → resolved
    UPDATE cases
    SET status = 'resolved', updated_by = v_user_id
    WHERE id = v_case_id;

    SELECT COUNT(*) INTO v_audit_cnt
    FROM case_audit_log
    WHERE case_id = v_case_id;

    IF v_audit_cnt <> 2 THEN
        RAISE EXCEPTION 'Expected 2 audit rows after 2 status changes, found %', v_audit_cnt;
    END IF;

    DELETE FROM case_audit_log WHERE case_id = v_case_id;
    DELETE FROM cases WHERE id = v_case_id;
    DELETE FROM users WHERE id = v_user_id;
END;
$$;

SELECT pass('Status change audit trigger test passed');

-- ============================================================
-- 10. updated_at trigger
-- ============================================================

DO $$
DECLARE
    v_user_id  UUID := gen_random_uuid();
    v_before   TIMESTAMPTZ;
    v_after    TIMESTAMPTZ;
BEGIN
    INSERT INTO users (id, email, full_name, role, password_hash, is_verified, is_active)
    VALUES (v_user_id, 'upd_test@example.ke', 'Update Test', 'public', 'hash', TRUE, TRUE);

    SELECT updated_at INTO v_before FROM users WHERE id = v_user_id;

    PERFORM pg_sleep(0.01); -- ensure clock advances

    UPDATE users SET full_name = 'Updated Name' WHERE id = v_user_id;

    SELECT updated_at INTO v_after FROM users WHERE id = v_user_id;

    IF v_after <= v_before THEN
        RAISE EXCEPTION 'updated_at should advance after UPDATE (before=%, after=%)', v_before, v_after;
    END IF;

    DELETE FROM users WHERE id = v_user_id;
END;
$$;

SELECT pass('updated_at trigger test passed');

-- ============================================================
-- 11. Unique constraint: one primary photo per case
-- ============================================================

DO $$
DECLARE
    v_user_id UUID := gen_random_uuid();
    v_case_id UUID := gen_random_uuid();
    v_ok      BOOLEAN := FALSE;
BEGIN
    INSERT INTO users (id, email, full_name, role, password_hash, is_verified, is_active)
    VALUES (v_user_id, 'photo_test@example.ke', 'Photo Test', 'public', 'hash', TRUE, TRUE);

    INSERT INTO cases (id, reference_no, child_name, age, gender, clothing,
        last_seen_area, county, location, description, missing_since,
        circumstance_type, status, reporter_id, reporter_type, created_by)
    VALUES (v_case_id, 'PHO-0001', 'Photo Child', 8, 'male', 'Blue shirt',
        'Nairobi', 'Nairobi',
        ST_SetSRID(ST_MakePoint(36.817, -1.286), 4326)::geography,
        'Photo test', NOW() - INTERVAL '1 hour', 'wandered',
        'active', v_user_id, 'public', v_user_id);

    INSERT INTO media (case_id, url, mime_type, is_primary)
    VALUES (v_case_id, 'https://s3/photo1.jpg', 'image/jpeg', TRUE);

    BEGIN
        INSERT INTO media (case_id, url, mime_type, is_primary)
        VALUES (v_case_id, 'https://s3/photo2.jpg', 'image/jpeg', TRUE);
    EXCEPTION WHEN unique_violation THEN
        v_ok := TRUE;
    END;

    IF NOT v_ok THEN
        RAISE EXCEPTION 'Should not allow two primary photos for the same case';
    END IF;

    DELETE FROM media WHERE case_id = v_case_id;
    DELETE FROM cases WHERE id = v_case_id;
    DELETE FROM users WHERE id = v_user_id;
END;
$$;

SELECT pass('One primary photo per case constraint test passed');

-- ============================================================
-- 12. Alert subscribers: opt-out index
-- ============================================================

DO $$
DECLARE
    v_count INT;
BEGIN
    INSERT INTO alert_subscribers (phone, county, opted_in_at, opted_out_at)
    VALUES
        ('+254799991111', 'Nairobi', NOW(), NULL),           -- active
        ('+254799992222', 'Nairobi', NOW() - INTERVAL '30 days', NOW()); -- opted out

    SELECT COUNT(*) INTO v_count
    FROM alert_subscribers
    WHERE county = 'Nairobi' AND opted_out_at IS NULL
      AND phone IN ('+254799991111', '+254799992222');

    IF v_count <> 1 THEN
        RAISE EXCEPTION 'Expected 1 active subscriber, found %', v_count;
    END IF;

    DELETE FROM alert_subscribers WHERE phone IN ('+254799991111', '+254799992222');
END;
$$;

SELECT pass('Alert subscribers opt-out filter test passed');

-- ============================================================
-- 13. Reference number sequence
-- ============================================================

DO $$
DECLARE
    seq1 BIGINT;
    seq2 BIGINT;
BEGIN
    SELECT nextval('case_reference_seq') INTO seq1;
    SELECT nextval('case_reference_seq') INTO seq2;

    IF seq2 <= seq1 THEN
        RAISE EXCEPTION 'Sequence should increment: got seq1=%, seq2=%', seq1, seq2;
    END IF;
END;
$$;

SELECT pass('case_reference_seq increments correctly');

-- ============================================================
-- 14. Soft delete: deleted cases excluded from geo view
-- ============================================================

DO $$
DECLARE
    v_user_id UUID := gen_random_uuid();
    v_case_id UUID := gen_random_uuid();
    v_count   INT;
BEGIN
    INSERT INTO users (id, email, full_name, role, password_hash, is_verified, is_active)
    VALUES (v_user_id, 'soft_del@example.ke', 'Soft Del', 'public', 'hash', TRUE, TRUE);

    INSERT INTO cases (id, reference_no, child_name, age, gender, clothing,
        last_seen_area, county, location, description, missing_since,
        circumstance_type, status, reporter_id, reporter_type, created_by)
    VALUES (v_case_id, 'DEL-0001', 'Deleted Child', 5, 'female', 'Pink dress',
        'Garissa', 'Garissa',
        ST_SetSRID(ST_MakePoint(39.646, -0.453), 4326)::geography,
        'Will be soft-deleted', NOW() - INTERVAL '1 day', 'unknown',
        'active', v_user_id, 'public', v_user_id);

    -- Soft delete
    UPDATE cases SET deleted_at = NOW() WHERE id = v_case_id;

    -- Should not appear in active_cases_geo view
    SELECT COUNT(*) INTO v_count FROM active_cases_geo WHERE id = v_case_id;

    IF v_count <> 0 THEN
        RAISE EXCEPTION 'Soft-deleted case should not appear in active_cases_geo';
    END IF;

    DELETE FROM cases WHERE id = v_case_id;
    DELETE FROM users WHERE id = v_user_id;
END;
$$;

SELECT pass('Soft-deleted cases excluded from active_cases_geo view');

-- ============================================================
-- 15. national_stats function returns correct types
-- ============================================================

SELECT results_eq(
    $$SELECT pg_typeof(active) FROM national_stats() LIMIT 1$$,
    $$VALUES ('bigint'::regtype)$$,
    'national_stats() active column is BIGINT'
);

-- ============================================================
-- Finish
-- ============================================================

SELECT * FROM finish();

ROLLBACK;