<?php
/**
 * VaCom — API. Alle acties van de app komen hier binnen als JSON.
 *
 * Aanroep: POST api.php met JSON-body {"actie": "...", ...} en de header
 * X-VaCom-Csrf. Alleen 'poll' mag ook via GET (goedkoop en vaak).
 *
 * Elk antwoord is JSON met minstens {"ok": true|false}.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/overzicht.php';

ini_set('display_errors', '0');   // waarschuwingen mogen nooit in JSON lekken
error_reporting(E_ALL);
set_error_handler(function ($nr, $tekst, $bestand, $regel) {
    error_log("VaCom api: $tekst in $bestand:$regel");
    return true;
});

$ik = vacom_user();
if ($ik === null) vacom_fout('Niet ingelogd.', 401);

$invoer = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $invoer = json_decode($raw ?: '', true);
    if (!is_array($invoer)) $invoer = $_POST;
    if (!vacom_csrf_ok($_SERVER['HTTP_X_VACOM_CSRF'] ?? ($invoer['csrf'] ?? null))) {
        vacom_fout('Sessie verlopen — laad de pagina opnieuw.', 403);
    }
} else {
    $invoer = $_GET;
}
$actie = (string)($invoer['actie'] ?? '');
$db = vacom_db();
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
    if (!$k) vacom_fout('Kanaal niet gevonden.', 404);
    return $k;
}

/** Reacties bij een set berichten: map bericht-id => [{emoji, count, user_ids}]. */
function reacties_bij(PDO $db, array $ids): array {
    if (!$ids) return [];
    $vraag = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT message_id, emoji, user_id FROM reactions WHERE message_id IN ($vraag) ORDER BY created, rowid");
    $st->execute(array_map('intval', $ids));
    $per = [];
    foreach ($st->fetchAll() as $r) {
        $mid = (int)$r['message_id'];
        $per[$mid][$r['emoji']]['user_ids'][] = (int)$r['user_id'];
    }
    $uit = [];
    foreach ($per as $mid => $emojis) {
        foreach ($emojis as $emoji => $d) {
            $uit[$mid][] = ['emoji' => (string)$emoji, 'count' => count($d['user_ids']), 'user_ids' => $d['user_ids']];
        }
    }
    return $uit;
}

/** Berichten van één kanaal, met auteur en reacties, als lijst voor de client. */
function berichten_uit(PDO $db, string $where, array $params, int $limiet, bool $oplopend): array {
    $sql = "SELECT m.id, m.channel_id, m.user_id, m.parent_id, m.body, m.created, m.edited, m.deleted, u.name AS author
            FROM messages m JOIN users u ON u.id = m.user_id
            WHERE $where ORDER BY m.id " . ($oplopend ? 'ASC' : 'DESC') . " LIMIT $limiet";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rijen = $st->fetchAll();
    if (!$oplopend) $rijen = array_reverse($rijen);
    $lijst = array_map('bericht_uit', $rijen);
    $reacties = reacties_bij($db, array_column($lijst, 'id'));
    foreach ($lijst as &$m) $m['reactions'] = $reacties[$m['id']] ?? [];
    unset($m);
    return $lijst;
}

/** Mag deze tekst als reactie-emoji? Kort, en alleen emoji-achtige tekens. */
function emoji_ok(string $e): bool {
    if ($e === '' || mb_strlen($e) > 8) return false;
    // Emoji-blokken + samenstellers (huidskleur, ZWJ, variatie-selector, vlaggen).
    return (bool)preg_match('/^[\x{1F000}-\x{1FAFF}\x{2190}-\x{2BFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}\x{2764}\x{203C}\x{2049}\x{3030}\x{00A9}\x{00AE}\x{2122}]+$/u', $e);
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
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));

case 'berichten': {
    // De laatste 60 berichten van een kanaal, of de 60 vóór 'before' (omhoog scrollen).
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    $before = param_int($invoer, 'before');
    $where = 'm.channel_id = ? AND m.parent_id IS NULL';
    $params = [(int)$k['id']];
    if ($before > 0) { $where .= ' AND m.id < ?'; $params[] = $before; }
    $lijst = berichten_uit($db, $where, $params, 60, false);
    vacom_json(['ok' => true, 'messages' => $lijst, 'has_more' => count($lijst) === 60]);
}

case 'poll': {
    // Het hart van "live": goedkoop als er niets is. Drie tellers: max_id (nieuw
    // bericht ergens), state (nieuw kanaal / gebruiker) en rx (reacties — die
    // maken geen nieuw bericht-id aan en hebben dus een eigen teller, v0.3).
    // Alles gelijk aan wat de client al weet? Dan één klein antwoord en klaar.
    $maxId   = vacom_max_message_id();
    $state   = (int)vacom_meta('state');
    $rx      = (int)vacom_meta('rx');
    $sinceId = param_int($invoer, 'since_id');
    $kanaalId = param_int($invoer, 'channel_id');
    if ($maxId <= $sinceId && $state === param_int($invoer, 'state') && $rx === param_int($invoer, 'rx')) {
        vacom_json(['ok' => true, 'changed' => false, 'max_id' => $maxId, 'state' => $state, 'rx' => $rx]);
    }
    $antwoord = ['ok' => true, 'changed' => true, 'max_id' => $maxId, 'state' => $state, 'rx' => $rx];
    $isLid = false;
    if ($kanaalId > 0) {
        $st = $db->prepare('SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ?');
        $st->execute([$kanaalId, $mijnId]);
        $isLid = (bool)$st->fetchColumn();
    }
    // Nieuwe berichten in het geopende kanaal meesturen (mits lid).
    if ($isLid && $maxId > $sinceId) {
        $antwoord['messages'] = berichten_uit($db, 'm.channel_id = ? AND m.id > ? AND m.parent_id IS NULL', [$kanaalId, $sinceId], 200, true);
    }
    // Reacties veranderd? Stuur de actuele reactie-sets van de laatste 100
    // berichten van het open kanaal mee; de client werkt bij wat hij kent.
    if ($isLid && $rx !== param_int($invoer, 'rx')) {
        $st = $db->prepare('SELECT id FROM messages WHERE channel_id = ? AND parent_id IS NULL ORDER BY id DESC LIMIT 100');
        $st->execute([$kanaalId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $sets = reacties_bij($db, $ids);
        // Ook lege sets meesturen: een wéggehaalde laatste reactie moet ook aankomen.
        $antwoord['reactions'] = ['ids' => $ids, 'sets' => (object)$sets];
    }
    // Ongelezen-tellers per kanaal (voor de badges in de zijbalk).
    $st = $db->prepare('SELECT c.id,
        (SELECT COUNT(*) FROM messages x WHERE x.channel_id = c.id AND x.id > m.last_read_id AND x.user_id != ? AND x.deleted = 0) AS unread
        FROM channels c JOIN channel_members m ON m.channel_id = c.id WHERE m.user_id = ? AND c.archived = 0');
    $st->execute([$mijnId, $mijnId]);
    $unread = [];
    foreach ($st->fetchAll() as $r) $unread[(string)$r['id']] = (int)$r['unread'];
    $antwoord['unread'] = $unread;
    vacom_json($antwoord);
}

case 'stuur': {
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    $body = str_replace("\r\n", "\n", param_str($invoer, 'body'));
    $body = trim($body);
    if ($body === '') vacom_fout('Leeg bericht.');
    if (mb_strlen($body) > 8000) vacom_fout('Bericht is te lang (max. 8000 tekens).');
    $db->beginTransaction();
    $db->prepare('INSERT INTO messages(channel_id, user_id, body, created) VALUES (?,?,?,?)')
       ->execute([(int)$k['id'], $mijnId, $body, vacom_now()]);
    $id = (int)$db->lastInsertId();
    // Eigen bericht telt meteen als gelezen.
    $db->prepare('UPDATE channel_members SET last_read_id = MAX(last_read_id, ?) WHERE channel_id = ? AND user_id = ?')
       ->execute([$id, (int)$k['id'], $mijnId]);
    $db->commit();
    $lijst = berichten_uit($db, 'm.id = ?', [$id], 1, true);
    vacom_json(['ok' => true, 'message' => $lijst[0], 'max_id' => vacom_max_message_id()]);
}

case 'reageer': {
    // Emoji-reactie op een bericht aan- of uitzetten (v0.3).
    $berichtId = param_int($invoer, 'message_id');
    $emoji     = param_str($invoer, 'emoji');
    $aan       = param_int($invoer, 'value') === 1;
    if (!emoji_ok($emoji)) vacom_fout('Ongeldige emoji.');
    $st = $db->prepare('SELECT channel_id, deleted FROM messages WHERE id = ?');
    $st->execute([$berichtId]);
    $m = $st->fetch();
    if (!$m || (int)$m['deleted'] === 1) vacom_fout('Bericht niet gevonden.', 404);
    kanaal_voor_mij($db, (int)$m['channel_id'], $mijnId); // lidmaatschap-check
    if ($aan) {
        // Max 20 verschillende emoji per bericht — houdt het leesbaar en
        // voorkomt dat iemand een bericht volplempt.
        $st = $db->prepare('SELECT COUNT(DISTINCT emoji) FROM reactions WHERE message_id = ?');
        $st->execute([$berichtId]);
        $st2 = $db->prepare('SELECT 1 FROM reactions WHERE message_id = ? AND emoji = ? LIMIT 1');
        $st2->execute([$berichtId, $emoji]);
        if ((int)$st->fetchColumn() >= 20 && !$st2->fetchColumn()) vacom_fout('Genoeg emoji op dit bericht. :-)');
        $db->prepare('INSERT OR IGNORE INTO reactions(message_id, user_id, emoji, created) VALUES (?,?,?,?)')
           ->execute([$berichtId, $mijnId, $emoji, vacom_now()]);
    } else {
        $db->prepare('DELETE FROM reactions WHERE message_id = ? AND user_id = ? AND emoji = ?')
           ->execute([$berichtId, $mijnId, $emoji]);
    }
    $db->exec("UPDATE meta SET value = CAST(value AS INTEGER) + 1 WHERE key = 'rx'");
    $sets = reacties_bij($db, [$berichtId]);
    vacom_json(['ok' => true, 'message_id' => $berichtId, 'reactions' => $sets[$berichtId] ?? [], 'rx' => (int)vacom_meta('rx')]);
}

case 'gelezen': {
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    $tot = param_int($invoer, 'last_id');
    $db->prepare('UPDATE channel_members SET last_read_id = MAX(last_read_id, ?) WHERE channel_id = ? AND user_id = ?')
       ->execute([$tot, (int)$k['id'], $mijnId]);
    vacom_json(['ok' => true]);
}

case 'kanaal_nieuw': {
    $naam = mb_strtolower(param_str($invoer, 'name'));
    $naam = trim(preg_replace('/[^\p{L}\p{N}\-_]+/u', '-', $naam) ?? '', '-');
    if ($naam === '' || mb_strlen($naam) > 40) vacom_fout('Geef een kanaalnaam op (letters, cijfers, streepjes).');
    $topic = mb_substr(param_str($invoer, 'topic'), 0, 200);
    $st = $db->prepare("SELECT id FROM channels WHERE type = 'public' AND name = ?");
    $st->execute([$naam]);
    if ($st->fetchColumn()) vacom_fout("Kanaal #$naam bestaat al.");
    $db->beginTransaction();
    $db->prepare("INSERT INTO channels(type, name, topic, created_by, created) VALUES ('public', ?, ?, ?, ?)")
       ->execute([$naam, $topic, $mijnId, vacom_now()]);
    $id = (int)$db->lastInsertId();
    // Iedereen wordt lid: bij 25 collega's is "iedereen ziet elk kanaal" precies wat je wilt.
    $db->prepare('INSERT INTO channel_members(channel_id, user_id, last_read_id, joined) SELECT ?, id, 0, ? FROM users WHERE active = 1')
       ->execute([$id, vacom_now()]);
    vacom_bump_state();
    $db->commit();
    vacom_json(['ok' => true, 'channel_id' => $id] + vacom_overzicht($db, $ik));
}

case 'kanaal_topic': {
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    if ($k['type'] !== 'public') vacom_fout('Alleen kanalen hebben een onderwerp.');
    $db->prepare('UPDATE channels SET topic = ? WHERE id = ?')->execute([mb_substr(param_str($invoer, 'topic'), 0, 200), (int)$k['id']]);
    vacom_bump_state();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

/* ---- Kanaal-opties (v0.4): leden, toevoegen, verlaten, sluiten, verwijderen ---- */

case 'kanaal_leden': {
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    if ($k['type'] !== 'public') vacom_fout('Een gesprek heeft geen ledenlijst.');
    $st = $db->prepare('SELECT u.id, u.name, m.via FROM channel_members m JOIN users u ON u.id = m.user_id
                        WHERE m.channel_id = ? AND u.active = 1 ORDER BY u.name COLLATE NOCASE');
    $st->execute([(int)$k['id']]);
    $leden = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'via' => $r['via']], $st->fetchAll());
    $isProject = $k['project_token'] !== null && $k['project_token'] !== '';
    vacom_json([
        'ok'          => true,
        'members'     => $leden,
        'is_project'  => $isProject,
        'kan_sluiten' => !$isProject && (vacom_is_superadmin() || (int)$k['created_by'] === $mijnId),
        'kan_wissen'  => !$isProject && vacom_is_superadmin(),
    ]);
}

case 'lid_toevoegen': {
    // Ieder lid mag een collega toevoegen. Wie zo binnenkomt is 'handmatig'
    // lid en blijft dat, ook als de Portal-koppeling hem niet kent.
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    if ($k['type'] !== 'public') vacom_fout('Aan een gesprek kun je niemand toevoegen.');
    $ander = vacom_user_by_id(param_int($invoer, 'user_id'));
    if ($ander === null || (int)$ander['active'] !== 1) vacom_fout('Collega niet gevonden.');
    $db->prepare("INSERT OR IGNORE INTO channel_members(channel_id, user_id, last_read_id, joined, via) VALUES (?,?,?,?,'handmatig')")
       ->execute([(int)$k['id'], (int)$ander['id'], vacom_max_message_id(), vacom_now()]);
    vacom_bump_state();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

case 'lid_verwijderen': {
    // Jezelf (verlaten) mag altijd; een ander alleen als superadmin. Wie via
    // de Portal gekoppeld is, komt bij de volgende sync gewoon terug — dat
    // regel je in de Portal, niet hier.
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    if ($k['type'] !== 'public') vacom_fout('Een gesprek verlaat je niet.');
    $wie = param_int($invoer, 'user_id') ?: $mijnId;
    if ($wie !== $mijnId && !vacom_is_superadmin()) vacom_fout('Alleen de superadmin kan iemand anders verwijderen.', 403);
    $st = $db->prepare('SELECT via FROM channel_members WHERE channel_id = ? AND user_id = ?');
    $st->execute([(int)$k['id'], $wie]);
    $via = $st->fetchColumn();
    if ($via === false) vacom_fout('Geen lid van dit kanaal.');
    if ($via === 'portal') vacom_fout('Deze collega is via de Portal aan het project gekoppeld; ontkoppelen doe je daar.');
    if ($via === 'auto' && ($k['project_token'] === null || $k['project_token'] === '')) {
        vacom_fout('Van een algemeen kanaal is iedereen lid; dat kun je niet verlaten.');
    }
    $db->prepare('DELETE FROM channel_members WHERE channel_id = ? AND user_id = ?')->execute([(int)$k['id'], $wie]);
    vacom_bump_state();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

case 'kanaal_sluiten': {
    // Archiveren: uit de zijbalk, wél doorzoekbaar. Alleen losse kanalen;
    // projectkanalen volgen het project in de Portal.
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    if ($k['type'] !== 'public' || ($k['project_token'] !== null && $k['project_token'] !== '')) vacom_fout('Een projectkanaal sluit je via de Portal.');
    if (!vacom_is_superadmin() && (int)$k['created_by'] !== $mijnId) vacom_fout('Alleen de superadmin of wie het kanaal aanmaakte kan het sluiten.', 403);
    $db->prepare('UPDATE channels SET archived = 1 WHERE id = ?')->execute([(int)$k['id']]);
    vacom_bump_state();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

case 'kanaal_verwijderen': {
    // Definitief weg, inclusief alle berichten. Superadmin, losse kanalen.
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    if (!vacom_is_superadmin()) vacom_fout('Alleen de superadmin kan een kanaal verwijderen.', 403);
    if ($k['type'] !== 'public' || ($k['project_token'] !== null && $k['project_token'] !== '')) vacom_fout('Een projectkanaal verwijder je via de Portal.');
    $db->beginTransaction();
    $db->prepare('DELETE FROM reactions WHERE message_id IN (SELECT id FROM messages WHERE channel_id = ?)')->execute([(int)$k['id']]);
    $db->prepare('DELETE FROM messages WHERE channel_id = ?')->execute([(int)$k['id']]);
    $db->prepare('DELETE FROM channel_members WHERE channel_id = ?')->execute([(int)$k['id']]);
    $db->prepare('DELETE FROM channels WHERE id = ?')->execute([(int)$k['id']]);
    vacom_bump_state();
    $db->commit();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

case 'dm': {
    // DM openen met een collega: bestaat er al een, dan die; anders aanmaken.
    $ander = vacom_user_by_id(param_int($invoer, 'user_id'));
    if ($ander === null || (int)$ander['id'] === $mijnId) vacom_fout('Collega niet gevonden.');
    $sleutel = vacom_dm_key($mijnId, (int)$ander['id']);
    $st = $db->prepare('SELECT id FROM channels WHERE dm_key = ?');
    $st->execute([$sleutel]);
    $id = (int)$st->fetchColumn();
    if ($id === 0) {
        $db->beginTransaction();
        $db->prepare("INSERT INTO channels(type, dm_key, created_by, created) VALUES ('dm', ?, ?, ?)")
           ->execute([$sleutel, $mijnId, vacom_now()]);
        $id = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO channel_members(channel_id, user_id, last_read_id, joined) VALUES (?,?,0,?)');
        $ins->execute([$id, $mijnId, vacom_now()]);
        $ins->execute([$id, (int)$ander['id'], vacom_now()]);
        vacom_bump_state();
        $db->commit();
    }
    vacom_json(['ok' => true, 'channel_id' => $id] + vacom_overzicht($db, $ik));
}

case 'zoek': {
    // Full-text search over alle berichten in kanalen waar ik lid van ben.
    $q = mb_substr(param_str($invoer, 'q'), 0, 200);
    if ($q === '') vacom_json(['ok' => true, 'results' => []]);
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
    vacom_json(['ok' => true, 'results' => $resultaten]);
}

case 'uitloggen':
    vacom_logout();
    vacom_json(['ok' => true]);

default:
    vacom_fout('Onbekende actie.', 404);
}
