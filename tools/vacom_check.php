<?php
/**
 * VaCom — servercheck.
 *
 * Zet dit bestand via FTP op de server (bijv. test.invite.nl/vacom/vacom_check.php),
 * open het in de browser en stuur de uitkomst door. Het test wat de hosting
 * aankan voor VaCom: SQLite + full-text search, de cryptografie voor
 * pushnotificaties, schrijfrechten buiten de webroot en de PHP-limieten.
 *
 * Verwijder het bestand daarna weer van de server — het toont serverdetails.
 *
 * ?lang=1 voert daarnaast een test uit die 20 seconden duurt, om te zien of
 * de hosting langere requests toestaat (relevant voor long-polling).
 */

declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

$rows = [];
function check(string $naam, bool $ok, string $detail = '', bool $verplicht = true): void {
    global $rows;
    $rows[] = [$naam, $ok, $detail, $verplicht];
}
function ini_row(string $key): void {
    check('php.ini: ' . $key, true, (string)ini_get($key), false);
}

/* ---------- Basis ---------- */
check('PHP-versie', PHP_VERSION_ID >= 80000, PHP_VERSION . ' (' . PHP_SAPI . ')');
check('HTTPS', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    (!empty($_SERVER['HTTPS']) ? 'ja' : 'nee') . ' — nodig voor PWA, service worker en push');
check('Server', true, ($_SERVER['SERVER_SOFTWARE'] ?? '?') . ' · ' . ($_SERVER['SERVER_PROTOCOL'] ?? '?'), false);

/* ---------- Extensies ---------- */
foreach ([
    'pdo_sqlite' => ['SQLite via PDO — de beoogde database', true],
    'sqlite3'    => ['SQLite3-extensie (reserve)', false],
    'openssl'    => ['OpenSSL — nodig voor pushnotificaties (VAPID)', true],
    'curl'       => ['cURL — om pushberichten te versturen', true],
    'mbstring'   => ['mbstring — nette tekstverwerking (emoji, accenten)', false],
    'json'       => ['JSON', true],
    'fileinfo'   => ['fileinfo — bestandstype van uploads herkennen', false],
    'gd'         => ['GD — thumbnails van afbeeldingen', false],
    'gmp'        => ['GMP — versnelt de push-cryptografie (niet verplicht)', false],
] as $ext => [$omschrijving, $verplicht]) {
    check("Extensie $ext", extension_loaded($ext), $omschrijving, $verplicht);
}

/* ---------- Schrijven buiten de webroot ---------- */
$webroot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?: __DIR__), '/\\');
$buiten  = dirname($webroot) . '/vacom_check_tmp';
$kanBuiten = @mkdir($buiten, 0755) || is_dir($buiten);
$kanBuiten = $kanBuiten && @file_put_contents($buiten . '/test.txt', 'ok') !== false;
check('Schrijven buiten de webroot', $kanBuiten,
    $kanBuiten ? "gelukt in $buiten" : "niet gelukt ($buiten) — dan gebruiken we .json.php-slot + submap in de webroot", false);
if ($kanBuiten) { @unlink($buiten . '/test.txt'); @rmdir($buiten); }

$lokaal = __DIR__ . '/vacom_check_tmp';
$kanLokaal = @mkdir($lokaal, 0755) || is_dir($lokaal);
$kanLokaal = $kanLokaal && @file_put_contents($lokaal . '/test.txt', 'ok') !== false;
check('Schrijven in de eigen map', $kanLokaal, $kanLokaal ? 'gelukt' : 'niet gelukt — maprechten controleren');
if ($kanLokaal) { @unlink($lokaal . '/test.txt'); @rmdir($lokaal); }

/* ---------- SQLite: WAL + FTS5 ---------- */
$dbPad = __DIR__ . '/vacom_check.sqlite';
try {
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('pdo_sqlite ontbreekt');
    $pdo = new PDO('sqlite:' . $dbPad, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $versie = $pdo->query('select sqlite_version()')->fetchColumn();
    $wal = $pdo->query('pragma journal_mode=wal')->fetchColumn();
    check('SQLite werkt', true, "versie $versie, journal_mode=$wal");
    check('SQLite WAL-modus', strtolower((string)$wal) === 'wal',
        'WAL laat lezen en schrijven tegelijk toe (meerdere collega\'s tegelijk)', false);
    try {
        $pdo->exec('create virtual table t using fts5(body)');
        $pdo->exec("insert into t(body) values ('VaCom is de interne chat van Invite'), ('hjoed gjin nijs')");
        $hit = $pdo->query("select count(*) from t where t match 'chat'")->fetchColumn();
        check('SQLite FTS5 (full-text search)', (int)$hit === 1, 'zoeken door alle berichten in milliseconden');
    } catch (Throwable $e) {
        check('SQLite FTS5 (full-text search)', false, 'niet beschikbaar (' . $e->getMessage() . ') — dan LIKE-zoeken of eigen index', false);
    }
    $pdo = null;
} catch (Throwable $e) {
    check('SQLite werkt', false, $e->getMessage() . ' — terugvallen op JSON-bestanden zoals de Portal');
}
foreach (['', '-wal', '-shm', '-journal'] as $suffix) { @unlink($dbPad . $suffix); }

/* ---------- Cryptografie voor Web Push (VAPID = ES256, payload = ECDH P-256) ---------- */
try {
    if (!extension_loaded('openssl')) throw new RuntimeException('openssl ontbreekt');
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($key === false) throw new RuntimeException('EC-sleutel (P-256) aanmaken mislukt: ' . openssl_error_string());
    $sig = '';
    $signed = openssl_sign('test', $sig, $key, OPENSSL_ALGO_SHA256);
    check('Push: ES256-handtekening (VAPID)', $signed && $sig !== '', 'de server kan zich bij Apple/Google identificeren');
    $key2 = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $pub2 = openssl_pkey_get_details($key2)['key'];
    $shared = function_exists('openssl_pkey_derive') ? openssl_pkey_derive($pub2, $key) : false;
    check('Push: ECDH-sleuteluitwisseling (payload-versleuteling)', $shared !== false && strlen((string)$shared) === 32,
        'berichten kunnen versleuteld naar de telefoon');
} catch (Throwable $e) {
    $hint = DIRECTORY_SEPARATOR === '\\' ? ' (op Windows meestal een ontbrekende openssl.cnf; op Linux-hosting doorgaans geen probleem)' : '';
    check('Push: cryptografie', false, $e->getMessage() . $hint . ' — dan alleen e-mailnotificaties, of push zonder inhoud');
}

/* ---------- Limieten ---------- */
foreach (['max_execution_time', 'memory_limit', 'upload_max_filesize', 'post_max_size', 'max_input_time', 'output_buffering'] as $k) ini_row($k);
check('Tijdzone', true, date_default_timezone_get(), false);

/* ---------- Lange request (optioneel) ---------- */
$langTest = null;
if (isset($_GET['lang'])) {
    $start = microtime(true);
    @set_time_limit(60);
    sleep(20);
    $langTest = round(microtime(true) - $start, 1);
}

/* ---------- Uitvoer ---------- */
$ok = array_reduce($rows, fn($c, $r) => $c && ($r[1] || !$r[3]), true);
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VaCom — servercheck</title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; background: #14181f; color: #e8e6df; margin: 0; padding: 24px; }
  h1 { color: #fbbc43; font-size: 22px; margin: 0 0 4px; }
  p.sub { color: #9aa3ad; margin: 0 0 20px; }
  table { border-collapse: collapse; width: 100%; max-width: 860px; }
  td { padding: 8px 10px; border-top: 1px solid #2a313b; vertical-align: top; }
  td.s { width: 28px; font-weight: bold; }
  .ok { color: #4da875; } .fout { color: #e0555f; } .warn { color: #f2bf5b; }
  td.d { color: #9aa3ad; font-size: 13px; }
  .kop { margin-top: 20px; padding: 12px; border-radius: 7px; background: #1d232c; }
  a { color: #8ecae8; }
</style>
</head>
<body>
<h1>VaCom — servercheck</h1>
<p class="sub"><?= htmlspecialchars((string)($_SERVER['HTTP_HOST'] ?? ''), ENT_QUOTES) ?> · <?= date('d-m-Y H:i') ?></p>

<div class="kop"><?= $ok
    ? '<span class="ok">Alles wat VaCom nodig heeft is aanwezig.</span>'
    : '<span class="warn">Niet alles is aanwezig — zie de rode regels; daar hebben we een alternatief voor.</span>' ?></div>

<table>
<?php foreach ($rows as [$naam, $goed, $detail, $verplicht]): ?>
<tr>
  <td class="s <?= $goed ? 'ok' : ($verplicht ? 'fout' : 'warn') ?>"><?= $goed ? '✓' : '✗' ?></td>
  <td><?= htmlspecialchars($naam, ENT_QUOTES) ?></td>
  <td class="d"><?= htmlspecialchars($detail, ENT_QUOTES) ?></td>
</tr>
<?php endforeach; ?>
<?php if ($langTest !== null): ?>
<tr>
  <td class="s <?= $langTest >= 19 ? 'ok' : 'fout' ?>"><?= $langTest >= 19 ? '✓' : '✗' ?></td>
  <td>Request van 20 seconden</td>
  <td class="d">duurde <?= $langTest ?> s — lange requests <?= $langTest >= 19 ? 'zijn toegestaan' : 'worden afgebroken' ?></td>
</tr>
<?php endif; ?>
</table>

<div class="kop">
  <?php if ($langTest === null): ?>
    Optioneel: <a href="?lang=1">test of een request van 20 seconden mag</a> (duurt even).<br>
  <?php endif; ?>
  Stuur een screenshot of de tekst van deze pagina door, en <strong>verwijder dit bestand daarna van de server</strong>.
</div>
</body>
</html>
