-- ============================================================
-- Migration 001: Enable PostGIS, create enums and sequences
-- ============================================================

BEGIN;

-- PostGIS spatial extension (required for ST_MakePoint, geography type, etc.)
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS postgis_topology;

-- UUID generation
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ── Enum types ───────────────────────────────────────────────────────────────

CREATE TYPE case_status AS ENUM (
    'review',    -- just submitted, pending officer approval
    'active',    -- approved and live on the map
    'resolved',  -- child found, case closed happily
    'closed'     -- administrative close (duplicate, invalid, etc.)
);

CREATE TYPE gender_type AS ENUM ('male', 'female', 'unknown');

CREATE TYPE user_role AS ENUM (
    'public',       -- general member of public
    'officer',      -- police / county child protection officer
    'admin',        -- national coordinator
    'superadmin'    -- system administrator
);

CREATE TYPE broadcast_channel AS ENUM ('sms', 'whatsapp', 'email', 'webpush');

CREATE TYPE circumstance_type AS ENUM ('wandered', 'abducted', 'unknown');

CREATE TYPE reporter_type AS ENUM ('public', 'police', 'school', 'ngo');

-- ── Sequences ─────────────────────────────────────────────────────────────────

-- Human-readable reference numbers: KE-2024-00042
CREATE SEQUENCE case_reference_seq
    START WITH 1
    INCREMENT BY 1
    NO MAXVALUE
    CACHE 1;

COMMIT;