<?php
/**
 * VaCom — API. Alle acties van de app komen hier binnen als JSON.
 *
 * Aanroep: POST api.php met JSON-body {"actie": "...", ...} en de header
 * X-VaCom-Csrf. Alleen-lezen acties (VACOM_GET_ACTIES) mogen ook via GET.
 *
 * Elk antwoord is JSON met minstens {"ok": true|false}.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/overzicht.php';
require_once __DIR__ . '/inc/push.php'; // v0.9: pushmeldingen

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

// v0.9.7: via GET (zonder CSRF-token) mogen alleen alleen-lezen acties. Anders
// kan een gewone link op een andere site — de sessie-cookie gaat bij een klik
// mee — ongemerkt iets wijzigen of verwijderen. Gebruikers van GET: de poll van
// de app, de Portal (chat_ipp.php) en de Invite Hub (overzicht, portal_vermeldingen).
const VACOM_GET_ACTIES = ['poll', 'overzicht', 'portal_vermeldingen', 'portal_ongelezen', 'portal_push_info', 'portal_project'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !in_array($actie, VACOM_GET_ACTIES, true)) {
    header('Allow: POST');
    vacom_fout('Deze actie kan alleen via POST.', 405);
}
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
    $sql = "SELECT m.id, m.channel_id, m.user_id, m.parent_id, m.body, m.created, m.edited, m.deleted, m.attachment, m.preview, u.name AS author
            FROM messages m JOIN users u ON u.id = m.user_id
            WHERE $where ORDER BY m.id " . ($oplopend ? 'ASC' : 'DESC') . " LIMIT $limiet";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rijen = $st->fetchAll();
    if (!$oplopend) $rijen = array_reverse($rijen);
    $lijst = array_map('bericht_uit', $rijen);
    verrijk_berichten($db, $lijst, (int)$GLOBALS['mijnId']);
    return $lijst;
}

/** Reacties, polls, thread-info, vastgezet/opgeslagen erbij (gedeeld door alle lijsten). */
function verrijk_berichten(PDO $db, array &$lijst, int $mijnId): void {
    if (!$lijst) return;
    $ids = array_column($lijst, 'id');
    $reacties   = reacties_bij($db, $ids);
    $stemmingen = stemmingen_bij($db, $ids, $mijnId);
    $extra      = extra_bij($db, $ids, $mijnId);
    foreach ($lijst as &$m) {
        $m['reactions'] = $reacties[$m['id']] ?? [];
        $m['stemming']  = $stemmingen[$m['id']] ?? null;
        $e = $extra[$m['id']] ?? [];
        $m['replies']    = $e['replies'] ?? 0;
        $m['last_reply'] = $e['last_reply'] ?? null;
        $m['reply_user_ids'] = $e['reply_user_ids'] ?? [];
        $m['pinned']     = $e['pinned'] ?? false;
        $m['saved']      = $e['saved'] ?? false;
    }
    unset($m);
}

/** v0.9: per bericht het aantal antwoorden in de thread (+ wie), en of het vastgezet/opgeslagen is. */
function extra_bij(PDO $db, array $ids, int $mijnId): array {
    if (!$ids) return [];
    $v = implode(',', array_fill(0, count($ids), '?'));
    $ids = array_map('intval', $ids);
    $uit = [];
    $st = $db->prepare("SELECT parent_id, COUNT(*) AS n, MAX(created) AS laatste FROM messages WHERE parent_id IN ($v) AND deleted = 0 GROUP BY parent_id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) $uit[(int)$r['parent_id']] = ['replies' => (int)$r['n'], 'last_reply' => $r['laatste'], 'reply_user_ids' => []];
    if ($uit) {
        $st = $db->prepare("SELECT DISTINCT parent_id, user_id FROM messages WHERE parent_id IN ($v) AND deleted = 0 ORDER BY id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $uit[(int)$r['parent_id']]['reply_user_ids'][] = (int)$r['user_id'];
    }
    $st = $db->prepare("SELECT message_id FROM pins WHERE message_id IN ($v)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $uit[(int)$id]['pinned'] = true;
    $st = $db->prepare("SELECT message_id FROM saved WHERE user_id = ? AND message_id IN ($v)");
    $st->execute(array_merge([$mijnId], $ids));
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $uit[(int)$id]['saved'] = true;
    return $uit;
}

/* ---- Linkvoorvertoning (v0.9) ---- */

/** Eerste "gewone" link in een tekst (geen beeld/gif, die staan al inline). */
function eerste_link(string $body): ?string {
    if (!preg_match('~https?://[^\s<>"\']+~i', $body, $m)) return null;
    $url = rtrim($m[0], '.,;:!?)\'"]');
    if (preg_match('/\.(gif|png|jpe?g|webp)(\?.*)?$/i', $url)) return null;
    return mb_substr($url, 0, 500);
}

/** Is deze host veilig om vanaf de server op te halen? (geen privé-/lokale adressen) */
function host_publiek(string $host): bool {
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) return false; // niet te resolven
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * Titel, omschrijving en plaatje van een pagina (Open Graph / <title>), met
 * cache per URL. Korte timeouts: het versturen mag er niet op wachten.
 * null = niets te tonen.
 */
function vacom_link_preview(PDO $db, string $url): ?array {
    $st = $db->prepare('SELECT data FROM link_previews WHERE url = ?');
    $st->execute([$url]);
    $cache = $st->fetch();
    if ($cache) return $cache['data'] ? (json_decode((string)$cache['data'], true) ?: null) : null;
    $data = null;
    $u = parse_url($url);
    if (!empty($u['host']) && in_array(strtolower((string)($u['scheme'] ?? '')), ['http', 'https'], true) && host_publiek($u['host']) && function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; VaCom-linkpreview/1.0; +https://portal.invite.nl/vacom/)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: nl,en'],
            CURLOPT_RANGE => '0-300000',
        ]);
        $html = curl_exec($c);
        $type = (string)curl_getinfo($c, CURLINFO_CONTENT_TYPE);
        $eind = (string)curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
        curl_close($c);
        if (is_string($html) && $html !== '' && stripos($type, 'html') !== false) {
            $html = substr($html, 0, 300000);
            $meta = fn(string $naam) => (preg_match('~<meta[^>]+(?:property|name)=["\']' . preg_quote($naam, '~') . '["\'][^>]+content=["\']([^"\']*)["\']~i', $html, $m)
                || preg_match('~<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . preg_quote($naam, '~') . '["\']~i', $html, $m)) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $titel = $meta('og:title') ?: (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $t) ? html_entity_decode(trim($t[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '');
            $beeld = $meta('og:image');
            if ($beeld !== '' && !preg_match('~^https?://~i', $beeld)) { // relatieve afbeelding → absoluut maken
                $b = parse_url($eind ?: $url);
                $beeld = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? $u['host']) . (str_starts_with($beeld, '/') ? '' : '/') . $beeld;
            }
            if ($titel !== '') {
                $data = [
                    'url' => $url, 'title' => mb_substr(trim(preg_replace('/\s+/', ' ', $titel)), 0, 120),
                    'description' => mb_substr(trim(preg_replace('/\s+/', ' ', $meta('og:description') ?: $meta('description'))), 0, 200),
                    'image' => preg_match('~^https://~i', $beeld) ? mb_substr($beeld, 0, 500) : '',
                    'site' => mb_substr($meta('og:site_name') ?: (string)($u['host']), 0, 60),
                ];
            }
        }
    }
    $db->prepare('INSERT OR REPLACE INTO link_previews(url, data, fetched) VALUES (?, ?, ?)')
       ->execute([$url, $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : '', time()]);
    return $data;
}

/* ---- Pushmeldingen bij een nieuw bericht (v0.9) ---- */

/**
 * Wie krijgt een melding? Leden van het kanaal behalve de schrijver, die niet
 * op dit moment naar VaCom kijken (kijkt_at jonger dan 45 s: venster zichtbaar
 * én met de aandacht, doorgegeven door de poll — zie case 'poll'). v0.9.3:
 * voorheen keken we naar last_seen, maar dat wordt door elk verzoek bijgewerkt,
 * ook door de achtergrondcontrole van de Portal-header; wie de Portal open had
 * staan kreeg daardoor nooit een melding.
 *   DM             → de ander(en)
 *   @vermelding    → de genoemden
 *   thread-antwoord→ wie in die thread meedoet (starter + eerdere reageerders)
 *   kanaalbericht  → alleen wie "alle berichten" aan heeft gezet
 */
function push_bij_bericht(PDO $db, array $k, int $berichtId, int $auteurId, string $tekst, ?int $parentId = null): void {
    if (!vacom_push_kan()) return;
    $st = $db->prepare('SELECT u.id, u.push_alles, u.kijkt_at FROM channel_members cm JOIN users u ON u.id = cm.user_id WHERE cm.channel_id = ? AND cm.user_id != ? AND u.active = 1');
    $st->execute([(int)$k['id'], $auteurId]);
    $leden = $st->fetchAll();
    if (!$leden) return;
    $genoemd = array_flip(vacom_vermeldingen_in($db, $tekst));
    $threadLeden = [];
    if ($parentId) {
        $st = $db->prepare('SELECT DISTINCT user_id FROM messages WHERE (id = ? OR parent_id = ?) AND deleted = 0');
        $st->execute([$parentId, $parentId]);
        $threadLeden = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }
    $doel = [];
    foreach ($leden as $l) {
        $uid = (int)$l['id'];
        if ((int)$l['kijkt_at'] > time() - 45) continue; // kijkt nu naar VaCom: ziet het bericht al
        $reden = $k['type'] === 'dm' || isset($genoemd[$uid]) || isset($threadLeden[$uid]) || (int)$l['push_alles'] === 1;
        if ($reden) $doel[] = $uid;
    }
    if (!$doel) return;
    $v = implode(',', array_fill(0, count($doel), '?'));
    $st = $db->prepare("SELECT endpoint, p256dh, auth FROM push_subs WHERE user_id IN ($v)");
    $st->execute($doel);
    $subs = $st->fetchAll();
    if (!$subs) return;
    $auteur = $db->query('SELECT name FROM users WHERE id = ' . $auteurId)->fetchColumn();
    $waar = $k['type'] === 'dm' ? '' : ' in #' . $k['name'];
    $kort = mb_substr(preg_replace('/\s+/', ' ', trim($tekst)), 0, 140);
    $weg = vacom_push_stuur($subs, [
        'title' => $auteur . $waar,
        'body'  => $kort,
        'url'   => 'index.php#k=' . (int)$k['id'] . ($parentId ? '&t=' . $parentId : ''),
        'tag'   => 'vacom-k' . (int)$k['id'],
    ]);
    foreach ($weg as $ep) $db->prepare('DELETE FROM push_subs WHERE endpoint = ?')->execute([$ep]);
}

/** Typt-indicator opruimen zodra iemand daadwerkelijk iets verstuurt. */
function typing_weg(PDO $db, int $kanaalId, int $userId): void {
    $db->prepare('DELETE FROM typing WHERE channel_id = ? AND user_id = ?')->execute([$kanaalId, $userId]);
}

/**
 * Polls bij een set berichten (v0.8): map bericht-id => stemming, met per
 * antwoord het aantal stemmen en wie, en wat ik zelf gekozen heb.
 */
function stemmingen_bij(PDO $db, array $berichtIds, int $mijnId): array {
    if (!$berichtIds) return [];
    $v = implode(',', array_fill(0, count($berichtIds), '?'));
    $st = $db->prepare("SELECT id, message_id, multi, closed FROM stemmingen WHERE message_id IN ($v)");
    $st->execute(array_map('intval', $berichtIds));
    $polls = []; $perBericht = [];
    foreach ($st->fetchAll() as $p) {
        $polls[(int)$p['id']] = ['id' => (int)$p['id'], 'multi' => (int)$p['multi'] === 1, 'closed' => (int)$p['closed'] === 1, 'options' => [], 'my' => [], 'total' => 0];
        $perBericht[(int)$p['message_id']] = (int)$p['id'];
    }
    if (!$polls) return [];
    $ids = array_keys($polls);
    $v = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, stemming_id, text FROM stemming_opties WHERE stemming_id IN ($v) ORDER BY sort, id");
    $st->execute($ids);
    $optieVanPoll = [];
    foreach ($st->fetchAll() as $o) {
        $polls[(int)$o['stemming_id']]['options'][] = ['id' => (int)$o['id'], 'text' => $o['text'], 'count' => 0, 'user_ids' => []];
        $optieVanPoll[(int)$o['id']] = (int)$o['stemming_id'];
    }
    if ($optieVanPoll) {
        $oids = array_keys($optieVanPoll);
        $v = implode(',', array_fill(0, count($oids), '?'));
        $st = $db->prepare("SELECT optie_id, user_id FROM stemming_stemmen WHERE optie_id IN ($v) ORDER BY created");
        $st->execute($oids);
        foreach ($st->fetchAll() as $s) {
            $pid = $optieVanPoll[(int)$s['optie_id']];
            foreach ($polls[$pid]['options'] as &$opt) {
                if ($opt['id'] !== (int)$s['optie_id']) continue;
                $opt['count']++;
                $opt['user_ids'][] = (int)$s['user_id'];
                if ((int)$s['user_id'] === $mijnId) $polls[$pid]['my'][] = $opt['id'];
            }
            unset($opt);
        }
    }
    foreach ($polls as &$p) {
        $stemmers = [];
        foreach ($p['options'] as $o) foreach ($o['user_ids'] as $u) $stemmers[$u] = true;
        $p['total'] = count($stemmers); // aantal mensen, niet aantal vinkjes
    }
    unset($p);
    $uit = [];
    foreach ($perBericht as $mid => $pid) $uit[$mid] = $polls[$pid];
    return $uit;
}

/** De poll van een bericht, na een wijziging (stemmen/sluiten). */
function stemming_van_bericht(PDO $db, int $berichtId, int $mijnId): ?array {
    return stemmingen_bij($db, [$berichtId], $mijnId)[$berichtId] ?? null;
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
        // v0.9: linkvoorvertoning
        'preview'    => ((int)$r['deleted'] === 1 || empty($r['preview'])) ? null : (json_decode((string)$r['preview'], true) ?: null),
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
    $threadId = param_int($invoer, 'thread_id'); // v0.9: open thread (starter-id), 0 = geen
    // v0.9.3: kijkt deze gebruiker nu echt naar VaCom (venster zichtbaar én met
    // de aandacht)? Dan hoeft er geen pushmelding — zie push_bij_bericht.
    // Hooguit elke 20 s wegschrijven; de poll zelf komt elke paar seconden.
    if (param_int($invoer, 'kijkt') === 1 && (int)($ik['kijkt_at'] ?? 0) < time() - 20) {
        $db->prepare('UPDATE users SET kijkt_at = ? WHERE id = ?')->execute([time(), $mijnId]);
    }
    // v0.9: typt-indicator — wie typte de laatste 6 s in dit kanaal (niet ikzelf)?
    // Klein genoeg om altijd mee te sturen; verandert ook als er verder niets nieuw is.
    $typt = [];
    if ($kanaalId > 0) {
        $st = $db->prepare('SELECT u.name FROM typing t JOIN users u ON u.id = t.user_id WHERE t.channel_id = ? AND t.user_id != ? AND t.at > ? ORDER BY t.at');
        $st->execute([$kanaalId, $mijnId, time() - 6]);
        $typt = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    if ($maxId <= $sinceId && $state === param_int($invoer, 'state') && $rx === param_int($invoer, 'rx') && $mx === param_int($invoer, 'mx')) {
        vacom_json(['ok' => true, 'changed' => false, 'max_id' => $maxId, 'state' => $state, 'rx' => $rx, 'mx' => $mx, 'typing' => $typt]);
    }
    $antwoord = ['ok' => true, 'changed' => true, 'max_id' => $maxId, 'state' => $state, 'rx' => $rx, 'mx' => $mx, 'typing' => $typt];
    $isLid = false;
    if ($kanaalId > 0) {
        $st = $db->prepare('SELECT 1 FROM channel_members WHERE channel_id = ? AND user_id = ?');
        $st->execute([$kanaalId, $mijnId]);
        $isLid = (bool)$st->fetchColumn();
    }
    // Nieuwe berichten in het geopende kanaal meesturen (mits lid).
    if ($isLid && $maxId > $sinceId) {
        $antwoord['messages'] = berichten_uit($db, 'm.channel_id = ? AND m.id > ? AND m.parent_id IS NULL', [$kanaalId, $sinceId], 200, true);
        // v0.9: nieuwe antwoorden in de open thread
        if ($threadId > 0) $antwoord['thread_messages'] = berichten_uit($db, 'm.channel_id = ? AND m.parent_id = ? AND m.id > ?', [$kanaalId, $threadId, $sinceId], 200, true);
    }
    // Reacties veranderd? Stuur de actuele reactie-sets van de laatste 100
    // berichten van het open kanaal (en de open thread) mee; de client werkt bij wat hij kent.
    if ($isLid && $rx !== param_int($invoer, 'rx')) {
        $st = $db->prepare('SELECT id FROM messages WHERE channel_id = ? AND (parent_id IS NULL OR parent_id = ?) ORDER BY id DESC LIMIT 150');
        $st->execute([$kanaalId, $threadId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $sets = reacties_bij($db, $ids);
        // Ook lege sets meesturen: een wéggehaalde laatste reactie moet ook aankomen.
        $antwoord['reactions'] = ['ids' => $ids, 'sets' => (object)$sets];
    }
    // v0.6: bericht bewerkt of verwijderd? Stuur tekst/bijlage-status van de
    // laatste 100 berichten van het open kanaal (+ de open thread) mee; de
    // client werkt bij wat hij kent. v0.9: ook antwoord-teller, vastgezet, poll.
    if ($isLid && $mx !== param_int($invoer, 'mx')) {
        $st = $db->prepare('SELECT id, body, edited, deleted, attachment, preview FROM messages WHERE channel_id = ? AND (parent_id IS NULL OR parent_id = ?) ORDER BY id DESC LIMIT 150');
        $st->execute([$kanaalId, $threadId]);
        $rijen = $st->fetchAll();
        $ids = array_map(fn($r) => (int)$r['id'], $rijen);
        $stemmingen = stemmingen_bij($db, $ids, $mijnId); // v0.8: ook stemmen
        $extra = extra_bij($db, $ids, $mijnId);
        $antwoord['updates'] = array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'body'       => (int)$r['deleted'] ? '' : $r['body'],
            'edited'     => $r['edited'],
            'deleted'    => (int)$r['deleted'] === 1,
            'attachment' => ((int)$r['deleted'] === 1 || empty($r['attachment'])) ? null : (json_decode((string)$r['attachment'], true) ?: null),
            'preview'    => ((int)$r['deleted'] === 1 || empty($r['preview'])) ? null : (json_decode((string)$r['preview'], true) ?: null),
            'stemming'   => (int)$r['deleted'] === 1 ? null : ($stemmingen[(int)$r['id']] ?? null),
            'replies'    => $extra[(int)$r['id']]['replies'] ?? 0,
            'last_reply' => $extra[(int)$r['id']]['last_reply'] ?? null,
            'reply_user_ids' => $extra[(int)$r['id']]['reply_user_ids'] ?? [],
            'pinned'     => $extra[(int)$r['id']]['pinned'] ?? false,
        ], $rijen);
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
    // v0.9: antwoord in een thread? De ouder moet een gewoon (niet-verwijderd)
    // bericht in hetzelfde kanaal zijn; een antwoord op een antwoord hangt aan de starter.
    $parentId = param_int($invoer, 'parent_id') ?: null;
    if ($parentId) {
        $st = $db->prepare('SELECT id, parent_id, deleted FROM messages WHERE id = ? AND channel_id = ?');
        $st->execute([$parentId, (int)$k['id']]);
        $ouder = $st->fetch();
        if (!$ouder || (int)$ouder['deleted'] === 1) vacom_fout('Dit bericht bestaat niet (meer).', 404);
        if ($ouder['parent_id'] !== null) $parentId = (int)$ouder['parent_id'];
    }
    $preview = ($link = eerste_link($body)) ? vacom_link_preview($db, $link) : null; // v0.9, vóór de transactie (kan even duren)
    $db->beginTransaction();
    $db->prepare('INSERT INTO messages(channel_id, user_id, parent_id, body, created, preview) VALUES (?,?,?,?,?,?)')
       ->execute([(int)$k['id'], $mijnId, $parentId, $body, vacom_now(), $preview ? json_encode($preview, JSON_UNESCAPED_UNICODE) : null]);
    $id = (int)$db->lastInsertId();
    vacom_vermeldingen_opslaan($db, $id, $body, $mijnId); // v0.7: wie wordt er @genoemd?
    // Eigen bericht telt meteen als gelezen.
    $db->prepare('UPDATE channel_members SET last_read_id = MAX(last_read_id, ?) WHERE channel_id = ? AND user_id = ?')
       ->execute([$id, (int)$k['id'], $mijnId]);
    // v0.5.1: een verborgen gesprek komt bij een nieuw bericht weer terug in
    // de lijst van de ander (state omhoog zodat zijn zijbalk ververst).
    $terug = $db->prepare('UPDATE channel_members SET hidden = 0 WHERE channel_id = ? AND hidden = 1');
    $terug->execute([(int)$k['id']]);
    typing_weg($db, (int)$k['id'], $mijnId);
    $db->commit();
    if ($terug->rowCount() > 0) vacom_bump_state();
    if ($parentId) vacom_bump_mx(); // de teller "n antwoorden" bij de starter moet bij iedereen verversen
    push_bij_bericht($db, $k, $id, $mijnId, $body, $parentId); // v0.9
    $lijst = berichten_uit($db, 'm.id = ?', [$id], 1, true);
    vacom_json(['ok' => true, 'message' => $lijst[0], 'max_id' => vacom_max_message_id(), 'mx' => (int)vacom_meta('mx')]);
}

/* ---- Threads (v0.9) ---- */

case 'thread': {
    // De starter + alle antwoorden, oplopend.
    $berichtId = param_int($invoer, 'message_id');
    $st = $db->prepare('SELECT channel_id, parent_id FROM messages WHERE id = ?');
    $st->execute([$berichtId]);
    $m = $st->fetch();
    if (!$m) vacom_fout('Bericht niet gevonden.', 404);
    if ($m['parent_id'] !== null) $berichtId = (int)$m['parent_id']; // antwoord aangeklikt → de hele thread
    kanaal_voor_mij($db, (int)$m['channel_id'], $mijnId, true);
    $ouder = berichten_uit($db, 'm.id = ?', [$berichtId], 1, true);
    if (!$ouder) vacom_fout('Bericht niet gevonden.', 404);
    $antwoorden = berichten_uit($db, 'm.parent_id = ?', [$berichtId], 500, true);
    vacom_json(['ok' => true, 'parent' => $ouder[0], 'replies' => $antwoorden]);
}

/* ---- Typt… (v0.9) ---- */

case 'typ': {
    // De client roept dit hooguit elke 2,5 s aan zolang er getypt wordt. De
    // poll van de anderen leest wie er de laatste 6 s getypt heeft.
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    $db->prepare('INSERT OR REPLACE INTO typing(channel_id, user_id, at) VALUES (?, ?, ?)')->execute([(int)$k['id'], $mijnId, time()]);
    $db->exec('DELETE FROM typing WHERE at < ' . (time() - 30)); // oude rommel opruimen
    vacom_json(['ok' => true]);
}

/* ---- Vastzetten en opslaan (v0.9) ---- */

case 'pin_toggle': {
    // Vastzetten in een kanaal: voor iedereen zichtbaar bovenin. Elk lid mag.
    $berichtId = param_int($invoer, 'message_id');
    $st = $db->prepare('SELECT channel_id, deleted FROM messages WHERE id = ?');
    $st->execute([$berichtId]);
    $m = $st->fetch();
    if (!$m || (int)$m['deleted'] === 1) vacom_fout('Bericht niet gevonden.', 404);
    $k = kanaal_voor_mij($db, (int)$m['channel_id'], $mijnId);
    $st = $db->prepare('SELECT 1 FROM pins WHERE message_id = ?');
    $st->execute([$berichtId]);
    if ($st->fetchColumn()) {
        $db->prepare('DELETE FROM pins WHERE message_id = ?')->execute([$berichtId]);
        $vast = false;
    } else {
        $db->prepare('INSERT INTO pins(message_id, channel_id, user_id, created) VALUES (?, ?, ?, ?)')->execute([$berichtId, (int)$k['id'], $mijnId, vacom_now()]);
        $vast = true;
    }
    vacom_bump_mx();
    vacom_json(['ok' => true, 'pinned' => $vast, 'mx' => (int)vacom_meta('mx')]);
}

case 'kanaal_pins': {
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId, true);
    $lijst = berichten_uit($db, 'm.id IN (SELECT message_id FROM pins WHERE channel_id = ?) AND m.deleted = 0', [(int)$k['id']], 100, false);
    vacom_json(['ok' => true, 'messages' => $lijst]);
}

case 'opslaan_toggle': {
    // Privé bewaren ("later"): alleen jij ziet je opgeslagen berichten.
    $berichtId = param_int($invoer, 'message_id');
    $st = $db->prepare('SELECT channel_id, deleted FROM messages WHERE id = ?');
    $st->execute([$berichtId]);
    $m = $st->fetch();
    if (!$m || (int)$m['deleted'] === 1) vacom_fout('Bericht niet gevonden.', 404);
    kanaal_voor_mij($db, (int)$m['channel_id'], $mijnId, true);
    $st = $db->prepare('SELECT 1 FROM saved WHERE user_id = ? AND message_id = ?');
    $st->execute([$mijnId, $berichtId]);
    if ($st->fetchColumn()) {
        $db->prepare('DELETE FROM saved WHERE user_id = ? AND message_id = ?')->execute([$mijnId, $berichtId]);
        $opgeslagen = false;
    } else {
        $db->prepare('INSERT INTO saved(user_id, message_id, created) VALUES (?, ?, ?)')->execute([$mijnId, $berichtId, vacom_now()]);
        $opgeslagen = true;
    }
    vacom_json(['ok' => true, 'saved' => $opgeslagen]);
}

case 'opgeslagen': {
    $lijst = berichten_uit($db, 'm.id IN (SELECT message_id FROM saved WHERE user_id = ?) AND m.deleted = 0
        AND m.channel_id IN (SELECT channel_id FROM channel_members WHERE user_id = ?)', [$mijnId, $mijnId], 200, false);
    vacom_json(['ok' => true, 'messages' => array_reverse($lijst)]); // nieuwste eerst
}

/* ---- Pushmeldingen: abonnementen en voorkeur (v0.9) ---- */

case 'push_abonneer': {
    $sub = $invoer['subscription'] ?? null;
    $endpoint = (string)($sub['endpoint'] ?? '');
    $p256dh   = (string)($sub['keys']['p256dh'] ?? '');
    $auth     = (string)($sub['keys']['auth'] ?? '');
    if (!preg_match('~^https://~', $endpoint) || $p256dh === '' || $auth === '') vacom_fout('Ongeldig abonnement.');
    if (!vacom_vapid()) vacom_fout('Deze server kan geen pushmeldingen sturen.');
    $db->prepare('INSERT INTO push_subs(user_id, endpoint, p256dh, auth, created) VALUES (?, ?, ?, ?, ?)
                  ON CONFLICT(endpoint) DO UPDATE SET user_id = excluded.user_id, p256dh = excluded.p256dh, auth = excluded.auth')
       ->execute([$mijnId, $endpoint, $p256dh, $auth, vacom_now()]);
    vacom_json(['ok' => true]);
}

case 'push_afmelden': {
    $endpoint = (string)($invoer['endpoint'] ?? '');
    $db->prepare('DELETE FROM push_subs WHERE endpoint = ? AND user_id = ?')->execute([$endpoint, $mijnId]);
    vacom_json(['ok' => true]);
}

case 'push_instelling': {
    $alles = !empty($invoer['alles']) ? 1 : 0;
    $db->prepare('UPDATE users SET push_alles = ? WHERE id = ?')->execute([$alles, $mijnId]);
    vacom_json(['ok' => true, 'push_alles' => $alles === 1]);
}

case 'push_test': {
    // Een proefmelding naar je eigen apparaten — om te zien dat het werkt.
    $st = $db->prepare('SELECT endpoint, p256dh, auth FROM push_subs WHERE user_id = ?');
    $st->execute([$mijnId]);
    $subs = $st->fetchAll();
    if (!$subs) vacom_fout('Geen apparaat aangemeld voor meldingen.');
    $rapport = [];
    $weg = vacom_push_stuur($subs, ['title' => 'VaCom', 'body' => 'Meldingen werken op dit apparaat 🎉', 'url' => 'index.php', 'tag' => 'vacom-test'], $rapport);
    foreach ($weg as $ep) $db->prepare('DELETE FROM push_subs WHERE endpoint = ?')->execute([$ep]);
    // v0.9.5: per apparaat wat de meldingsdienst antwoordde, zodat de
    // Instellingen kunnen tonen wáár het misgaat (Chrome / Firefox / Apple).
    $gelukt = count(array_filter($rapport, fn($r) => $r['code'] >= 200 && $r['code'] < 300));
    vacom_json(['ok' => true, 'verstuurd' => $gelukt, 'resultaten' => $rapport]);
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
    push_bij_bericht($db, $k, $id, $mijnId, ($bijschrift !== '' ? $bijschrift . ' ' : '') . '📎 ' . $naam); // v0.9
    $lijst = berichten_uit($db, 'm.id = ?', [$id], 1, true);
    vacom_json(['ok' => true, 'message' => $lijst[0], 'max_id' => vacom_max_message_id()]);
}

/* ---- Polls / stemmingen (v0.8) ---- */

case 'stemming_maak': {
    // Een poll is een gewoon bericht (de vraag = tekst, dus doorzoekbaar) met
    // een stemmingen-rij eraan. Minstens 2, hoogstens 10 antwoorden.
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId);
    $vraag = mb_substr(param_str($invoer, 'question'), 0, 300);
    if ($vraag === '') vacom_fout('Wat is de vraag?');
    $opties = [];
    foreach ((array)($invoer['options'] ?? []) as $o) {
        $t = mb_substr(trim((string)$o), 0, 120);
        if ($t !== '') $opties[] = $t;
    }
    if (count($opties) < 2) vacom_fout('Geef minstens twee antwoorden.');
    if (count($opties) > 10) vacom_fout('Maximaal tien antwoorden.');
    $multi = !empty($invoer['multi']) ? 1 : 0;
    $db->beginTransaction();
    $db->prepare('INSERT INTO messages(channel_id, user_id, body, created) VALUES (?,?,?,?)')
       ->execute([(int)$k['id'], $mijnId, $vraag, vacom_now()]);
    $id = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO stemmingen(message_id, multi) VALUES (?, ?)')->execute([$id, $multi]);
    $sid = (int)$db->lastInsertId();
    $ins = $db->prepare('INSERT INTO stemming_opties(stemming_id, text, sort) VALUES (?, ?, ?)');
    foreach ($opties as $i => $t) $ins->execute([$sid, $t, $i]);
    vacom_vermeldingen_opslaan($db, $id, $vraag, $mijnId); // v0.7: @Naam in de vraag telt als vermelding
    $db->prepare('UPDATE channel_members SET last_read_id = MAX(last_read_id, ?) WHERE channel_id = ? AND user_id = ?')
       ->execute([$id, (int)$k['id'], $mijnId]);
    $terug = $db->prepare('UPDATE channel_members SET hidden = 0 WHERE channel_id = ? AND hidden = 1');
    $terug->execute([(int)$k['id']]);
    $db->commit();
    if ($terug->rowCount() > 0) vacom_bump_state();
    push_bij_bericht($db, $k, $id, $mijnId, '📊 ' . $vraag); // v0.9
    $lijst = berichten_uit($db, 'm.id = ?', [$id], 1, true);
    vacom_json(['ok' => true, 'message' => $lijst[0], 'max_id' => vacom_max_message_id()]);
}

case 'stemming_stem': {
    // Stemmen (of je stem wijzigen/intrekken): de meegegeven antwoorden
    // vervangen je eerdere keuze. Bij één antwoord telt alleen het eerste.
    $sid = param_int($invoer, 'stemming_id');
    $st = $db->prepare('SELECT s.*, m.channel_id, m.deleted FROM stemmingen s JOIN messages m ON m.id = s.message_id WHERE s.id = ?');
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s || (int)$s['deleted'] === 1) vacom_fout('Poll niet gevonden.', 404);
    kanaal_voor_mij($db, (int)$s['channel_id'], $mijnId); // lid, kanaal niet gesloten
    if ((int)$s['closed'] === 1) vacom_fout('Deze poll is gesloten.');
    $geldig = array_map('intval', $db->query('SELECT id FROM stemming_opties WHERE stemming_id = ' . (int)$s['id'])->fetchAll(PDO::FETCH_COLUMN));
    $keuze = array_values(array_unique(array_filter(array_map('intval', (array)($invoer['option_ids'] ?? [])), fn($o) => in_array($o, $geldig, true))));
    if ((int)$s['multi'] !== 1) $keuze = array_slice($keuze, 0, 1);
    $db->beginTransaction();
    $v = implode(',', array_fill(0, count($geldig), '?'));
    $db->prepare("DELETE FROM stemming_stemmen WHERE user_id = ? AND optie_id IN ($v)")->execute(array_merge([$mijnId], $geldig));
    $ins = $db->prepare('INSERT INTO stemming_stemmen(optie_id, user_id, created) VALUES (?, ?, ?)');
    foreach ($keuze as $o) $ins->execute([$o, $mijnId, vacom_now()]);
    $db->commit();
    vacom_bump_mx(); // anderen zien de nieuwe stand via de poll-'updates'
    vacom_json(['ok' => true, 'stemming' => stemming_van_bericht($db, (int)$s['message_id'], $mijnId), 'mx' => (int)vacom_meta('mx')]);
}

case 'stemming_sluit': {
    // Sluiten: wie de poll plaatste, of de superadmin. Daarna alleen nog de uitslag.
    $sid = param_int($invoer, 'stemming_id');
    $st = $db->prepare('SELECT s.*, m.channel_id, m.user_id, m.deleted FROM stemmingen s JOIN messages m ON m.id = s.message_id WHERE s.id = ?');
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s || (int)$s['deleted'] === 1) vacom_fout('Poll niet gevonden.', 404);
    if ((int)$s['user_id'] !== $mijnId && !vacom_is_superadmin()) vacom_fout('Alleen wie de poll plaatste (of de superadmin) kan hem sluiten.', 403);
    kanaal_voor_mij($db, (int)$s['channel_id'], $mijnId, true);
    $db->prepare('UPDATE stemmingen SET closed = 1 WHERE id = ?')->execute([(int)$s['id']]);
    vacom_bump_mx();
    vacom_json(['ok' => true, 'stemming' => stemming_van_bericht($db, (int)$s['message_id'], $mijnId), 'mx' => (int)vacom_meta('mx')]);
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
    $db->prepare("UPDATE messages SET body = '', deleted = 1, attachment = NULL, preview = NULL, edited = ? WHERE id = ?")->execute([vacom_now(), $berichtId]);
    $db->prepare('DELETE FROM stemmingen WHERE message_id = ?')->execute([$berichtId]); // v0.8: poll + stemmen mee weg
    $db->prepare('DELETE FROM pins WHERE message_id = ?')->execute([$berichtId]);       // v0.9
    $db->prepare('DELETE FROM saved WHERE message_id = ?')->execute([$berichtId]);      // v0.9
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
    // v0.7.1: voor de VaCom-knop in de Portal-header. v0.9.1: niet alleen
    // @vermeldingen maar álle ongelezen berichten per kanaal ('unread', zelfde
    // telling als de zijbalk), plus 'mentions' als deel daarvan. Alleen-lezen,
    // GET. 'totaal' = vermeldingen (naam uit v0.7.1, de Portal leest hem nog).
    $st = $db->prepare('SELECT c.id, c.type, c.name, c.dm_key, c.project_token,
        (SELECT COUNT(*) FROM messages x WHERE x.channel_id = c.id AND x.id > m.last_read_id AND x.user_id != m.user_id AND x.deleted = 0) AS unread,
        (SELECT COUNT(*) FROM mentions mn JOIN messages x ON x.id = mn.message_id
           WHERE x.channel_id = c.id AND mn.user_id = ? AND x.id > m.last_read_id AND x.deleted = 0) AS mentions
        FROM channels c JOIN channel_members m ON m.channel_id = c.id
        WHERE m.user_id = ? AND c.archived = 0 AND NOT (c.type = \'dm\' AND m.hidden = 1)');
    $st->execute([$mijnId, $mijnId]);
    $uit = []; $totaal = 0; $ongelezen = 0;
    foreach ($st->fetchAll() as $r) {
        $u = (int)$r['unread']; $n = (int)$r['mentions'];
        if ($u === 0 && $n === 0) continue;
        $label = '#' . $r['name'];
        if ($r['type'] === 'dm') {
            [$a, $b] = array_map('intval', explode(':', (string)$r['dm_key']));
            $ander = vacom_user_by_id($a === $mijnId ? $b : $a);
            $label = $ander ? (string)$ander['name'] : 'Gesprek';
        }
        $uit[] = ['id' => (int)$r['id'], 'label' => $label, 'project_token' => $r['project_token'] ?: '', 'unread' => $u, 'mentions' => $n];
        $totaal += $n; $ongelezen += $u;
    }
    // Kanalen waar je genoemd wordt bovenaan, daarna op aantal nieuw.
    usort($uit, fn($x, $y) => [$y['mentions'], $y['unread']] <=> [$x['mentions'], $x['unread']]);
    vacom_json(['ok' => true, 'totaal' => $totaal, 'ongelezen' => $ongelezen, 'kanalen' => $uit]);
}

case 'portal_push_info': {
    // v0.9.2: voor de meldingen-balk in de Portal. De Portal-pagina meldt zich
    // zelf aan bij de push-dienst (met VaCom's service worker en publieke
    // VAPID-sleutel) en bewaart het abonnement via push_abonneer — daarvoor
    // heeft ze het CSRF-token van deze sessie nodig. Alleen-lezen, GET, zelfde
    // origin; een lege 'vapid' betekent: deze server kan niet pushen.
    vacom_json(['ok' => true, 'vapid' => vacom_vapid_public(), 'csrf' => vacom_csrf()]);
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
    if (!VACOM_PROJECTKANALEN) vacom_json(['ok' => true, 'projects' => []]); // v0.13: voorlopig uit
    if (!VACOM_PORTAL) vacom_json(['ok' => true, 'projects' => []]);
    vacom_json(['ok' => true, 'projects' => vacom_projecten_beschikbaar($db, $ik)]);

case 'projectkanaal_aanmaken': {
    if (!VACOM_PROJECTKANALEN) vacom_fout('Projectkanalen staan voorlopig uit.'); // v0.13
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
        'kan_wissen'    => !$isProject && mag_kanaal_beheren($k, $mijnId),   // v0.13: ook wie het kanaal aanmaakte
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
    if (!mag_kanaal_beheren($k, $mijnId)) vacom_fout('Alleen de superadmin of wie het kanaal aanmaakte kan het verwijderen.', 403); // v0.13
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

case 'gesprek_verwijderen': {
    // v0.13: een gesprek (DM) definitief weg, inclusief berichten en bijlagen,
    // voor allebei. Mag door elk van de twee deelnemers (kanaal_voor_mij
    // controleert het lidmaatschap).
    $k = kanaal_voor_mij($db, param_int($invoer, 'channel_id'), $mijnId, true);
    if ($k['type'] !== 'dm') vacom_fout('Alleen een gesprek kun je zo verwijderen; een kanaal verwijder je via Kanaal-opties.');
    bijlagen_wis_kanaal($db, (int)$k['id']);
    $db->beginTransaction();
    $db->prepare('DELETE FROM reactions WHERE message_id IN (SELECT id FROM messages WHERE channel_id = ?)')->execute([(int)$k['id']]);
    $db->prepare('DELETE FROM messages WHERE channel_id = ?')->execute([(int)$k['id']]);
    $db->prepare('DELETE FROM channel_members WHERE channel_id = ?')->execute([(int)$k['id']]);
    $db->prepare('DELETE FROM channels WHERE id = ?')->execute([(int)$k['id']]);
    vacom_bump_state();
    $db->commit();
    vacom_json(['ok' => true] + vacom_overzicht($db, $ik));
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
    // v0.9: filters — van wie, in welk kanaal, en een periode (YYYY-MM-DD).
    $vanWie  = param_int($invoer, 'user_id');
    $inKanaal = param_int($invoer, 'channel_id');
    $vanaf = preg_match('/^\d{4}-\d{2}-\d{2}$/', param_str($invoer, 'from')) ? param_str($invoer, 'from') . 'T00:00:00Z' : '';
    $tot   = preg_match('/^\d{4}-\d{2}-\d{2}$/', param_str($invoer, 'to'))   ? param_str($invoer, 'to')   . 'T23:59:59Z' : '';
    $filters = ''; $fp = [];
    if ($vanWie)   { $filters .= ' AND m.user_id = ?';    $fp[] = $vanWie; }
    if ($inKanaal) { $filters .= ' AND m.channel_id = ?'; $fp[] = $inKanaal; }
    if ($vanaf)    { $filters .= ' AND m.created >= ?';   $fp[] = $vanaf; }
    if ($tot)      { $filters .= ' AND m.created <= ?';   $fp[] = $tot; }
    if ($q === '' && $filters === '') vacom_json(['ok' => true, 'results' => []]);
    if ($q !== '') {
        // Elke term als prefix-zoekterm, veilig tussen aanhalingstekens (FTS5-syntax).
        $termen = preg_split('/\s+/', $q) ?: [];
        $fts = implode(' ', array_map(fn($t) => '"' . str_replace('"', '""', $t) . '"*', $termen));
        $st = $db->prepare("SELECT m.id, m.channel_id, m.user_id, m.parent_id, m.body, m.created, m.edited, m.deleted, u.name AS author,
       snippet(messages_fts, 0, char(1), char(2), '…', 14) AS snippet
FROM messages_fts f
JOIN messages m ON m.id = f.rowid
JOIN users u ON u.id = m.user_id
JOIN channel_members cm ON cm.channel_id = m.channel_id AND cm.user_id = ?
WHERE messages_fts MATCH ? AND m.deleted = 0 $filters
ORDER BY m.id DESC LIMIT 50");
        $params = array_merge([$mijnId, $fts], $fp);
    } else {
        // Alleen filters, geen zoekwoord: gewoon de laatste berichten die eraan voldoen.
        $st = $db->prepare("SELECT m.id, m.channel_id, m.user_id, m.parent_id, m.body, m.created, m.edited, m.deleted, u.name AS author,
       substr(m.body, 1, 160) AS snippet
FROM messages m
JOIN users u ON u.id = m.user_id
JOIN channel_members cm ON cm.channel_id = m.channel_id AND cm.user_id = ?
WHERE m.deleted = 0 $filters
ORDER BY m.id DESC LIMIT 50");
        $params = array_merge([$mijnId], $fp);
    }
    try {
        $st->execute($params);
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
