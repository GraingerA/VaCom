<?php
/**
 * wurd — API. Alle acties van de app komen hier binnen als JSON.
 *
 * Aanroep: POST api.php met JSON-body {"actie": "...", ...} en de header
 * X-Wurd-Csrf. Alleen 'poll' mag ook via GET (goedkoop en vaak).
 *
 * Elk antwoord is JSON met minstens {"ok": true|false}.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/overzicht.php';

ini_set('display_errors', '0');   // waarschuwingen mogen nooit in JSON lekken
error_reporting(E_ALL);
set_error_handler(function ($nr, $tekst, $bestand, $regel) {
    error_log("wurd api: $tekst in $bestand:$regel");
    return true;
});

$ik = wurd_user();
if ($ik === null) wurd_fout('Niet ingelogd.', 401);

$invoer = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $invoer = json_decode($raw ?: '', true);
    if (!is_array($invoer)) $invoer = $_POST;
    if (!wurd_csrf_ok($_SERVER['HTTP_X_WURD_CSRF'] ?? ($invoer['csrf'] ?? null))) {
        wurd_fout('Sessie verlopen — laad de pagina opnieuw.', 403);
    }
} else {
    $invoer = $_GET;
}
$actie = (string)($invoer['actie'] ?? '');
$db = wurd_db();
$mijnId = (int)$ik['id'];

/* ---------- Helpers ---------- */

function param_int(array $in, string $k): int { return (int)($in[$k] ?? 0); }
function param_str(array $in, string $k): string { return trim((string)($in[$k] ?? '')); }

/** Is deze gebruiker lid van dit kanaal? Geeft de kanaalrij terug of stopt met 404. */
function kanaal_voor_mij(PDO $db, int $kanaalId, int $userId): array {
    $st = $db->prepare('SELECT c.* FROM channels c JOIN channel_members m ON m.channel_id = c.id AND m.user_id = ?
                        WHERE c.id = ? AND c.archived = 0');
    $st->execute([$userId, $kanaalId]);
    $k = $st->fetch();
    if (!$k) wurd_fout('Kanaal niet gevonden.', 404);
    return $k;
}

/** Berichten van één kanaal, met auteur, als lijst voor de client. */
function berichten_uit(PDO $db, string $where, array $params, int $limiet, bool $oplopend): array {
    $sql = "SELECT m.id, m.channel_id, m.user_id, m.parent_id, m.body, m.created, m.edited, m.deleted, u.name AS author
            FROM messages m JOIN users u ON u.id = m.user_id
            WHERE $where ORDER BY m.id " . ($oplopend ? 'ASC' : 'DESC') . " LIMIT $limiet";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rijen = $st->fetchAll();
    if (!$oplopend) $rijen = array_reverse($rijen);
    return array_map('bericht_uit', $rijen);
}

function bericht_uit(array $r): array {
    return [
        'id'         => (int)$r['id'],
        'channel_id' => (int)$r['channel_id'],
        'user_id'    => (int)$r['user_id'],
        'author'     => $r['author'],
        'parent_id'  => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
        'body'       => (int)$r['deleted'] ? '' : $r['body'],
        'created'    => $r['created'],
        'edited'     => $r['edited'],
        'deleted'    => (int)$r['deleted'] === 1,
    ];
}

/* ---------- Acties ---------- */

switch ($actie) {

case 'overzicht':
    wurd_json(['ok' => true] + wurd_overzicht($db, $ik));

case 'berichten': {
    // De laatste 60 berichten van een kanaal, of de 60 vóór 'before' (omhoog scrollen).
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    $before = param_int($invoer, 'before');
    $where = 'm.channel_id = ? AND m.parent_id IS NULL';
    $params = [(int)$k['id']];
    if ($before > 0) { $where .= ' AND m.id < ?'; $params[] = $before; }
    $lijst = berichten_uit($db, $where, $params, 60, false);
    wurd_json(['ok' => true, 'messages' => $lijst, 'has_more' => count($lijst) === 60]);
}

case 'poll': {
    // Het hart van "live": goedkoop als er niets is. Twee tellers: max_id (nieuw
    // bericht ergens) en state (nieuw kanaal / gebruiker). Allebei gelijk aan wat
    // de client al weet? Dan één klein antwoord en klaar.
    $maxId   = wurd_max_message_id();
    $state   = (int)wurd_meta('state');
    $sinceId = param_int($invoer, 'since_id');
    $kanaalId = param_int($invoer, 'channel_id');
    if ($maxId <= $sinceId && $state === param_int($invoer, 'state')) {
        wurd_json(['ok' => true, 'changed' => false, 'max_id' => $maxId, 'state' => $state]);
    }
    $antwoord = ['ok' => true, 'changed' => true, 'max_id' => $maxId, 'state' => $state];
    // Nieuwe berichten in het geopende kanaal meesturen (mits lid).
    if ($kanaalId > 0 && $maxId > $sinceId) {
        $st = $db->prepare('SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ?');
        $st->execute([$kanaalId, $mijnId]);
        if ($st->fetchColumn()) {
            $antwoord['messages'] = berichten_uit($db, 'm.channel_id = ? AND m.id > ? AND m.parent_id IS NULL', [$kanaalId, $sinceId], 200, true);
        }
    }
    // Ongelezen-tellers per kanaal (voor de badges in de zijbalk).
    $st = $db->prepare('SELECT c.id,
        (SELECT COUNT(*) FROM messages x WHERE x.channel_id = c.id AND x.id > m.last_read_id AND x.user_id != ? AND x.deleted = 0) AS unread
        FROM channels c JOIN channel_members m ON m.channel_id = c.id WHERE m.user_id = ? AND c.archived = 0');
    $st->execute([$mijnId, $mijnId]);
    $unread = [];
    foreach ($st->fetchAll() as $r) $unread[(string)$r['id']] = (int)$r['unread'];
    $antwoord['unread'] = $unread;
    wurd_json($antwoord);
}

case 'stuur': {
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    $body = str_replace("\r\n", "\n", param_str($invoer, 'body'));
    $body = trim($body);
    if ($body === '') wurd_fout('Leeg bericht.');
    if (mb_strlen($body) > 8000) wurd_fout('Bericht is te lang (max. 8000 tekens).');
    $db->beginTransaction();
    $db->prepare('INSERT INTO messages(channel_id, user_id, body, created) VALUES (?,?,?,?)')
       ->execute([(int)$k['id'], $mijnId, $body, wurd_now()]);
    $id = (int)$db->lastInsertId();
    // Eigen bericht telt meteen als gelezen.
    $db->prepare('UPDATE channel_members SET last_read_id = MAX(last_read_id, ?) WHERE channel_id = ? AND user_id = ?')
       ->execute([$id, (int)$k['id'], $mijnId]);
    $db->commit();
    $lijst = berichten_uit($db, 'm.id = ?', [$id], 1, true);
    wurd_json(['ok' => true, 'message' => $lijst[0], 'max_id' => wurd_max_message_id()]);
}

case 'gelezen': {
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    $tot = param_int($invoer, 'last_id');
    $db->prepare('UPDATE channel_members SET last_read_id = MAX(last_read_id, ?) WHERE channel_id = ? AND user_id = ?')
       ->execute([$tot, (int)$k['id'], $mijnId]);
    wurd_json(['ok' => true]);
}

case 'kanaal_nieuw': {
    $naam = mb_strtolower(param_str($invoer, 'name'));
    $naam = trim(preg_replace('/[^\p{L}\p{N}\-_]+/u', '-', $naam) ?? '', '-');
    if ($naam === '' || mb_strlen($naam) > 40) wurd_fout('Geef een kanaalnaam op (letters, cijfers, streepjes).');
    $topic = mb_substr(param_str($invoer, 'topic'), 0, 200);
    $st = $db->prepare("SELECT id FROM channels WHERE type = 'public' AND name = ?");
    $st->execute([$naam]);
    if ($st->fetchColumn()) wurd_fout("Kanaal #$naam bestaat al.");
    $db->beginTransaction();
    $db->prepare("INSERT INTO channels(type, name, topic, created_by, created) VALUES ('public', ?, ?, ?, ?)")
       ->execute([$naam, $topic, $mijnId, wurd_now()]);
    $id = (int)$db->lastInsertId();
    // Iedereen wordt lid: bij 25 collega's is "iedereen ziet elk kanaal" precies wat je wilt.
    $db->prepare('INSERT INTO channel_members(channel_id, user_id, last_read_id, joined) SELECT ?, id, 0, ? FROM users WHERE active = 1')
       ->execute([$id, wurd_now()]);
    wurd_bump_state();
    $db->commit();
    wurd_json(['ok' => true, 'channel_id' => $id] + wurd_overzicht($db, $ik));
}

case 'kanaal_topic': {
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    if ($k['type'] !== 'public') wurd_fout('Alleen kanalen hebben een onderwerp.');
    $db->prepare('UPDATE channels SET topic = ? WHERE id = ?')->execute([mb_substr(param_str($invoer, 'topic'), 0, 200), (int)$k['id']]);
    wurd_bump_state();
    wurd_json(['ok' => true] + wurd_overzicht($db, $ik));
}

case 'dm': {
    // DM openen met een collega: bestaat er al een, dan die; anders aanmaken.
    $ander = wurd_user_by_id(param_int($invoer, 'user_id'));
    if ($ander === null || (int)$ander['id'] === $mijnId) wurd_fout('Collega niet gevonden.');
    $sleutel = wurd_dm_key($mijnId, (int)$ander['id']);
    $st = $db->prepare('SELECT id FROM channels WHERE dm_key = ?');
    $st->execute([$sleutel]);
    $id = (int)$st->fetchColumn();
    if ($id === 0) {
        $db->beginTransaction();
        $db->prepare("INSERT INTO channels(type, dm_key, created_by, created) VALUES ('dm', ?, ?, ?)")
           ->execute([$sleutel, $mijnId, wurd_now()]);
        $id = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO channel_members(channel_id, user_id, last_read_id, joined) VALUES (?,?,0,?)');
        $ins->execute([$id, $mijnId, wurd_now()]);
        $ins->execute([$id, (int)$ander['id'], wurd_now()]);
        wurd_bump_state();
        $db->commit();
    }
    wurd_json(['ok' => true, 'channel_id' => $id] + wurd_overzicht($db, $ik));
}

case 'zoek': {
    // De W van wurd: full-text search over alle berichten in kanalen waar ik lid van ben.
    $q = mb_substr(param_str($invoer, 'q'), 0, 200);
    if ($q === '') wurd_json(['ok' => true, 'results' => []]);
    // Elke term als prefix-zoekterm, veilig tussen aanhalingstekens (FTS5-syntax).
    $termen = preg_split('/\s+/', $q) ?: [];
    $fts = implode(' ', array_map(fn($t) => '"' . str_replace('"', '""', $t) . '"*', $termen));
    $st = $db->prepare(<<<'SQL'
SELECT m.id, m.channel_id, m.user_id, m.parent_id, m.body, m.created, m.edited, m.deleted, u.name AS author,
       snippet(messages_fts, 0, char(1), char(2), '…', 14) AS snippet
FROM messages_fts f
JOIN messages m ON m.id = f.rowid
JOIN users u ON u.id = m.user_id
JOIN channel_members cm ON cm.channel_id = m.channel_id AND cm.user_id = ?
WHERE messages_fts MATCH ? AND m.deleted = 0
ORDER BY m.id DESC LIMIT 50
SQL);
    try {
        $st->execute([$mijnId, $fts]);
        $rijen = $st->fetchAll();
    } catch (Throwable $e) {
        $rijen = [];
    }
    $resultaten = [];
    foreach ($rijen as $r) {
        $b = bericht_uit($r);
        $b['snippet'] = $r['snippet'];
        $resultaten[] = $b;
    }
    wurd_json(['ok' => true, 'results' => $resultaten]);
}

case 'uitloggen':
    wurd_logout();
    wurd_json(['ok' => true]);

default:
    wurd_fout('Onbekende actie.', 404);
}
