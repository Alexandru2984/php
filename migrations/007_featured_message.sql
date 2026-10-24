ALTER TABLE messages ADD COLUMN featured_at TEXT;
ALTER TABLE messages ADD COLUMN feature_changed_at TEXT;

CREATE UNIQUE INDEX idx_messages_single_featured
    ON messages((1))
    WHERE featured_at IS NOT NULL;

-- Keep the migration compatible with the preceding release: if older application
-- code moderates a featured row, the database clears the presentation flag instead
-- of rejecting the whole moderation statement.
CREATE TRIGGER messages_featured_public_after_insert
AFTER INSERT ON messages
WHEN new.featured_at IS NOT NULL AND new.status != 'published'
BEGIN
    UPDATE messages
    SET featured_at = NULL,
        feature_changed_at = CURRENT_TIMESTAMP
    WHERE id = new.id;
END;

CREATE TRIGGER messages_featured_public_after_update
AFTER UPDATE OF featured_at, status ON messages
WHEN new.featured_at IS NOT NULL AND new.status != 'published'
BEGIN
    UPDATE messages
    SET featured_at = NULL,
        feature_changed_at = CURRENT_TIMESTAMP
    WHERE id = new.id;
END;
