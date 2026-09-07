<?php
/**
 * VaCom — inloggen, sessies en gebruikers.
 *
 * Zelfde opzet als Rûnte en de Portal: wachtwoorden als hash, PHP-sessies
 * met veilige cookies (30 dagen sliding), CSRF-token op alle schrijfacties,
 * rate-limiting op de login, en een bootstrap-superadmin bij de eerste start.
 *
 * Rollen:
 *   superadmin — alles, inclusief gebruikersbeheer
 *   lid        — meepraten in kanalen en DM's
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/portal.php';

define('VACOM_SESSIE_DUUR', 30 * 24 * 60 * 60);
define('VACOM_BOOTSTRAP_FILE', VACOM_DATA . '/BOOTSTRAP_WACHTWOORD.txt');

/* ---------- Sessies ---------- */

/**
 * In Portal-modus gebruiken we bewust de standaard sessienaam: dat is precies
 * de sessie die de Portal zet bij het inloggen (met logged_in_as, role,
 * is_external). Los draaiend krijgt VaCom een eigen sessienaam zodat hij
 * niet botst met andere apps op dezelfde (gedeelde) server.
 */
function vacom_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.gc_maxlifetime', (string)VACOM_SESSIE_DUUR);
    if (!VACOM_PORTAL) session_name('vacom_sessie');
    $cookie = [
        'lifetime' => VACOM_SESSIE_DUUR,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookie);
    session_start();

    // Sliding expiry: elk bezoek van een ingelogde gebruiker verlengt de cookie.
    if (!empty($_SESSION['vacom_user_id']) || !empty($_SESSION['logged_in_as'])) {
        $cookie['expires'] = time() + VACOM_SESSIE_DUUR;
        unset($cookie['lifetime']);
        setcookie(session_name(), session_id(), $cookie);
    }
    if (empty($_SESSION['vacom_csrf'])) {
        $_SESSION['vacom_csrf'] = bin2hex(random_bytes(16));
    }
}

function vacom_csrf(): string {
    vacom_session_start();
    return $_SESSION['vacom_csrf'];
}

/** Controleer het CSRF-token (uit header of formulierveld). */
function vacom_csrf_ok(?string $token): bool {
    vacom_session_start();
    return is_string($token) && hash_equals($_SESSION['vacom_csrf'], trim($token));
}

/* ---------- Gebruikers ---------- */

function vacom_user_by_id(int $id): ?array {
    $st = vacom_db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ?: null;
}

function vacom_user_by_name(string $naam): ?array {
    $st = vacom_db()->prepare('SELECT * FROM users WHERE name_key = ?');
    $st->execute([vacom_naam_sleutel($naam)]);
    $u = $st->fetch();
    return $u ?: null;
}

/**
 * Nieuwe gebruiker aanmaken. Wordt meteen lid van alle openbare kanalen, met
 * de leesmarkering op "nu" — zodat een nieuwe collega niet begint met honderden
 * ongelezen berichten.
 */
function vacom_create_user(string $naam, string $wachtwoord, string $rol = 'lid', string $email = ''): ?array {
    $naam = trim(preg_replace('/\s+/', ' ', $naam) ?? $naam);
    if ($naam === '' || mb_strlen($naam) > 40 || vacom_user_by_name($naam) !== null) return null;
    if (mb_strlen($wachtwoord) < 8) return null;
    $db = vacom_db();
    $db->beginTransaction();
    $st = $db->prepare('INSERT INTO users(name, name_key, email, role, password_hash, created) VALUES (?,?,?,?,?,?)');
    $st->execute([$naam, vacom_naam_sleutel($naam), trim($email), $rol === 'superadmin' ? 'superadmin' : 'lid',
        password_hash($wachtwoord, PASSWORD_DEFAULT), vacom_now()]);
    $id = (int)$db->lastInsertId();
    $max = vacom_max_message_id();
    $db->prepare("INSERT INTO channel_members(channel_id, user_id, last_read_id, joined)
                  SELECT id, ?, ?, ? FROM channels WHERE type = 'public' AND archived = 0")
       ->execute([$id, $max, vacom_now()]);
    vacom_bump_state();
    $db->commit();
    return vacom_user_by_id($id);
}

/**
 * Allereerste start: geen gebruikers en geen kanalen? Maak #algemeen en een
 * superadmin "Ad" met een willekeurig wachtwoord. Dat wachtwoord staat
 * eenmalig in de datamap (buiten de webroot, dus alleen via FTP te lezen) en
 * wordt na de eerste geslaagde login automatisch verwijderd.
 */
function vacom_bootstrap_if_needed(): void {
    $db = vacom_db();
    if ((int)$db->query('SELECT COUNT(*) FROM channels')->fetchColumn() === 0) {
        $db->prepare("INSERT INTO channels(type, name, topic, created) VALUES ('public', 'algemeen', 'Voor alles en iedereen', ?)")
           ->execute([vacom_now()]);
    }
    // In Portal-modus komen de accounts uit de Portal; geen eigen superadmin nodig.
    if (VACOM_PORTAL) return;
    if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) return;

    $wachtwoord = bin2hex(random_bytes(12));
    if (vacom_create_user('Ad', $wachtwoord, 'superadmin') === null) return;
    $tekst  = "Petear — eenmalig startwachtwoord\n\n";
    $tekst .= "Gebruikersnaam : Ad\n";
    $tekst .= "Wachtwoord     : $wachtwoord\n\n";
    $tekst .= "Log hiermee in en wijzig daarna je wachtwoord via 'Gebruikers'.\n";
    $tekst .= "Dit bestand wordt na je eerste login automatisch verwijderd.\n";
    @file_put_contents(VACOM_BOOTSTRAP_FILE, $tekst, LOCK_EX);
}

/* ---------- Rate-limiting op de login ---------- */

function vacom_ratelimit_ok(string $sleutel, int $max, int $seconden): bool {
    $db = vacom_db();
    $nu = time();
    $st = $db->prepare('SELECT count, reset FROM ratelimit WHERE key = ?');
    $st->execute([$sleutel]);
    $rij = $st->fetch();
    if (!$rij || (int)$rij['reset'] < $nu) {
        $db->prepare('INSERT OR REPLACE INTO ratelimit(key, count, reset) VALUES (?, 1, ?)')->execute([$sleutel, $nu + $seconden]);
        return true;
    }
    if ((int)$rij['count'] >= $max) return false;
    $db->prepare('UPDATE ratelimit SET count = count + 1 WHERE key = ?')->execute([$sleutel]);
    return true;
}

function vacom_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'onbekend');
}

/* ---------- Inloggen / uitloggen ---------- */

/** Probeer in te loggen. Geeft de gebruiker terug, of een foutmelding als string. */
function vacom_login(string $naam, string $wachtwoord) {
    if (VACOM_PORTAL) return 'Log in via de Portal.';
    vacom_bootstrap_if_needed();
    if (!vacom_ratelimit_ok('login:' . vacom_client_ip(), 8, 15 * 60)) {
        return 'Te veel pogingen. Probeer het over een kwartier opnieuw.';
    }
    $u = vacom_user_by_name($naam);
    if ($u === null || !(int)$u['active'] || !password_verify(trim($wachtwoord), $u['password_hash'])) {
        return 'Naam of wachtwoord klopt niet.';
    }
    vacom_session_start();
    session_regenerate_id(true);
    $_SESSION['vacom_user_id'] = (int)$u['id'];
    $_SESSION['vacom_csrf'] = bin2hex(random_bytes(16));
    if (is_file(VACOM_BOOTSTRAP_FILE)) @unlink(VACOM_BOOTSTRAP_FILE);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        vacom_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                 ->execute([password_hash(trim($wachtwoord), PASSWORD_DEFAULT), $u['id']]);
    }
    return $u;
}

function vacom_logout(): void {
    vacom_session_start();
    $_SESSION = [];
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/']);
    session_destroy();
}

/**
 * De ingelogde gebruiker, of null. Wordt bij elk request opnieuw uit de
 * database gehaald: wie gedeactiveerd is, is meteen uitgelogd. Werkt ook
 * last_seen bij (hooguit één keer per minuut, om schrijfwerk te sparen).
 */
function vacom_user(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    vacom_session_start();
    if (VACOM_PORTAL) {
        $u = vacom_portal_sessie_gebruiker();
        if ($u === null) return $cache = null;
        $id = (int)$u['id'];
    } else {
        $id = (int)($_SESSION['vacom_user_id'] ?? 0);
        if ($id <= 0) return $cache = null;
        $u = vacom_user_by_id($id);
        if ($u === null || !(int)$u['active']) {
            unset($_SESSION['vacom_user_id']);
            return $cache = null;
        }
    }
    if (empty($u['last_seen']) || strtotime($u['last_seen']) < time() - 60) {
        $u['last_seen'] = vacom_now();
        vacom_db()->prepare('UPDATE users SET last_seen = ? WHERE id = ?')->execute([$u['last_seen'], $id]);
    }
    return $cache = $u;
}

function vacom_is_superadmin(): bool {
    $u = vacom_user();
    return $u !== null && $u['role'] === 'superadmin';
}

/** Voor HTML-pagina's: niet ingelogd → naar het inlogscherm. */
function vacom_require_login(): array {
    $u = vacom_user();
    if ($u === null) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

/** Publieke weergave van een gebruiker (nooit de hash of het e-mailadres). */
/** Pad van de avatarfoto van een gebruiker (v0.5): in de datamap, dus buiten
 *  de webroot; avatar.php serveert hem. */
function vacom_avatar_pad(int $id): string {
    return VACOM_DATA . '/avatars/' . $id . '.jpg';
}

function vacom_public_user(array $u): array {
    $avatarPad = vacom_avatar_pad((int)$u['id']);
    return [
        'id'        => (int)$u['id'],
        'name'      => $u['name'],
        'role'      => $u['role'],
        'email'     => (string)($u['email'] ?? ''),
        'status'    => (string)($u['status'] ?? ''),
        'active'    => (int)$u['active'] === 1,
        'last_seen' => $u['last_seen'],
        // v0.5: mtime van de avatarfoto (0 = geen foto). Dient ook als cache-buster.
        'avatar'    => is_file($avatarPad) ? (int)filemtime($avatarPad) : 0,
    ];
}
