<?php
/**
 * VaCom — database (SQLite).
 *
 * Eén bestand in de datamap: vacom.sqlite. WAL-modus zodat lezen en schrijven
 * elkaar niet blokkeren, en FTS5 voor het zoeken door alle berichten.
 * Het schema wordt hier beheerd met een versienummer (PRAGMA user_version);
 * een nieuwe versie = een nieuw blok in vacom_migreer(). Zo hoeft er nooit
 * handmatig iets aan de database op de server te gebeuren.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function vacom_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    vacom_ensure_data_dir();
    $pdo = new PDO('sqlite:' . VACOM_DATA . '/vacom.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    vacom_migreer($pdo);
    return $pdo;
}

function vacom_migreer(PDO $pdo): void {
    $versie = (int)$pdo->query('PRAGMA user_version')->fetchColumn();

    if ($versie < 1) {
        $pdo->exec(<<<'SQL'
CREATE TABLE users (
    id            INTEGER PRIMARY KEY,
    name          TEXT    NOT NULL,
    name_key      TEXT    NOT NULL UNIQUE,
    email         TEXT    NOT NULL DEFAULT '',
    role          TEXT    NOT NULL DEFAULT 'lid',      -- 'superadmin' | 'lid'
    password_hash TEXT    NOT NULL,
    created       TEXT    NOT NULL,
    last_seen     TEXT,
    active        INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE channels (
    id         INTEGER PRIMARY KEY,
    type       TEXT    NOT NULL,                      -- 'public' | 'dm'
    name       TEXT    NOT NULL DEFAULT '',
    topic      TEXT    NOT NULL DEFAULT '',
    dm_key     TEXT    UNIQUE,                        -- 'kleinsteId:grootsteId' bij een DM
    created_by INTEGER,
    created    TEXT    NOT NULL,
    archived   INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE channel_members (
    channel_id   INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    last_read_id INTEGER NOT NULL DEFAULT 0,
    joined       TEXT    NOT NULL,
    PRIMARY KEY (channel_id, user_id)
);

CREATE TABLE messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,     -- oplopend over alle kanalen: de 'klok' voor polling
    channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    parent_id  INTEGER REFERENCES messages(id),       -- voor threads (later)
    body       TEXT    NOT NULL,
    created    TEXT    NOT NULL,
    edited     TEXT,
    deleted    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_messages_channel ON messages(channel_id, id);

CREATE VIRTUAL TABLE messages_fts USING fts5(
    body,
    content='messages', content_rowid='id',
    tokenize='unicode61 remove_diacritics 2'
);
CREATE TRIGGER messages_ai AFTER INSERT ON messages BEGIN
    INSERT INTO messages_fts(rowid, body) VALUES (new.id, new.body);
END;
CREATE TRIGGER messages_ad AFTER DELETE ON messages BEGIN
    INSERT INTO messages_fts(messages_fts, rowid, body) VALUES ('delete', old.id, old.body);
END;
CREATE TRIGGER messages_au AFTER UPDATE OF body ON messages BEGIN
    INSERT INTO messages_fts(messages_fts, rowid, body) VALUES ('delete', old.id, old.body);
    INSERT INTO messages_fts(rowid, body) VALUES (new.id, new.body);
END;

CREATE TABLE reactions (
    message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    emoji      TEXT    NOT NULL,
    created    TEXT    NOT NULL,
    PRIMARY KEY (message_id, user_id, emoji)
);

-- Teller die verandert bij alles wat níét een nieuw bericht is (nieuw kanaal,
-- nieuwe gebruiker, ...). De client vergelijkt deze bij elke poll en haalt het
-- overzicht opnieuw op als hij verschilt.
CREATE TABLE meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
INSERT INTO meta(key, value) VALUES ('state', '1');

CREATE TABLE ratelimit (
    key    TEXT    PRIMARY KEY,
    count  INTEGER NOT NULL,
    reset  INTEGER NOT NULL
);

PRAGMA user_version = 1;
SQL);
    }
}

/* ---------- Kleine helpers rond de database ---------- */

function vacom_meta(string $key): string {
    $st = vacom_db()->prepare('SELECT value FROM meta WHERE key = ?');
    $st->execute([$key]);
    return (string)($st->fetchColumn() ?: '');
}

/** Verhoog de state-teller: clients halen daarna hun overzicht opnieuw op. */
function vacom_bump_state(): void {
    vacom_db()->exec("UPDATE meta SET value = CAST(value AS INTEGER) + 1 WHERE key = 'state'");
}

function vacom_max_message_id(): int {
    return (int)vacom_db()->query('SELECT COALESCE(MAX(id), 0) FROM messages')->fetchColumn();
}

/** Sleutel voor een DM tussen twee gebruikers, onafhankelijk van wie begon. */
function vacom_dm_key(int $a, int $b): string {
    return min($a, $b) . ':' . max($a, $b);
}
