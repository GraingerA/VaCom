<?php
/**
 * VaCom — Portal-modus.
 *
 * Staat VaCom in een submap van de Invite Project Portal (public_html/vacom/),
 * dan is de Portal de bron van waarheid:
 *   - dezelfde inlogsessie (wie in de Portal is ingelogd, is hier ingelogd;
 *     klanten met rol 'external' komen er niet in);
 *   - dezelfde collega's (users.json → tabel users, zonder wachtwoord: inloggen
 *     gaat altijd via de Portal);
 *   - per Portal-project automatisch een kanaal (projects.json); verdwijnt een
 *     project in de prullenbak, dan wordt het kanaal gearchiveerd (blijft
 *     doorzoekbaar);
 *   - v0.4: van een projectkanaal is alleen lid wie in de Portal aan het
 *     project gekoppeld is (projectmanager of designer van een middel), plus
 *     wie handmatig is toegevoegd. Ook de superadmin ziet alleen zijn eigen
 *     projecten. Losse kanalen (#algemeen e.d.) blijven voor iedereen.
 *
 * Alles hier is LEZEN uit ipp_data. VaCom schrijft nooit in de Portal-data.
 * De synchronisatie is goedkoop: drie filemtime()-aanroepen per request, en
 * alleen als een van de bestanden veranderd is wordt er echt gewerkt.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function vacom_portal_lees(string $bestand): ?array {
    $pad = VACOM_PORTAL_DATA . '/' . $bestand;
    if (!is_file($pad)) return null;
    $data = json_decode((string)file_get_contents($pad), true);
    return is_array($data) ? $data : null;
}

/**
 * Het merk van het bezochte adres (v0.11, Portal v5.4+): Invite op
 * portal.invite.nl, Incombinatie op portal.incombinatie.nl. Komt uit de Portal
 * (merk_ipp.php, één bron voor naam, kleuren en logo's). Zonder Portal, of
 * zonder merk_ipp.php (oudere Portal): null = alles zoals Invite.
 */
function vacom_merk(): ?array {
    static $merk = false;
    if ($merk !== false) return $merk;
    $pad = VACOM_PORTAL ? VACOM_PORTAL_ROOT . '/merk_ipp.php' : '';
    if ($pad === '' || !is_file($pad)) return $merk = null;
    if (!defined('IPP_IS_DEV')) define('IPP_IS_DEV', VACOM_IS_DEV); // lokaal: ?merk= uit de Portal-sessie
    require_once $pad;
    return $merk = function_exists('ipp_merk') ? ipp_merk() : null;
}
/** Naam van het merk ("Invite" / "Incombinatie"). */
function vacom_merk_naam(): string {
    $m = vacom_merk();
    return (string)($m['naam'] ?? 'Invite');
}
/** Voor in de <head>, ná de stylesheets: favicon, kleurtokens en logo van het
 *  merk (ipp_merk_head van de Portal, met '../' omdat VaCom in een submap staat). */
function vacom_merk_head(): string {
    $m = vacom_merk();
    return ($m !== null && function_exists('ipp_merk_head')) ? ipp_merk_head($m, '../') : '';
}

/** v0.16.1: de favicon (svg) van VaCom, per merk: het VaCom-icoon van de Invite Hub
 *  (iconen/vacom.svg → assets/favicon.svg) of de Incombinatie-variant (vacom_inc.svg →
 *  assets/favicon_inc.svg). Hoort ná vacom_merk_head() in de <head>: de browser neemt
 *  de laatste <link rel="icon">. */
function vacom_favicon(): string {
    $m = vacom_merk();
    $sleutel = (string)($m['sleutel'] ?? 'invite');
    $pad = $sleutel !== 'invite' && is_file(VACOM_ROOT . '/assets/favicon_' . substr($sleutel, 0, 3) . '.svg')
        ? 'assets/favicon_' . substr($sleutel, 0, 3) . '.svg' : 'assets/favicon.svg';
    return '<link rel="icon" href="' . h(asset($pad)) . '" type="image/svg+xml">';
}

/** Interne Portal-gebruikers: [['name','role','email'], ...] — klanten (external) niet. */
function vacom_portal_gebruikers(): array {
    $data = vacom_portal_lees('users.json');
    $uit = [];
    foreach ($data['users'] ?? [] as $u) {
        $naam = trim((string)($u['name'] ?? ''));
        $rol  = (string)($u['role'] ?? '');
        if ($naam === '' || $rol === 'external') continue;
        $uit[] = ['name' => $naam, 'role' => $rol, 'email' => trim((string)($u['email'] ?? ''))];
    }
    return $uit;
}

/** Actieve projecten (token => [name, manager, gekoppeld]) en tokens in de prullenbak.
 *  'gekoppeld' = naam-sleutels van iedereen die in de Portal aan het project hangt:
 *  de projectmanager en de designers van de middelen (v0.4). */
function vacom_portal_projecten(): array {
    $actief = [];
    foreach (vacom_portal_lees('projects.json') ?? [] as $p) {
        $token = (string)($p['token'] ?? '');
        if ($token === '' || trim((string)($p['name'] ?? '')) === '') continue;
        $manager = trim((string)($p['manager'] ?? ''));
        $gekoppeld = [];
        if ($manager !== '') $gekoppeld[vacom_naam_sleutel($manager)] = true;
        // v0.11 (Portal v5.5.1): een gedeeld project kan een tweede projectmanager
        // van het andere bedrijf hebben; die hoort ook in het projectkanaal.
        foreach ((array)($p['managers_extra'] ?? []) as $m2) {
            $m2 = trim((string)$m2);
            if ($m2 !== '') $gekoppeld[vacom_naam_sleutel($m2)] = true;
        }
        foreach ($p['onderdelen'] ?? [] as $o) {
            $d = trim((string)($o['designer'] ?? ''));
            if ($d !== '') $gekoppeld[vacom_naam_sleutel($d)] = true;
        }
        // 'vacom' = de Portal heeft het kanaal voor dit project geactiveerd
        // (v0.5, vlag vacom_chat in projects.json — de Portal-kant volgt later).
        $actief[$token] = ['name' => trim((string)$p['name']), 'manager' => $manager, 'gekoppeld' => $gekoppeld,
                           'vacom' => !empty($p['vacom_chat']),
                           // v0.5.1: wie het kanaal vanuit de Portal startte (Portal v4.13) —
                           // die wordt lid, ook als hij niet aan het project gekoppeld is.
                           'vacom_door' => trim((string)($p['vacom_chat_by'] ?? '')),
                           // Wanneer de Portal de vlag (voor het laatst) zette; ouder dan
                           // een wis-actie van de superadmin = niet opnieuw aanmaken.
                           'vacom_at'   => (int)($p['vacom_chat_at'] ?? 0)];
    }
    $prullenbak = [];
    foreach (vacom_portal_lees('trash.json') ?? [] as $t) {
        $token = (string)($t['project']['token'] ?? '');
        if ($token !== '') $prullenbak[$token] = true;
    }
    return ['actief' => $actief, 'prullenbak' => $prullenbak];
}

/** Portal-rol → VaCom-rol. */
function vacom_portal_rol(string $rol): string {
    return $rol === 'superadmin' ? 'superadmin' : 'lid';
}

/** Kanaalnaam uit een projectnaam: "Poort van Wiarda" → "poort-van-wiarda". */
function vacom_slug(string $naam): string {
    $s = $naam;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && $t !== '') $s = $t;
    }
    $s = strtolower($s);
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s) ?? '', '-');
    $s = substr($s, 0, 40);
    return $s !== '' ? trim($s, '-') : 'project';
}

/** Maakt het kanaal voor een Portal-project aan (v0.5: alleen op verzoek — vanuit
 *  VaCom of via de Portal-vlag — niet meer automatisch voor elk project). Geeft
 *  het kanaal-id terug. $namenInGebruik houdt slugs uniek binnen één sync-ronde. */
function vacom_projectkanaal_maak(PDO $db, string $token, array $p, array &$namenInGebruik, string $nu): int {
    $slug = vacom_slug($p['name']);
    $naam = $slug;
    $n = 2;
    while (isset($namenInGebruik[$naam])) { $naam = $slug . '-' . $n++; }
    $namenInGebruik[$naam] = true;
    $topic = $p['manager'] !== '' ? 'Project · ' . $p['manager'] : 'Project';
    $db->prepare("INSERT INTO channels(type, name, topic, project_token, created) VALUES ('public', ?, ?, ?, ?)")
       ->execute([$naam, $topic, $token, $nu]);
    return (int)$db->lastInsertId();
}

/**
 * Projectkanaal starten vanuit VaCom (v0.5). Alleen voor wie in de Portal aan
 * het project gekoppeld is. Bestaat het kanaal al (bv. gesloten omdat het
 * project even in de prullenbak stond), dan wordt dat heropend. Daarna draait
 * de sync zodat alle gekoppelden lid zijn. Geeft het kanaal-id terug.
 */
function vacom_projectkanaal_activeren(PDO $db, string $token, array $ik): int {
    $projecten = vacom_portal_projecten();
    $p = $projecten['actief'][$token] ?? null;
    if ($p === null) throw new RuntimeException('Project niet gevonden in de Portal.');
    if (!isset($p['gekoppeld'][$ik['name_key']])) throw new RuntimeException('Je bent niet aan dit project gekoppeld.');
    $st = $db->prepare('SELECT id, archived FROM channels WHERE project_token = ?');
    $st->execute([$token]);
    $k = $st->fetch();
    if ($k) {
        if ((int)$k['archived'] === 1) $db->prepare('UPDATE channels SET archived = 0 WHERE id = ?')->execute([$k['id']]);
        $id = (int)$k['id'];
    } else {
        $namen = array_column($db->query("SELECT name FROM channels WHERE type = 'public'")->fetchAll(), 'name');
        $namenInGebruik = array_fill_keys($namen, true);
        $id = vacom_projectkanaal_maak($db, $token, $p, $namenInGebruik, vacom_now());
        $db->prepare('DELETE FROM meta WHERE key = ?')->execute(['pk_gewist:' . $token]); // bewust opnieuw gestart
    }
    vacom_portal_sync(true); // leden (gekoppelden) erbij, state-teller omhoog
    vacom_bump_state();
    return $id;
}

/** Heeft de superadmin dit projectkanaal gewist ná de laatste "start" vanuit de
 *  Portal? (v0.5.1, zie beheer_kanaal_wissen in api.php) */
function vacom_projectkanaal_gewist(PDO $db, string $token, int $vacomAt): bool {
    $st = $db->prepare('SELECT value FROM meta WHERE key = ?');
    $st->execute(['pk_gewist:' . $token]);
    $gewist = (int)($st->fetchColumn() ?: 0);
    return $gewist > 0 && $gewist >= $vacomAt;
}

/** Projecten waar deze gebruiker aan gekoppeld is en die nog geen kanaal hebben (v0.5). */
function vacom_projecten_beschikbaar(PDO $db, array $ik): array {
    $metKanaal = array_fill_keys(array_column($db->query('SELECT project_token FROM channels WHERE project_token IS NOT NULL')->fetchAll(), 'project_token'), true);
    $uit = [];
    foreach (vacom_portal_projecten()['actief'] as $token => $p) {
        if (isset($metKanaal[$token]) || !isset($p['gekoppeld'][$ik['name_key']])) continue;
        $uit[] = ['token' => $token, 'name' => $p['name'], 'manager' => $p['manager']];
    }
    usort($uit, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $uit;
}

/** Verandert zodra een van de Portal-bestanden verandert. */
function vacom_portal_stempel(): string {
    $delen = [];
    foreach (['users.json', 'projects.json', 'trash.json'] as $b) {
        $delen[] = (string)(@filemtime(VACOM_PORTAL_DATA . '/' . $b) ?: 0);
    }
    return implode(':', $delen);
}

/**
 * Portal → VaCom synchroniseren (gebruikers en projectkanalen).
 * Doet niets als de Portal-bestanden niet veranderd zijn sinds de vorige keer.
 */
function vacom_portal_sync(bool $forceer = false): void {
    if (!VACOM_PORTAL) return;
    $stempel = vacom_portal_stempel();
    // v0.4: zolang de lidmaatschapsregels van deze versie nog nooit zijn
    // toegepast, altijd draaien — ook als de Portal-bestanden niet veranderd
    // zijn. Dit vlaggetje wordt alleen door déze code gezet, zodat een oude
    // portal.php (bv. halverwege een FTP-upload) het niet per ongeluk kan
    // afvinken.
    $meta = vacom_metas(['lidmaatschap', 'portal_stempel']); // v0.18: één query (dit draait bij elk verzoek)
    if ($meta['lidmaatschap'] !== '4') $forceer = true;
    if (!$forceer && $stempel === $meta['portal_stempel']) return;

    $db = vacom_db();
    // v0.18: BEGIN IMMEDIATE — meteen het schrijfslot vragen. Na een wijziging in
    // de Portal zien alle open VaCom-vensters tegelijk een nieuwe stempel en
    // willen ze allemaal synchroniseren. Met een gewone transactie (eerst
    // lezen, dan schrijven) kreeg de tweede dan direct "database is locked";
    // nu wacht hij netjes (busy_timeout) en ziet daarna dat het al gedaan is.
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (!$forceer && $stempel === vacom_meta('portal_stempel')) { $db->exec('COMMIT'); return; }
        vacom_portal_sync_werk($db, $stempel);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $e2) { /* was al teruggedraaid */ }
        throw $e;
    }
}

/** Het eigenlijke werk van vacom_portal_sync(), binnen de transactie die daar geopend is. */
function vacom_portal_sync_werk(PDO $db, string $stempel): void {
    $veranderd = false;
    $nu = vacom_now();
    $maxId = vacom_max_message_id();

    /* ---- Gebruikers ---- */
    $bestaand = [];
    foreach ($db->query('SELECT id, name, name_key, role, email, active FROM users')->fetchAll() as $u) {
        $bestaand[$u['name_key']] = $u;
    }
    $gezien = [];
    $ins = $db->prepare("INSERT INTO users(name, name_key, email, role, password_hash, created) VALUES (?,?,?,?,'',?)");
    $upd = $db->prepare('UPDATE users SET name = ?, role = ?, email = ?, active = 1 WHERE id = ?');
    foreach (vacom_portal_gebruikers() as $pu) {
        $key = vacom_naam_sleutel($pu['name']);
        if (isset($gezien[$key])) continue;
        $gezien[$key] = true;
        $rol = vacom_portal_rol($pu['role']);
        if (isset($bestaand[$key])) {
            $b = $bestaand[$key];
            if ($b['name'] !== $pu['name'] || $b['role'] !== $rol || $b['email'] !== $pu['email'] || (int)$b['active'] !== 1) {
                $upd->execute([$pu['name'], $rol, $pu['email'], $b['id']]);
                $veranderd = true;
            }
        } else {
            $ins->execute([$pu['name'], $key, $pu['email'], $rol, $nu]);
            $veranderd = true;
        }
    }
    // Wie niet (meer) in de Portal staat, kan hier ook niet meer in.
    foreach ($bestaand as $key => $b) {
        if (!isset($gezien[$key]) && (int)$b['active'] === 1) {
            $db->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([$b['id']]);
            $veranderd = true;
        }
    }

    /* ---- Projectkanalen ---- */
    $projecten = vacom_portal_projecten();
    $kanalen = [];
    foreach ($db->query('SELECT id, name, archived, project_token FROM channels WHERE project_token IS NOT NULL')->fetchAll() as $k) {
        $kanalen[$k['project_token']] = $k;
    }
    $namen = array_column($db->query("SELECT name FROM channels WHERE type = 'public'")->fetchAll(), 'name');
    $namenInGebruik = array_fill_keys($namen, true);

    foreach ($projecten['actief'] as $token => $p) {
        $topic = $p['manager'] !== '' ? 'Project · ' . $p['manager'] : 'Project';
        if (isset($kanalen[$token])) {
            $k = $kanalen[$token];
            if ((int)$k['archived'] === 1) {
                $db->prepare('UPDATE channels SET archived = 0 WHERE id = ?')->execute([$k['id']]);
                $veranderd = true;
            }
            // Project hernoemd? Kanaal mee hernoemen als die naam nog vrij is.
            $slug = vacom_slug($p['name']);
            if ($k['name'] !== $slug && !isset($namenInGebruik[$slug])) {
                $db->prepare('UPDATE channels SET name = ? WHERE id = ?')->execute([$slug, $k['id']]);
                unset($namenInGebruik[$k['name']]);
                $namenInGebruik[$slug] = true;
                $veranderd = true;
            }
            $db->prepare('UPDATE channels SET topic = ? WHERE id = ? AND topic != ?')->execute([$topic, $k['id'], $topic]);
        } elseif ($p['vacom'] && !vacom_projectkanaal_gewist($db, $token, $p['vacom_at'])) {
            // v0.5: alleen nog aanmaken als de Portal het kanaal geactiveerd
            // heeft. Zonder vlag gebeurt er niets — een kanaal start je dan
            // vanuit VaCom (+ bij Projecten), zie vacom_projectkanaal_activeren().
            // v0.5.1: en niet als de superadmin het kanaal ná die vlag gewist heeft.
            $nieuwId = vacom_projectkanaal_maak($db, $token, $p, $namenInGebruik, $nu);
            // v0.5.1: de starter (vanuit de Portal) wordt 'handmatig' lid, zodat
            // ook een niet-gekoppelde collega (bv. de superadmin) het gesprek ziet.
            if ($p['vacom_door'] !== '' && ($starter = vacom_user_by_name($p['vacom_door'])) !== null) {
                $db->prepare("INSERT OR IGNORE INTO channel_members(channel_id, user_id, last_read_id, joined, via) VALUES (?, ?, ?, ?, 'handmatig')")
                   ->execute([$nieuwId, (int)$starter['id'], $maxId, $nu]);
            }
            $veranderd = true;
        }
    }
    // Project weg of in de prullenbak → kanaal archiveren (blijft doorzoekbaar).
    foreach ($kanalen as $token => $k) {
        if (!isset($projecten['actief'][$token]) && (int)$k['archived'] === 0) {
            $db->prepare('UPDATE channels SET archived = 1 WHERE id = ?')->execute([$k['id']]);
            $veranderd = true;
        }
    }

    /* ---- Losse kanalen: iedereen lid (nieuwe rijen beginnen bij "nu") ---- */
    $st = $db->prepare("INSERT OR IGNORE INTO channel_members(channel_id, user_id, last_read_id, joined, via)
                        SELECT c.id, u.id, ?, ?, 'auto' FROM channels c, users u
                        WHERE c.type = 'public' AND c.archived = 0 AND c.project_token IS NULL AND u.active = 1");
    $st->execute([$maxId, $nu]);
    if ($st->rowCount() > 0) $veranderd = true;

    /* ---- Projectkanalen: alleen wie gekoppeld is (v0.4) ----
       Lid via 'portal' = manager of designer van een middel, volgens de Portal.
       De sync voegt gekoppelden toe en haalt wie niet meer gekoppeld is er weer
       uit — maar laat 'handmatig' toegevoegde collega's met rust. Oude
       'auto'-rijen (van vóór v0.4, toen iedereen overal lid was) worden hier
       omgezet of opgeruimd. Superadmin krijgt bewust géén uitzondering. */
    $userIdPerKey = [];
    foreach ($db->query('SELECT id, name_key FROM users WHERE active = 1')->fetchAll() as $u) {
        $userIdPerKey[$u['name_key']] = (int)$u['id'];
    }
    $kanaalRijen = $db->query('SELECT id, project_token FROM channels WHERE project_token IS NOT NULL AND archived = 0')->fetchAll();
    $insLid  = $db->prepare("INSERT OR IGNORE INTO channel_members(channel_id, user_id, last_read_id, joined, via) VALUES (?,?,?,?,'portal')");
    $updLid  = $db->prepare("UPDATE channel_members SET via = 'portal' WHERE channel_id = ? AND user_id = ? AND via = 'auto'");
    foreach ($kanaalRijen as $k) {
        $keys = $projecten['actief'][$k['project_token']]['gekoppeld'] ?? [];
        $ids  = [];
        foreach (array_keys($keys) as $key) {
            if (isset($userIdPerKey[$key])) $ids[] = $userIdPerKey[$key];
        }
        foreach ($ids as $uid) {
            $insLid->execute([(int)$k['id'], $uid, $maxId, $nu]);
            if ($insLid->rowCount() > 0) $veranderd = true;
            $updLid->execute([(int)$k['id'], $uid]);
        }
        // Wie niet (meer) gekoppeld is en niet handmatig is toegevoegd → eruit.
        $sql = "DELETE FROM channel_members WHERE channel_id = ? AND via IN ('auto','portal')";
        $params = [(int)$k['id']];
        if ($ids) {
            $sql .= ' AND user_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        $del = $db->prepare($sql);
        $del->execute($params);
        if ($del->rowCount() > 0) $veranderd = true;
    }

    $db->prepare("INSERT OR REPLACE INTO meta(key, value) VALUES ('portal_stempel', ?)")->execute([$stempel]);
    $db->exec("INSERT OR REPLACE INTO meta(key, value) VALUES ('lidmaatschap', '4')");
    if ($veranderd) vacom_bump_state();
}

/**
 * De VaCom-gebruiker die hoort bij de Portal-sessie, of null (niet ingelogd,
 * of een klant). Maakt het VaCom-account aan als het nog niet bestaat.
 */
function vacom_portal_sessie_gebruiker(): ?array {
    $naam = trim((string)($_SESSION['logged_in_as'] ?? ''));
    if ($naam === '' || !empty($_SESSION['is_external'])) return null;
    vacom_portal_sync();
    $u = vacom_user_by_name($naam);
    if ($u === null) {
        vacom_portal_sync(true);
        $u = vacom_user_by_name($naam);
    }
    return ($u !== null && (int)$u['active'] === 1) ? $u : null;
}
