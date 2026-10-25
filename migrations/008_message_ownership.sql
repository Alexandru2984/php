ALTER TABLE messages ADD COLUMN owner_token_hash TEXT
    CHECK (owner_token_hash IS NULL OR length(owner_token_hash) = 64);
ALTER TABLE messages ADD COLUMN owner_withdrawn_at TEXT;

CREATE UNIQUE INDEX idx_messages_owner_token_hash
    ON messages(owner_token_hash)
    WHERE owner_token_hash IS NOT NULL;

-- An author's withdrawal is intentionally irreversible through ordinary
-- moderation, including if the application is temporarily rolled back.
CREATE TRIGGER messages_owner_withdrawal_no_publish
BEFORE UPDATE OF owner_withdrawn_at, status ON messages
WHEN old.owner_withdrawn_at IS NOT NULL AND new.status = 'published'
BEGIN
    SELECT RAISE(ABORT, 'owner-withdrawn messages cannot be published');
END;

CREATE TRIGGER messages_owner_withdrawal_no_insert_publish
BEFORE INSERT ON messages
WHEN new.owner_withdrawn_at IS NOT NULL AND new.status = 'published'
BEGIN
    SELECT RAISE(ABORT, 'owner-withdrawn messages cannot be published');
END;

CREATE TRIGGER messages_owner_withdrawal_marker_immutable
BEFORE UPDATE OF owner_withdrawn_at ON messages
WHEN old.owner_withdrawn_at IS NOT NULL AND new.owner_withdrawn_at IS NULL
BEGIN
    SELECT RAISE(ABORT, 'owner withdrawal marker is immutable');
END;
