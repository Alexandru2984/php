CREATE TABLE IF NOT EXISTS rate_limits (
    scope             TEXT NOT NULL,
    subject_hash      TEXT NOT NULL,
    window_started_at INTEGER NOT NULL,
    attempts          INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    penalty_level     INTEGER NOT NULL DEFAULT 0 CHECK (penalty_level BETWEEN 0 AND 8),
    blocked_until     INTEGER NOT NULL DEFAULT 0,
    updated_at        INTEGER NOT NULL,
    PRIMARY KEY (scope, subject_hash)
);

CREATE INDEX IF NOT EXISTS idx_rate_limits_updated_at
    ON rate_limits(updated_at);

CREATE TABLE IF NOT EXISTS security_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type   TEXT NOT NULL,
    outcome      TEXT NOT NULL CHECK (outcome IN ('success', 'failure', 'blocked')),
    subject_hash TEXT,
    related_id   INTEGER,
    created_at   INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_security_events_type_created
    ON security_events(event_type, created_at);
