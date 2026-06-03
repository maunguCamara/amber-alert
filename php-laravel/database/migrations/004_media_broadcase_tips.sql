-- ============================================================
-- Migration 004: Media, broadcasts, tips, audit log
-- ============================================================

BEGIN;

-- ── Media ─────────────────────────────────────────────────────────────────────

CREATE TABLE media (
    id          UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id     UUID        NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    url         TEXT        NOT NULL,
    thumb_url   TEXT,
    mime_type   TEXT        NOT NULL DEFAULT 'image/jpeg',
    size_bytes  BIGINT      NOT NULL DEFAULT 0,
    is_primary  BOOLEAN     NOT NULL DEFAULT FALSE,
    deleted_at  TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Only one primary photo per case
CREATE UNIQUE INDEX media_one_primary_per_case_idx
    ON media (case_id)
    WHERE is_primary = TRUE AND deleted_at IS NULL;

CREATE INDEX media_case_id_idx ON media (case_id) WHERE deleted_at IS NULL;

-- ── Broadcast records ─────────────────────────────────────────────────────────
-- Tracks every SMS / WhatsApp / email sent for a given case.

CREATE TABLE broadcast_records (
    id           UUID              PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id      UUID              NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    channel      broadcast_channel NOT NULL,
    recipient    TEXT              NOT NULL,   -- phone number or email
    message_id   TEXT,                         -- Africa's Talking / provider message ID
    status       TEXT              NOT NULL DEFAULT 'sent',  -- sent | delivered | failed
    sent_at      TIMESTAMPTZ       NOT NULL DEFAULT NOW(),
    delivered_at TIMESTAMPTZ
);

CREATE INDEX broadcast_case_id_idx   ON broadcast_records (case_id);
CREATE INDEX broadcast_message_id_idx ON broadcast_records (message_id) WHERE message_id IS NOT NULL;

-- ── Inbound tips ──────────────────────────────────────────────────────────────
-- SMS or USSD tips submitted by members of the public.

CREATE TABLE case_tips (
    id           UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id      UUID        REFERENCES cases(id) ON DELETE SET NULL,
    reference_no TEXT,                      -- raw reference from SMS if case lookup fails
    from_phone   TEXT        NOT NULL,
    message      TEXT        NOT NULL,
    channel      TEXT        NOT NULL DEFAULT 'sms',  -- sms | ussd | whatsapp
    is_reviewed  BOOLEAN     NOT NULL DEFAULT FALSE,
    reviewed_by  UUID        REFERENCES users(id),
    reviewed_at  TIMESTAMPTZ,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX tips_case_id_idx     ON case_tips (case_id) WHERE case_id IS NOT NULL;
CREATE INDEX tips_unreviewed_idx  ON case_tips (created_at) WHERE is_reviewed = FALSE;

-- ── Audit log ─────────────────────────────────────────────────────────────────
-- Immutable record of all status changes for accountability.

CREATE TABLE case_audit_log (
    id           UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id      UUID        NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    changed_by   UUID        NOT NULL REFERENCES users(id),
    field        TEXT        NOT NULL,   -- 'status' | 'resolution' | etc.
    old_value    TEXT,
    new_value    TEXT,
    note         TEXT,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX audit_log_case_id_idx ON case_audit_log (case_id);

-- Trigger: auto-insert audit row on status change
CREATE OR REPLACE FUNCTION audit_case_status_change()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status IS DISTINCT FROM NEW.status THEN
        INSERT INTO case_audit_log (case_id, changed_by, field, old_value, new_value)
        VALUES (NEW.id, NEW.updated_by, 'status', OLD.status::TEXT, NEW.status::TEXT);
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER case_status_audit
    AFTER UPDATE OF status ON cases
    FOR EACH ROW EXECUTE FUNCTION audit_case_status_change();

COMMIT;