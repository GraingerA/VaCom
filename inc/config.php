<?php
/**
 * VaCom â€” gedeelde configuratie en kleine helpers.
 *
 * EÃ©n plek voor versie, paden en de functies die elk bestand nodig heeft.
 * Gebruik: require_once __DIR__ . '/inc/config.php';
 */

declare(strict_types=1);

define('VACOM_VERSION', '0.7.3');
// Maximale grootte van een bijlage bij een bericht (v0.6). De server heeft
// daarnaast z'n eigen limiet (upload_max_filesize); die melden we apart.
define('VACOM_MAX_BIJLAGE', 25 * 1024 * 1024);
define('VACOM_ROOT', dirname(__DIR__));

// PHP's ingebouwde dev-server (php -S) meldt zich als 'cli-server'. Daarop
// draait VaCom lokaal; op de echte server is dit altijd false.
define('VACOM_IS_DEV', PHP_SAPI === 'cli-server');

// Eigen instellingen (lokaal, niet in git): inc/config.local.php.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// Portal-modus: staat VaCom in een submap van de Invite Project Portal
// (public_html/vacom/)? Dan vinden we config_ipp.php in de webroot. In dat
// geval deelt VaCom de login en de collega's van de Portal en krijgt elk
// Portal-project een kanaal (zie inc/portal.php). Anders: losse modus met
// eigen accounts.
$vacom_webroot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
if (!defined('VACOM_PORTAL_ROOT')) {
    if ($vacom_webroot !== '' && is_file($vacom_webroot . '/config_ipp.php')) {
        define('VACOM_PORTAL_ROOT', $vacom_webroot);
    } elseif (is_file(dirname(VACOM_ROOT) . '/config_ipp.php')) {
        define('VACOM_PORTAL_ROOT', dirname(VACOM_ROOT));
    } else {
        define('VACOM_PORTAL_ROOT', '');
    }
}
define('VACOM_PORTAL', VACOM_PORTAL_ROOT !== '');
if (!defined('VACOM_PORTAL_DATA')) {
    // Zelfde regel als de Portal zelf: ../ipp_data naast de webroot, anders data/ erin.
    $vacom_ipp = VACOM_PORTAL ? dirname(VACOM_PORTAL_ROOT) . '/ipp_data' : '';
    define('VACOM_PORTAL_DATA', VACOM_PORTAL ? (is_dir($vacom_ipp) ? $vacom_ipp : VACOM_PORTAL_ROOT . '/data') : '');
}
// Relatieve link naar de Portal (VaCom staat altijd precies Ã©Ã©n map dieper).
define('VACOM_PORTAL_URL', '../');

// Vaste basis-URL, zonder slash aan het eind. Hardcoded en niet afgeleid uit
// de Host-header (die is door een bezoeker te vervalsen).
if (!defined('VACOM_BASE_URL')) {
    define('VACOM_BASE_URL', VACOM_IS_DEV ? 'http://localhost:8400'
        : (VACOM_PORTAL ? 'https://portal.invite.nl/vacom' : 'https://test.invite.nl/vacom'));
}

// Datamap. Op de server staat die BUITEN de webroot (../vacom_data naast
// public_html), zodat de database nooit via de browser te downloaden is â€”
// .htaccess is op deze hosting niet betrouwbaar. Lokaal: gewoon ./data.
// Eigen pad nodig? Zet define('VACOM_DATA', ...) in inc/config.local.php.
if (!defined('VACOM_DATA')) {
    if (VACOM_IS_DEV || empty($_SERVER['DOCUMENT_ROOT'])) {
        define('VACOM_DATA', VACOM_ROOT . '/data');
    } else {
        define('VACOM_DATA', dirname(rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\')) . '/vacom_data');
    }
}

date_default_timezone_set('Europe/Amsterdam');

/* Vangnet voor een ontbrekende mbstring-extensie (zelfde als in de Portal). */
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s) { return strtolower((string)$s); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s) { return strlen((string)$s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null) {
        return $len === null ? substr((string)$s, $start) : substr((string)$s, $start, $len);
    }
}

function vacom_ensure_data_dir(): void {
    if (!is_dir(VACOM_DATA)) {
        @mkdir(VACOM_DATA, 0755, true);
    }
}

/** HTML-escapen voor uitvoer in pagina's. */
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Tijdstempel in UTC, ISO 8601 â€” JavaScript leest dit rechtstreeks met new Date(). */
function vacom_now(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
}

/** Naam normaliseren voor vergelijken en als unieke sleutel. */
function vacom_naam_sleutel(string $naam): string {
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $naam) ?? $naam));
}

/** Asset-URL met versie als cache-buster: na een upload haalt elke browser de nieuwe versie. */
function asset(string $pad): string {
    return h($pad) . '?v=' . VACOM_VERSION;
}

/**
 * Giphy API-sleutel (v0.5.3) voor de GIF-knop. Staat als platte tekst in
 * giphy_key.txt in de datamap: buiten de webroot en buiten Git, net als de
 * database. Geen bestand of leeg = geen GIF-knop, verder werkt alles gewoon.
 *   server : ../vacom_data/giphy_key.txt
 *   lokaal : vacom/data/giphy_key.txt
 */
function vacom_giphy_key(): string {
    static $sleutel = null;
    if ($sleutel === null) {
        $pad = VACOM_DATA . '/giphy_key.txt';
        $sleutel = is_file($pad) ? trim((string)file_get_contents($pad)) : '';
    }
    return $sleutel;
}

/** JSON-antwoord sturen en stoppen. */
function vacom_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vacom_fout(string $melding, int $status = 400): void {
    vacom_json(['ok' => false, 'fout' => $melding], $status);
}
