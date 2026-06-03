-- ============================================================
-- Migration 006: Seed data (development / staging only)
-- ============================================================
-- Run with: psql $DATABASE_URL -f 006_seed.sql
-- DO NOT run in production — use proper provisioning scripts.

BEGIN;

-- ── System user (reporter_id for USSD-submitted cases) ───────────────────────

INSERT INTO users (id, email, phone, full_name, role, password_hash, is_verified, is_active)
VALUES (
    '00000000-0000-0000-0000-000000000001',
    'system@amberalert.go.ke',
    '+254000000000',
    'System',
    'superadmin',
    -- bcrypt hash of 'CHANGE_ME_IN_PRODUCTION'
    '$2b$12$placeholderHashReplaceBeforeDeploymentXXXXXXXXXXXX',
    TRUE,
    TRUE
) ON CONFLICT (email) DO NOTHING;

-- ── Demo officer accounts (password: Officer@2024) ────────────────────────────

INSERT INTO users (email, phone, full_name, role, county, password_hash, is_verified, is_active)
VALUES
    ('officer.nairobi@amberalert.go.ke',  '+254700000001', 'Alice Wanjiru',   'officer', 'Nairobi',  '$2b$12$demoHashNairobi000000000000000000000000000', TRUE, TRUE),
    ('officer.mombasa@amberalert.go.ke',  '+254700000002', 'Hassan Salim',    'officer', 'Mombasa',  '$2b$12$demoHashMombasa000000000000000000000000000', TRUE, TRUE),
    ('officer.kisumu@amberalert.go.ke',   '+254700000003', 'Grace Achieng',   'officer', 'Kisumu',   '$2b$12$demoHashKisumu0000000000000000000000000000', TRUE, TRUE),
    ('officer.nakuru@amberalert.go.ke',   '+254700000004', 'Peter Kamau',     'officer', 'Nakuru',   '$2b$12$demoHashNakuru0000000000000000000000000000', TRUE, TRUE),
    ('admin@amberalert.go.ke',            '+254700000010', 'National Admin',  'admin',   NULL,       '$2b$12$demoHashAdmin00000000000000000000000000000', TRUE, TRUE)
ON CONFLICT (email) DO NOTHING;

-- ── Demo cases (centred on real Kenyan county capitals) ───────────────────────

INSERT INTO cases (
    reference_no, child_name, age, gender, clothing, last_seen_area,
    county, location, description, missing_since,
    circumstance_type, status, reporter_id, reporter_type, created_by
)
SELECT
    ref, child_name, age, gender::gender_type, clothing, area,
    county,
    ST_SetSRID(ST_MakePoint(lng, lat), 4326)::geography,
    description, missing_since::timestamptz,
    circ::circumstance_type, status::case_status,
    '00000000-0000-0000-0000-000000000001',
    'public',
    '00000000-0000-0000-0000-000000000001'
FROM (VALUES
    ('KE-2024-00001', 'Brian Otieno',   8,  'male',   'Blue school uniform',    'Mathare Primary, Nairobi',  'Nairobi',  36.817, -1.286, 'Last seen leaving school gate at 4 PM.',     NOW() - INTERVAL '2 days',  'wandered', 'active'),
    ('KE-2024-00002', 'Amina Hassan',   6,  'female', 'Pink dress, white shoes', 'Nyali Market, Mombasa',    'Mombasa',  39.668, -4.043, 'Disappeared while shopping with mother.',    NOW() - INTERVAL '5 hours', 'unknown',  'active'),
    ('KE-2024-00003', 'David Mwangi',   11, 'male',   'Green t-shirt, grey shorts', 'Section 58, Nakuru',   'Nakuru',   36.080, -0.303, 'Did not return from school.',                NOW() - INTERVAL '1 day',   'wandered', 'review'),
    ('KE-2024-00004', 'Grace Wanjiku',  7,  'female', 'Yellow dress',            'Kondele, Kisumu',          'Kisumu',   34.768, -0.092, 'Went to buy bread, did not return.',         NOW() - INTERVAL '3 days',  'wandered', 'active'),
    ('KE-2024-00005', 'Ethan Kipchoge', 9,  'male',   'Red hoodie, black trousers', 'Meru Town Centre',     'Meru',     37.649,  0.047, 'Disappeared from market area.',              NOW() - INTERVAL '6 hours', 'unknown',  'active'),
    ('KE-2024-00006', 'Fatuma Abdi',    5,  'female', 'White and green dress',   'Garissa Town',             'Garissa',  39.646, -0.453, 'Found wandering, awaiting ID.',             NOW() - INTERVAL '2 days',  'unknown',  'review'),
    ('KE-2024-00007', 'Kevin Njoroge',  13, 'male',   'School uniform',          'Nanyuki, Laikipia',        'Laikipia', 37.074,  0.008, 'Located at relative home in Nyeri.',        NOW() - INTERVAL '12 days', 'wandered', 'resolved'),
    ('KE-2024-00008', 'Sarah Chebet',   10, 'female', 'Orange skirt, brown blouse', 'Kapenguria, West Pokot', 'West Pokot', 35.112, 1.244, 'Last seen herding livestock.', NOW() - INTERVAL '1 day',   'wandered', 'active')
) AS t(ref, child_name, age, gender, clothing, area, county, lng, lat, description, missing_since, circ, status)
ON CONFLICT (reference_no) DO NOTHING;

-- ── Sample alert subscribers (for SMS broadcast testing) ─────────────────────

INSERT INTO alert_subscribers (phone, county, source)
VALUES
    ('+254711111111', 'Nairobi',  'web'),
    ('+254722222222', 'Nairobi',  'ussd'),
    ('+254733333333', 'Mombasa',  'web'),
    ('+254744444444', 'Kisumu',   'ussd'),
    ('+254755555555', 'Nakuru',   'web')
ON CONFLICT DO NOTHING;

-- Refresh materialized view after seed
SELECT refresh_county_summary();

COMMIT;