ALTER TABLE messages ADD COLUMN moderation_reason TEXT;
ALTER TABLE messages ADD COLUMN moderated_at TEXT;

CREATE TABLE admin_audit_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type     TEXT NOT NULL,
    outcome        TEXT NOT NULL CHECK (outcome IN ('success', 'failure', 'blocked')),
    actor_hash     TEXT NOT NULL,
    related_id     INTEGER,
    previous_state TEXT,
    new_state      TEXT,
    reason_code    TEXT,
    created_at     INTEGER NOT NULL
);

CREATE INDEX idx_admin_audit_created
    ON admin_audit_events(created_at DESC, id DESC);

CREATE TRIGGER admin_audit_events_no_update
BEFORE UPDATE ON admin_audit_events
BEGIN
    SELECT RAISE(ABORT, 'admin audit events are append-only');
END;

CREATE TRIGGER admin_audit_events_no_delete
BEFORE DELETE ON admin_audit_events
BEGIN
    SELECT RAISE(ABORT, 'admin audit events are append-only');
END;

CREATE TABLE abuse_reports (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id  INTEGER NOT NULL REFERENCES messages(id) ON DELETE RESTRICT,
    category    TEXT NOT NULL CHECK (category IN ('spam', 'harassment', 'privacy', 'other')),
    status      TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'resolved', 'dismissed')),
    created_at  INTEGER NOT NULL,
    reviewed_at INTEGER
);

CREATE INDEX idx_abuse_reports_status_created
    ON abuse_reports(status, created_at ASC, id ASC);

CREATE INDEX idx_abuse_reports_message
    ON abuse_reports(message_id, status);
