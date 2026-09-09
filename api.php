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

/** Kleine HTTPS-GET (v0.5.3, voor Giphy): cURL als die er is, anders file_get_contents. Korte timeout. */
function vacom_http_get(string $url): string {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
        $uit = curl_exec($c);
        curl_close($c);
        return is_string($uit) ? $uit : '';
    }
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $uit = @file_get_contents($url, false, $ctx);
    return is_string($uit) ? $uit : '';
}

/**
 * Is deze gebruiker lid van dit kanaal? Geeft de kanaalrij terug of stopt met 404.
 * v0.4.1: een gesloten (gearchiveerd) kanaal is zoals in Slack alleen-lezen.
 * Lees-acties geven $ookGesloten = true mee; schrijf-acties niet en krijgen
 * dan een duidelijke melding i.p.v. een raadselachtig "niet gevonden".
 */
function kanaal_voor_mij(PDO $db, int $kanaalId, int $userId, bool $ookGesloten = false): array {
    $st = $db->prepare('SELECT c.* FROM channels c JOIN channel_members m ON m.channel_id = c.id AND m.user_id = ?
                        WHERE c.id = ?');
    $st->execute([$userId, $kanaalId]);
    $k = $st->fetch();
    if (!$k) vacom_fout('Kanaal niet gevonden.', 404);
    if ((int)$k['archived'] === 1 && !$ookGesloten) vacom_fout('Dit kanaal is gesloten — je kunt het alleen nog lezen.', 403);
    return $k;
}

/** Mag deze gebruiker een los kanaal sluiten of heropenen? (superadmin of de aanmaker) */
function mag_kanaal_beheren(array $k, int $userId): bool {
    return vacom_is_superadmin() || (int)$k['created_by'] === $userId;
}

function is_projectkanaal(array $k): bool {
    return $k['project_token'] !== null && $k['project_token'] !== '';
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
    $sql = "SELECT m.id, m.channel_id, m.user_id, m.parent_id, m.body, m.created, m.edited, m.deleted, m.attachment, u.name AS author
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
        // v0.6: bijlage als array, of null (geen bijlage / bericht verwijderd)
        'attachment' => ((int)$r['deleted'] === 1 || empty($r['attachment'])) ? null : (json_decode((string)$r['attachment'], true) ?: null),
    ];
}

/* ---- Bijlagen (v0.6) ---- */

/** Map waarin de bijlage van een bericht staat (buiten de webroot). */
function bijlage_map(int $berichtId): string {
    return VACOM_DATA . '/bestanden/' . $berichtId;
}

/** Bestandsnaam veilig maken: alleen de basisnaam, geen rare tekens, niet te lang. */
function bijlage_naam(string $naam): string {
    $naam = basename(str_replace('\\', '/', $naam));
    $naam = preg_replace('/[\x00-\x1F\x7F\/:*?"<>|]+/', '_', $naam) ?? '';
    $naam = trim($naam, ' .');
    if (mb_strlen($naam) > 120) {
        $ext  = pathinfo($naam, PATHINFO_EXTENSION);
        $naam = mb_substr(pathinfo($naam, PATHINFO_FILENAME), 0, 100) . ($ext !== '' ? '.' . $ext : '');
    }
    return $naam !== '' ? $naam : 'bestand';
}

/** Hele bijlage-map van een bericht weg (bij verwijderen). */
function bijlage_wis(int $berichtId): void {
    $map = bijlage_map($berichtId);
    if (!is_dir($map)) return;
    foreach (scandir($map) ?: [] as $f) { if ($f !== '.' && $f !== '..') @unlink($map . '/' . $f); }
    @rmdir($map);
}

/** Bijlagen van álle berichten in een kanaal weg (kanaal definitief verwijderen). */
function bijlagen_wis_kanaal(PDO $db, int $kanaalId): void {
    $st = $db->prepare('SELECT id FROM messages WHERE channel_id = ? AND attachment IS NOT NULL');
    $st->execute([$kanaalId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) bijlage_wis((int)$id);
}

/**
 * Thumbnail (JPEG, max. $max px aan de lange kant) van een foto. Draait iPhone-
 * foto's recht via de EXIF-oriëntatie. Geeft [breedte, hoogte] terug, of null
 * als het niet lukt (geen GD, exotisch formaat, te groot voor het geheugen).
 */
function maak_thumbnail(string $bron, string $doel, int $max, array $info): ?array {
    if (!function_exists('imagecreatetruecolor')) return null;
    [$w, $h, $type] = $info;
    if ($w * $h > 40_000_000) return null; // > 40 megapixel: niet aan beginnen
    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($bron),
        IMAGETYPE_PNG  => @imagecreatefrompng($bron),
        IMAGETYPE_GIF  => @imagecreatefromgif($bron),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($bron) : false,
        default        => false,
    };
    if (!$src) return null;
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($bron)['Orientation'] ?? 1);
        if ($o === 3)     $src = imagerotate($src, 180, 0);
        elseif ($o === 6) $src = imagerotate($src, -90, 0);
        elseif ($o === 8) $src = imagerotate($src, 90, 0);
        if ($o === 6 || $o === 8) [$w, $h] = [$h, $w];
    }
    $schaal = min(1, $max / max($w, $h));
    $tw = max(1, (int)round($w * $schaal)); $th = max(1, (int)round($h * $schaal));
    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // transparantie → wit
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    $ok = imagejpeg($dst, $doel, 82);
    imagedestroy($src); imagedestroy($dst);
    return $ok ? [$tw, $th] : null;
}

/* ---------- Acties ---------- */

switch ($actie) {

case 'overzicht':
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));

case 'berichten': {
    // De laatste 60 berichten van een kanaal, of de 60 vóór 'before' (omhoog scrollen).
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId, true); // ook gesloten: lezen mag
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
    $mx      = (int)vacom_meta('mx'); // v0.6: bewerkt/verwijderd
    $sinceId = param_int($invoer, 'since_id');
    $kanaalId = param_int($invoer, 'channel_id');
    if ($maxId <= $sinceId && $state === param_int($invoer, 'state') && $rx === param_int($invoer, 'rx') && $mx === param_int($invoer, 'mx')) {
        vacom_json(['ok' => true, 'changed' => false, 'max_id' => $maxId, 'state' => $state, 'rx' => $rx, 'mx' => $mx]);
    }
    $antwoord = ['ok' => true, 'changed' => true, 'max_id' => $maxId, 'state' => $state, 'rx' => $rx, 'mx' => $mx];
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
    // v0.6: bericht bewerkt of verwijderd? Stuur tekst/bijlage-status van de
    // laatste 100 berichten van het open kanaal mee; de client werkt bij wat hij kent.
    if ($isLid && $mx !== param_int($invoer, 'mx')) {
        $st = $db->prepare('SELECT id, body, edited, deleted, attachment FROM messages WHERE channel_id = ? AND parent_id IS NULL ORDER BY id DESC LIMIT 100');
        $st->execute([$kanaalId]);
        $antwoord['updates'] = array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'body'       => (int)$r['deleted'] ? '' : $r['body'],
            'edited'     => $r['edited'],
            'deleted'    => (int)$r['deleted'] === 1,
            'attachment' => ((int)$r['deleted'] === 1 || empty($r['attachment'])) ? null : (json_decode((string)$r['attachment'], true) ?: null),
        ], $st->fetchAll());
    }
    // Ongelezen-tellers per kanaal (voor de badges in de zijbalk), en (v0.7)
    // hoeveel daarvan berichten zijn waarin ik met @Naam genoemd word.
    $st = $db->prepare('SELECT c.id,
        (SELECT COUNT(*) FROM messages x WHERE x.channel_id = c.id AND x.id > m.last_read_id AND x.user_id != ? AND x.deleted = 0) AS unread,
        (SELECT COUNT(*) FROM mentions mn JOIN messages x ON x.id = mn.message_id
           WHERE x.channel_id = c.id AND mn.user_id = ? AND x.id > m.last_read_id AND x.deleted = 0) AS mentions
        FROM channels c JOIN channel_members m ON m.channel_id = c.id WHERE m.user_id = ? AND c.archived = 0');
    $st->execute([$mijnId, $mijnId, $mijnId]);
    $unread = []; $mentions = [];
    foreach ($st->fetchAll() as $r) { $unread[(string)$r['id']] = (int)$r['unread']; $mentions[(string)$r['id']] = (int)$r['mentions']; }
    $antwoord['unread'] = $unread;
    $antwoord['mentions'] = $mentions;
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
    vacom_vermeldingen_opslaan($db, $id, $body, $mijnId); // v0.7: wie wordt er @genoemd?
    // Eigen bericht telt meteen als gelezen.
    $db->prepare('UPDATE channel_members SET last_read_id = MAX(last_read_id, ?) WHERE channel_id = ? AND user_id = ?')
       ->execute([$id, (int)$k['id'], $mijnId]);
    // v0.5.1: een verborgen gesprek komt bij een nieuw bericht weer terug in
    // de lijst van de ander (state omhoog zodat zijn zijbalk ververst).
    $terug = $db->prepare('UPDATE channel_members SET hidden = 0 WHERE channel_id = ? AND hidden = 1');
    $terug->execute([(int)$k['id']]);
    $db->commit();
    if ($terug->rowCount() > 0) vacom_bump_state();
    $lijst = berichten_uit($db, 'm.id = ?', [$id], 1, true);
    vacom_json(['ok' => true, 'message' => $lijst[0], 'max_id' => vacom_max_message_id()]);
}

case 'stuur_bestand': {
    // v0.6: foto of bestand als bericht (multipart: 'bestand' + optioneel 'body'
    // als bijschrift). Het bestand gaat naar de datamap (buiten de webroot),
    // bestand.php serveert het aan kanaalleden. Zonder bijschrift wordt de
    // bestandsnaam de tekst van het bericht — zo is hij ook doorzoekbaar.
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    $f = $_FILES['bestand'] ?? null;
    if (!$f || !isset($f['error'])) vacom_fout('Geen bestand ontvangen. Te groot voor de server? De limiet is ' . ini_get('upload_max_filesize') . '.');
    $fout = (int)$f['error'];
    if ($fout === UPLOAD_ERR_INI_SIZE || $fout === UPLOAD_ERR_FORM_SIZE) vacom_fout('Bestand is te groot voor de server (limiet ' . ini_get('upload_max_filesize') . ').');
    if ($fout === UPLOAD_ERR_NO_FILE) vacom_fout('Geen bestand gekozen.');
    if ($fout !== UPLOAD_ERR_OK) vacom_fout('Upload mislukt (code ' . $fout . ').');
    if (!is_uploaded_file($f['tmp_name'])) vacom_fout('Upload mislukt.');
    if ((int)$f['size'] <= 0) vacom_fout('Het bestand is leeg.');
    if ((int)$f['size'] > VACOM_MAX_BIJLAGE) vacom_fout('Bestand is te groot (max. ' . (int)(VACOM_MAX_BIJLAGE / 1048576) . ' MB).');
    $naam = bijlage_naam((string)$f['name']);
    if (preg_match('/\.(php\d?|phtml|phar|cgi|pl|sh|bat|cmd|exe|com|scr|msi|jar|vbs|ps1|htaccess)$/i', $naam)) vacom_fout('Dit bestandstype is niet toegestaan.');
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = ($fi ? finfo_file($fi, $f['tmp_name']) : false) ?: $mime;
        if ($fi) finfo_close($fi);
    }
    $info = @getimagesize($f['tmp_name']);
    $isBeeld = $info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    if ($isBeeld) $mime = $info['mime'];
    $bijschrift = trim(str_replace("\r\n", "\n", param_str($invoer, 'body')));
    if (mb_strlen($bijschrift) > 8000) vacom_fout('Bijschrift is te lang (max. 8000 tekens).');
    $body = $bijschrift !== '' ? $bijschrift : $naam;

    $db->beginTransaction();
    $db->prepare('INSERT INTO messages(channel_id, user_id, body, created) VALUES (?,?,?,?)')
       ->execute([(int)$k['id'], $mijnId, $body, vacom_now()]);
    $id  = (int)$db->lastInsertId();
    $map = bijlage_map($id);
    if (!is_dir($map) && !@mkdir($map, 0755, true)) { $db->rollBack(); vacom_fout('Kan de bestandsmap niet aanmaken — is de datamap beschrijfbaar?', 500); }
    $doel = $map . '/' . $naam;
    if (!move_uploaded_file($f['tmp_name'], $doel)) { $db->rollBack(); @rmdir($map); vacom_fout('Opslaan van het bestand mislukt.', 500); }
    $att = ['name' => $naam, 'size' => (int)$f['size'], 'mime' => $mime, 'type' => $isBeeld ? 'image' : 'file', 'thumb' => false, 'w' => 0, 'h' => 0];
    if ($isBeeld) {
        $t = maak_thumbnail($doel, $map . '/thumb.jpg', 1000, $info);
        if ($t) { $att['thumb'] = true; [$att['w'], $att['h']] = $t; }
        else { $att['w'] = (int)$info[0]; $att['h'] = (int)$info[1]; }
    }
    $db->prepare('UPDATE messages SET attachment = ? WHERE id = ?')->execute([json_encode($att, JSON_UNESCAPED_UNICODE), $id]);
    vacom_vermeldingen_opslaan($db, $id, $body, $mijnId); // v0.7: @Naam in het bijschrift
    $db->prepare('UPDATE channel_members SET last_read_id = MAX(last_read_id, ?) WHERE channel_id = ? AND user_id = ?')
       ->execute([$id, (int)$k['id'], $mijnId]);
    $terug = $db->prepare('UPDATE channel_members SET hidden = 0 WHERE channel_id = ? AND hidden = 1');
    $terug->execute([(int)$k['id']]);
    $db->commit();
    if ($terug->rowCount() > 0) vacom_bump_state();
    $lijst = berichten_uit($db, 'm.id = ?', [$id], 1, true);
    vacom_json(['ok' => true, 'message' => $lijst[0], 'max_id' => vacom_max_message_id()]);
}

case 'bewerk': {
    // v0.6: eigen bericht bewerken. Alleen de tekst; een bijlage blijft staan.
    $berichtId = param_int($invoer, 'message_id');
    $st = $db->prepare('SELECT * FROM messages WHERE id = ?');
    $st->execute([$berichtId]);
    $m = $st->fetch();
    if (!$m || (int)$m['deleted'] === 1) vacom_fout('Bericht niet gevonden.', 404);
    if ((int)$m['user_id'] !== $mijnId) vacom_fout('Je kunt alleen je eigen berichten bewerken.', 403);
    kanaal_voor_mij($db, (int)$m['channel_id'], $mijnId); // lid, en niet gesloten
    $body = trim(str_replace("\r\n", "\n", param_str($invoer, 'body')));
    if ($body === '' && empty($m['attachment'])) vacom_fout('Leeg bericht — verwijder het liever.');
    if (mb_strlen($body) > 8000) vacom_fout('Bericht is te lang (max. 8000 tekens).');
    if ($body === '') $body = (json_decode((string)$m['attachment'], true)['name'] ?? ''); // bijlage zonder tekst: naam als tekst
    if ($body !== $m['body']) {
        $db->prepare('UPDATE messages SET body = ?, edited = ? WHERE id = ?')->execute([$body, vacom_now(), $berichtId]);
        vacom_vermeldingen_opslaan($db, $berichtId, $body, $mijnId); // v0.7: opnieuw bepalen wie genoemd wordt
        vacom_bump_mx();
    }
    $lijst = berichten_uit($db, 'm.id = ?', [$berichtId], 1, true);
    vacom_json(['ok' => true, 'message' => $lijst[0], 'mx' => (int)vacom_meta('mx')]);
}

case 'verwijder': {
    // v0.6: bericht verwijderen — eigen bericht, of als superadmin elk bericht.
    // Soft delete zoals Slack ("bericht verwijderd" blijft als spoor), maar de
    // tekst, reacties, bijlage en zoekindex gaan wél echt weg.
    $berichtId = param_int($invoer, 'message_id');
    $st = $db->prepare('SELECT * FROM messages WHERE id = ?');
    $st->execute([$berichtId]);
    $m = $st->fetch();
    if (!$m || (int)$m['deleted'] === 1) vacom_fout('Bericht niet gevonden.', 404);
    if ((int)$m['user_id'] !== $mijnId && !vacom_is_superadmin()) vacom_fout('Je kunt alleen je eigen berichten verwijderen.', 403);
    kanaal_voor_mij($db, (int)$m['channel_id'], $mijnId, vacom_is_superadmin()); // superadmin mag ook in een gesloten kanaal opruimen
    $db->beginTransaction();
    $db->prepare("UPDATE messages SET body = '', deleted = 1, attachment = NULL, edited = ? WHERE id = ?")->execute([vacom_now(), $berichtId]);
    $db->prepare('DELETE FROM reactions WHERE message_id = ?')->execute([$berichtId]);
    $db->prepare('DELETE FROM mentions WHERE message_id = ?')->execute([$berichtId]); // v0.7
    $db->commit();
    bijlage_wis($berichtId);
    vacom_bump_mx();
    vacom_json(['ok' => true, 'mx' => (int)vacom_meta('mx')]);
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
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId, true); // leesmarkering mag ook in een gesloten kanaal
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

/* ---- Avatar (v0.5): eigen foto uploaden of verwijderen ---- */

case 'avatar_upload': {
    // Komt binnen als multipart (FormData), dus $invoer = $_POST en de foto in $_FILES.
    $f = $_FILES['avatar'] ?? null;
    if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) vacom_fout('Geen foto ontvangen.');
    if ((int)$f['error'] !== UPLOAD_ERR_OK) vacom_fout('Upload mislukt (code ' . (int)$f['error'] . ').');
    if ((int)$f['size'] > 8 * 1024 * 1024) vacom_fout('De foto is te groot (max. 8 MB).');
    $info = @getimagesize($f['tmp_name']);
    if (!$info) vacom_fout('Dit bestand is geen afbeelding.');
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']),
        IMAGETYPE_PNG  => @imagecreatefrompng($f['tmp_name']),
        IMAGETYPE_GIF  => @imagecreatefromgif($f['tmp_name']),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f['tmp_name']) : false,
        default        => false,
    };
    if (!$src) vacom_fout('Alleen JPG, PNG, GIF of WebP.');
    // iPhone-foto's dragen hun draaiing in de EXIF-data; anders staat de foto op z'n kant.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($f['tmp_name']);
        $o = (int)($exif['Orientation'] ?? 1);
        if ($o === 3)     $src = imagerotate($src, 180, 0);
        elseif ($o === 6) $src = imagerotate($src, -90, 0);
        elseif ($o === 8) $src = imagerotate($src, 90, 0);
    }
    // Vierkant uit het midden knippen en verkleinen naar 192×192 (ruim genoeg voor retina).
    $w = imagesx($src); $h = imagesy($src); $z = min($w, $h);
    $dst = imagecreatetruecolor(192, 192);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // transparante PNG's krijgen wit
    imagecopyresampled($dst, $src, 0, 0, (int)(($w - $z) / 2), (int)(($h - $z) / 2), 192, 192, $z, $z);
    $pad = vacom_avatar_pad($mijnId);
    if (!is_dir(dirname($pad))) @mkdir(dirname($pad), 0755, true);
    if (!imagejpeg($dst, $pad, 86)) vacom_fout('Opslaan mislukt — is de datamap beschrijfbaar?');
    imagedestroy($src); imagedestroy($dst);
    vacom_bump_state(); // iedereen haalt het overzicht opnieuw → nieuwe foto zichtbaar
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

case 'avatar_verwijderen': {
    $pad = vacom_avatar_pad($mijnId);
    if (is_file($pad)) @unlink($pad);
    vacom_bump_state();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

/* ---- GIF zoeken via Giphy (v0.5.3) ---- */

case 'gif_zoeken': {
    // Via de server, niet rechtstreeks vanuit de browser: zo staat de API-
    // sleutel nooit in de browsercode en ziet Giphy alleen ons server-IP,
    // niet dat van collega's. Leeg zoekwoord = de populaire gifs van nu.
    $sleutel = vacom_giphy_key();
    if ($sleutel === '') vacom_fout('Geen Giphy-sleutel ingesteld (giphy_key.txt in de datamap).');
    $q = mb_substr(param_str($invoer, 'q'), 0, 100);
    $params = ['api_key' => $sleutel, 'limit' => 24, 'rating' => 'pg-13', 'lang' => 'nl'];
    if ($q !== '') $params['q'] = $q;
    $url = 'https://api.giphy.com/v1/gifs/' . ($q !== '' ? 'search' : 'trending') . '?' . http_build_query($params);
    $data = json_decode(vacom_http_get($url) ?: '', true);
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) vacom_fout('Giphy antwoordt niet — probeer het zo nog eens.', 502);
    $uit = [];
    foreach ($data['data'] as $g) {
        $imgs  = $g['images'] ?? [];
        $klein = $imgs['fixed_width_small'] ?? $imgs['fixed_width'] ?? null; // voor het raster
        $groot = $imgs['downsized_medium'] ?? $imgs['fixed_height'] ?? $imgs['original'] ?? null; // in het gesprek
        if (!$klein || !$groot || empty($groot['url'])) continue;
        $uit[] = [
            'id'      => (string)($g['id'] ?? ''),
            'titel'   => (string)($g['title'] ?? ''),
            'preview' => (string)($klein['webp'] ?? $klein['url'] ?? ''),
            'w'       => (int)($klein['width'] ?? 0),
            'h'       => (int)($klein['height'] ?? 0),
            // Zonder tracking-parameters: het bericht wordt één kale gif-link,
            // precies wat de app al als beeld toont (alsAfbeelding in app.js).
            'url'     => strtok((string)$groot['url'], '?'),
        ];
    }
    vacom_json(['ok' => true, 'gifs' => $uit]);
}

/* ---- Portal-koppeling (v0.5.1): alleen-lezen, via GET vanaf de Portal-pagina's ---- */

case 'portal_ongelezen': {
    // Per projectkanaal: ben ik lid en hoeveel ongelezen? Voor het VaCom-
    // icoontje (kleur + badge) op de projectkaarten van de Portal-homepage.
    $st = $db->prepare('SELECT c.project_token,
        (SELECT COUNT(*) FROM channel_members m WHERE m.channel_id = c.id AND m.user_id = ?) AS lid,
        (SELECT COUNT(*) FROM messages x WHERE x.channel_id = c.id AND x.deleted = 0 AND x.user_id != ?
            AND x.id > COALESCE((SELECT last_read_id FROM channel_members m WHERE m.channel_id = c.id AND m.user_id = ?), 0)) AS ongelezen
        FROM channels c WHERE c.project_token IS NOT NULL AND c.archived = 0');
    $st->execute([$mijnId, $mijnId, $mijnId]);
    $uit = [];
    foreach ($st->fetchAll() as $r) {
        $lid = (int)$r['lid'] === 1;
        $uit[$r['project_token']] = ['lid' => $lid, 'ongelezen' => $lid ? (int)$r['ongelezen'] : 0];
    }
    vacom_json(['ok' => true, 'projecten' => (object)$uit]);
}

case 'portal_vermeldingen': {
    // v0.7.1: voor de @-indicator in de Portal-header — in welke kanalen word
    // ik genoemd in berichten die ik nog niet gelezen heb? Alleen-lezen, GET.
    $st = $db->prepare('SELECT c.id, c.type, c.name, c.dm_key, c.project_token,
        (SELECT COUNT(*) FROM mentions mn JOIN messages x ON x.id = mn.message_id
           WHERE x.channel_id = c.id AND mn.user_id = ? AND x.id > m.last_read_id AND x.deleted = 0) AS mentions
        FROM channels c JOIN channel_members m ON m.channel_id = c.id
        WHERE m.user_id = ? AND c.archived = 0');
    $st->execute([$mijnId, $mijnId]);
    $uit = []; $totaal = 0;
    foreach ($st->fetchAll() as $r) {
        $n = (int)$r['mentions'];
        if ($n === 0) continue;
        $label = '#' . $r['name'];
        if ($r['type'] === 'dm') {
            [$a, $b] = array_map('intval', explode(':', (string)$r['dm_key']));
            $ander = vacom_user_by_id($a === $mijnId ? $b : $a);
            $label = $ander ? (string)$ander['name'] : 'Gesprek';
        }
        $uit[] = ['id' => (int)$r['id'], 'label' => $label, 'project_token' => $r['project_token'] ?: '', 'mentions' => $n];
        $totaal += $n;
    }
    usort($uit, fn($x, $y) => $y['mentions'] <=> $x['mentions']);
    vacom_json(['ok' => true, 'totaal' => $totaal, 'kanalen' => $uit]);
}

case 'portal_project': {
    // De kanaalkaart op de Portal-projectpagina: leden en de laatste berichten.
    // Geen lid? Dan alleen dát (privacy: je leest alleen mee waar je bij hoort).
    $token = preg_replace('/[^a-f0-9]/', '', param_str($invoer, 'project'));
    $st = $db->prepare('SELECT * FROM channels WHERE project_token = ? AND archived = 0');
    $st->execute([$token]);
    $k = $st->fetch();
    if (!$k) vacom_json(['ok' => true, 'bestaat' => false]);
    $kid = (int)$k['id'];
    $lidSt = $db->prepare('SELECT last_read_id FROM channel_members WHERE channel_id = ? AND user_id = ?');
    $lidSt->execute([$kid, $mijnId]);
    $lidRij = $lidSt->fetch();
    $ledenSt = $db->prepare('SELECT u.name FROM channel_members m JOIN users u ON u.id = m.user_id WHERE m.channel_id = ? AND u.active = 1 ORDER BY u.name COLLATE NOCASE');
    $ledenSt->execute([$kid]);
    $uit = ['ok' => true, 'bestaat' => true, 'lid' => (bool)$lidRij, 'naam' => $k['name'],
            'leden' => array_column($ledenSt->fetchAll(), 'name'), 'ongelezen' => 0, 'berichten' => []];
    if ($lidRij) {
        // v0.6: ook id + bijlage mee, zodat de kaart een foto/gif klein kan tonen.
        $uit['berichten'] = array_map(fn($m) => ['id' => $m['id'], 'author' => $m['author'], 'body' => mb_substr($m['body'], 0, 200), 'created' => $m['created'], 'attachment' => $m['attachment']],
            berichten_uit($db, 'm.channel_id = ? AND m.parent_id IS NULL AND m.deleted = 0', [$kid], 5, false));
        $o = $db->prepare('SELECT COUNT(*) FROM messages WHERE channel_id = ? AND deleted = 0 AND user_id != ? AND id > ?');
        $o->execute([$kid, $mijnId, (int)$lidRij['last_read_id']]);
        $uit['ongelezen'] = (int)$o->fetchColumn();
    }
    vacom_json($uit);
}

case 'kanaal_deelnemen': {
    // v0.5.1: jezelf bij een projectkanaal voegen — bv. als niet-gekoppelde
    // collega vanuit de Portal. Iedereen mag collega's toevoegen, dus jezelf
    // ook; je wordt 'handmatig' lid (de Portal-sync laat dat met rust).
    $token = preg_replace('/[^a-f0-9]/', '', param_str($invoer, 'project_token'));
    $st = $db->prepare('SELECT * FROM channels WHERE project_token = ? AND archived = 0');
    $st->execute([$token]);
    $k = $st->fetch();
    if (!$k) vacom_fout('Dit projectkanaal bestaat niet (meer).', 404);
    $db->prepare("INSERT OR IGNORE INTO channel_members(channel_id, user_id, last_read_id, joined, via) VALUES (?, ?, ?, ?, 'handmatig')")
       ->execute([(int)$k['id'], $mijnId, vacom_max_message_id(), vacom_now()]);
    vacom_bump_state();
    vacom_json(['ok' => true, 'channel_id' => (int)$k['id']] + vacom_overzicht($db, $ik));
}

/* ---- Kanaalbeheer voor de superadmin (v0.5.1) ---- */

case 'beheer_kanalen': {
    // Alle kanalen, ook waar de superadmin geen lid van is, met aantallen —
    // om op te ruimen. Gesprekken (DM's) blijven privé en staan er niet bij.
    if (!vacom_is_superadmin()) vacom_fout('Alleen voor de superadmin.', 403);
    $rijen = $db->query("SELECT c.id, c.name, c.project_token, c.archived,
        (SELECT COUNT(*) FROM channel_members m WHERE m.channel_id = c.id) AS leden,
        (SELECT COUNT(*) FROM messages x WHERE x.channel_id = c.id AND x.deleted = 0) AS berichten
        FROM channels c WHERE c.type = 'public' ORDER BY c.archived, c.name COLLATE NOCASE")->fetchAll();
    $lijst = array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['name'],
        'is_project' => $r['project_token'] !== null && $r['project_token'] !== '',
        'archived' => (int)$r['archived'] === 1, 'leden' => (int)$r['leden'], 'berichten' => (int)$r['berichten'],
    ], $rijen);
    vacom_json(['ok' => true, 'kanalen' => $lijst]);
}

case 'beheer_kanaal_wissen': {
    // Definitief weg — ook zonder lid te zijn, ook projectkanalen. Berichten,
    // reacties en de zoekindex (FTS-trigger) gaan mee. Van een projectkanaal
    // onthouden we wannéér het gewist is, zodat de Portal-vlag het niet
    // meteen opnieuw aanmaakt (zie vacom_portal_sync); opnieuw starten
    // vanuit de Portal kan wel, dat zet een nieuwere vlag-tijd.
    if (!vacom_is_superadmin()) vacom_fout('Alleen de superadmin kan een kanaal verwijderen.', 403);
    $st = $db->prepare("SELECT * FROM channels WHERE id = ? AND type = 'public'");
    $st->execute([param_int($invoer, 'channel_id')]);
    $k = $st->fetch();
    if (!$k) vacom_fout('Kanaal niet gevonden.', 404);
    $kid = (int)$k['id'];
    bijlagen_wis_kanaal($db, $kid); // v0.6
    $db->beginTransaction();
    $db->prepare('DELETE FROM reactions WHERE message_id IN (SELECT id FROM messages WHERE channel_id = ?)')->execute([$kid]);
    $db->prepare('DELETE FROM messages WHERE channel_id = ?')->execute([$kid]);
    $db->prepare('DELETE FROM channel_members WHERE channel_id = ?')->execute([$kid]);
    $db->prepare('DELETE FROM channels WHERE id = ?')->execute([$kid]);
    if (is_projectkanaal($k)) {
        $db->prepare('INSERT OR REPLACE INTO meta(key, value) VALUES (?, ?)')->execute(['pk_gewist:' . $k['project_token'], (string)time()]);
    }
    vacom_bump_state();
    $db->commit();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

/* ---- Projectkanalen starten (v0.5) ---- */

case 'projecten_beschikbaar':
    if (!VACOM_PORTAL) vacom_json(['ok' => true, 'projects' => []]);
    vacom_json(['ok' => true, 'projects' => vacom_projecten_beschikbaar($db, $ik)]);

case 'projectkanaal_aanmaken': {
    if (!VACOM_PORTAL) vacom_fout('Alleen in Portal-modus.');
    $token = param_str($invoer, 'token');
    if ($token === '' || !preg_match('/^[a-f0-9]+$/', $token)) vacom_fout('Ongeldig project.');
    try {
        $id = vacom_projectkanaal_activeren($db, $token, $ik);
    } catch (RuntimeException $e) {
        vacom_fout($e->getMessage());
    }
    vacom_json(['ok' => true, 'channel_id' => $id] + vacom_overzicht($db, $ik));
}

/* ---- Kanaal-opties (v0.4): leden, toevoegen, verlaten, sluiten, verwijderen ---- */

case 'kanaal_leden': {
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId, true); // ook van een gesloten kanaal
    if ($k['type'] !== 'public') vacom_fout('Een gesprek heeft geen ledenlijst.');
    $st = $db->prepare('SELECT u.id, u.name, m.via FROM channel_members m JOIN users u ON u.id = m.user_id
                        WHERE m.channel_id = ? AND u.active = 1 ORDER BY u.name COLLATE NOCASE');
    $st->execute([(int)$k['id']]);
    $leden = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'via' => $r['via']], $st->fetchAll());
    $isProject = is_projectkanaal($k);
    $gesloten  = (int)$k['archived'] === 1;
    vacom_json([
        'ok'            => true,
        'members'       => $leden,
        'is_project'    => $isProject,
        'archived'      => $gesloten,
        'kan_sluiten'   => !$isProject && !$gesloten && mag_kanaal_beheren($k, $mijnId),
        'kan_heropenen' => !$isProject &&  $gesloten && mag_kanaal_beheren($k, $mijnId),
        'kan_wissen'    => !$isProject && vacom_is_superadmin(),
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
    // v0.5.1: een projectkanaal verlaat je niet — je hoort erbij zolang je aan
    // het project gekoppeld bent (en de Portal-kaart bleef anders "actief"
    // terwijl jij het gesprek niet meer zag).
    if ($wie === $mijnId && is_projectkanaal($k)) vacom_fout('Een projectkanaal verlaat je niet: je hoort erbij zolang je aan het project gekoppeld bent.');
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
    // Archiveren, zoals in Slack (v0.4.1): het kanaal verhuist naar "Gesloten
    // kanalen" onderin de zijbalk, blijft leesbaar en doorzoekbaar, maar
    // niemand kan er nog in schrijven. Alleen losse kanalen; projectkanalen
    // volgen het project in de Portal (prullenbak = gesloten, herstel = open).
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    if ($k['type'] !== 'public' || is_projectkanaal($k)) vacom_fout('Een projectkanaal sluit je via de Portal.');
    if (!mag_kanaal_beheren($k, $mijnId)) vacom_fout('Alleen de superadmin of wie het kanaal aanmaakte kan het sluiten.', 403);
    $db->prepare('UPDATE channels SET archived = 1 WHERE id = ?')->execute([(int)$k['id']]);
    vacom_bump_state();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

case 'kanaal_heropenen': {
    // Het omgekeerde van sluiten (v0.4.1): terug naar de gewone lijst, en er
    // kan weer geschreven worden. Zelfde rechten als sluiten.
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId, true);
    if ($k['type'] !== 'public' || is_projectkanaal($k)) vacom_fout('Een projectkanaal heropen je via de Portal (project herstellen uit de prullenbak).');
    if ((int)$k['archived'] !== 1) vacom_fout('Dit kanaal is niet gesloten.');
    if (!mag_kanaal_beheren($k, $mijnId)) vacom_fout('Alleen de superadmin of wie het kanaal aanmaakte kan het heropenen.', 403);
    $db->prepare('UPDATE channels SET archived = 0 WHERE id = ?')->execute([(int)$k['id']]);
    vacom_bump_state();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

case 'kanaal_verwijderen': {
    // Definitief weg, inclusief alle berichten. Superadmin, losse kanalen —
    // ook gesloten kanalen (die ruim je zo alsnog definitief op).
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId, true);
    if (!vacom_is_superadmin()) vacom_fout('Alleen de superadmin kan een kanaal verwijderen.', 403);
    if ($k['type'] !== 'public' || is_projectkanaal($k)) vacom_fout('Een projectkanaal verwijder je via de Portal.');
    bijlagen_wis_kanaal($db, (int)$k['id']); // v0.6
    $db->beginTransaction();
    $db->prepare('DELETE FROM reactions WHERE message_id IN (SELECT id FROM messages WHERE channel_id = ?)')->execute([(int)$k['id']]);
    $db->prepare('DELETE FROM messages WHERE channel_id = ?')->execute([(int)$k['id']]);
    $db->prepare('DELETE FROM channel_members WHERE channel_id = ?')->execute([(int)$k['id']]);
    $db->prepare('DELETE FROM channels WHERE id = ?')->execute([(int)$k['id']]);
    vacom_bump_state();
    $db->commit();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

case 'status_zetten': {
    // v0.5: eigen status. Korte vrije tekst; leeg = geen status.
    $status = mb_substr(param_str($invoer, 'status'), 0, 60);
    $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $mijnId]);
    $ik['status'] = $status; // $ik was al geladen vóór de update; 'me' in het overzicht moet de nieuwe status tonen
    vacom_bump_state();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

case 'dm': {
    // DM openen met een collega: bestaat er al een, dan die; anders aanmaken.
    // v0.5: een gesprek met jezelf mag ook (notities; dm_key "5:5").
    $ander = vacom_user_by_id(param_int($invoer, 'user_id'));
    if ($ander === null) vacom_fout('Collega niet gevonden.');
    $anderId = (int)$ander['id'];
    $sleutel = vacom_dm_key($mijnId, $anderId);
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
        if ($anderId !== $mijnId) $ins->execute([$id, $anderId, vacom_now()]);
        vacom_bump_state();
        $db->commit();
    } else {
        // v0.5.1: opnieuw openen haalt een verborgen gesprek weer tevoorschijn.
        $db->prepare('UPDATE channel_members SET hidden = 0 WHERE channel_id = ? AND user_id = ?')->execute([$id, $mijnId]);
    }
    vacom_json(['ok' => true, 'channel_id' => $id] + vacom_overzicht($db, $ik));
}

case 'gesprek_verbergen': {
    // v0.5.1: een (leeg of oud) gesprek uit je lijst halen, zoals in Slack.
    // Alleen voor jou; de ander ziet niets. Komt terug bij een nieuw bericht.
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId, true);
    if ($k['type'] !== 'dm') vacom_fout('Alleen een gesprek kun je verbergen; een kanaal verlaat of sluit je.');
    $db->prepare('UPDATE channel_members SET hidden = 1 WHERE channel_id = ? AND user_id = ?')->execute([(int)$k['id'], $mijnId]);
    vacom_bump_state(); // ook je andere apparaten verversen hun lijst
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
}

case 'vermeldingen': {
    // v0.7: de laatste berichten waarin ik met @Naam genoemd word, in kanalen
    // waar ik lid van ben (ook gesloten kanalen). 'ongelezen' = nog niet
    // gezien (boven mijn leesmarkering in dat kanaal).
    $st = $db->prepare(<<<'SQL'
SELECT m.id, m.channel_id, m.user_id, m.parent_id, m.body, m.created, m.edited, m.deleted, m.attachment, u.name AS author,
       (m.id > cm.last_read_id) AS ongelezen
FROM mentions mn
JOIN messages m ON m.id = mn.message_id
JOIN users u ON u.id = m.user_id
JOIN channel_members cm ON cm.channel_id = m.channel_id AND cm.user_id = ?
WHERE mn.user_id = ? AND m.deleted = 0
ORDER BY m.id DESC LIMIT 50
SQL);
    $st->execute([$mijnId, $mijnId]);
    $uit = [];
    foreach ($st->fetchAll() as $r) {
        $b = bericht_uit($r);
        $b['ongelezen'] = (int)$r['ongelezen'] === 1;
        $uit[] = $b;
    }
    vacom_json(['ok' => true, 'results' => $uit]);
}

case 'zoek': {
    // Full-text search over alle berichten in kanalen waar ik lid van ben —
    // gesloten kanalen doen gewoon mee (v0.4.1: en zijn vanuit de treffer ook
    // te openen, alleen-lezen).
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
