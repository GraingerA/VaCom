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
 *     doorzoekbaar).
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

/** Actieve projecten (token => [name, manager]) en tokens in de prullenbak. */
function vacom_portal_projecten(): array {
    $actief = [];
    foreach (vacom_portal_lees('projects.json') ?? [] as $p) {
        $token = (string)($p['token'] ?? '');
        if ($token === '' || trim((string)($p['name'] ?? '')) === '') continue;
        $actief[$token] = ['name' => trim((string)$p['name']), 'manager' => trim((string)($p['manager'] ?? ''))];
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
    if (!$forceer && $stempel === vacom_meta('portal_stempel')) return;

    $db = vacom_db();
    $db->beginTransaction();
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
        } else {
            $slug = vacom_slug($p['name']);
            $naam = $slug;
            $n = 2;
            while (isset($namenInGebruik[$naam])) { $naam = $slug . '-' . $n++; }
            $namenInGebruik[$naam] = true;
            $db->prepare("INSERT INTO channels(type, name, topic, project_token, created) VALUES ('public', ?, ?, ?, ?)")
               ->execute([$naam, $topic, $token, $nu]);
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

    /* ---- Iedereen lid van elk openbaar kanaal (nieuwe rijen beginnen bij "nu") ---- */
    $st = $db->prepare("INSERT OR IGNORE INTO channel_members(channel_id, user_id, last_read_id, joined)
                        SELECT c.id, u.id, ?, ? FROM channels c, users u
                        WHERE c.type = 'public' AND c.archived = 0 AND u.active = 1");
    $st->execute([$maxId, $nu]);
    if ($st->rowCount() > 0) $veranderd = true;

    $db->prepare("INSERT OR REPLACE INTO meta(key, value) VALUES ('portal_stempel', ?)")->execute([$stempel]);
    if ($veranderd) vacom_bump_state();
    $db->commit();
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
