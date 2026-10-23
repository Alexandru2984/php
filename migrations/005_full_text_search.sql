CREATE VIRTUAL TABLE messages_fts USING fts5(
    author,
    content,
    content = 'messages',
    content_rowid = 'id',
    tokenize = 'unicode61 remove_diacritics 2',
    prefix = '2 3 4'
);

CREATE TRIGGER messages_fts_after_insert
AFTER INSERT ON messages
BEGIN
    INSERT INTO messages_fts(rowid, author, content)
    VALUES (new.id, new.author, new.content);
END;

CREATE TRIGGER messages_fts_after_delete
AFTER DELETE ON messages
BEGIN
    INSERT INTO messages_fts(messages_fts, rowid, author, content)
    VALUES ('delete', old.id, old.author, old.content);
END;

CREATE TRIGGER messages_fts_after_update
AFTER UPDATE OF author, content ON messages
BEGIN
    INSERT INTO messages_fts(messages_fts, rowid, author, content)
    VALUES ('delete', old.id, old.author, old.content);
    INSERT INTO messages_fts(rowid, author, content)
    VALUES (new.id, new.author, new.content);
END;

INSERT INTO messages_fts(messages_fts) VALUES ('rebuild');
