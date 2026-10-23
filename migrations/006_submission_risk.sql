ALTER TABLE messages ADD COLUMN content_fingerprint TEXT
    CHECK (content_fingerprint IS NULL OR length(content_fingerprint) = 64);
ALTER TABLE messages ADD COLUMN risk_score INTEGER NOT NULL DEFAULT 0
    CHECK (risk_score BETWEEN 0 AND 100);
ALTER TABLE messages ADD COLUMN risk_flags TEXT NOT NULL DEFAULT '[]'
    CHECK (length(risk_flags) <= 255);

CREATE INDEX idx_messages_fingerprint_created
    ON messages(content_fingerprint, created_at DESC)
    WHERE content_fingerprint IS NOT NULL;
