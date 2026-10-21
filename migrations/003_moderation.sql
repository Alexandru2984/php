ALTER TABLE messages ADD COLUMN status TEXT NOT NULL DEFAULT 'published';
ALTER TABLE messages ADD COLUMN deleted_at TEXT;

CREATE INDEX IF NOT EXISTS idx_messages_status_created
    ON messages(status, created_at DESC, id DESC);
