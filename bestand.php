<?php
/**
 * VaCom — bijlage bij een bericht serveren (v0.6).
 *
 * De bestanden staan in de datamap (buiten de webroot); dit script leest ze
 * en stuurt ze door, alleen aan leden van het kanaal waar het bericht in
 * staat. Afbeeldingen en PDF's gaan inline (tonen in de browser), al het
 * andere als download — en nooit met een type waarmee de browser het als
 * pagina zou uitvoeren (html/svg/js worden een download).
 *
 * Aanroep: bestand.php?m=<bericht-id>            (het bestand zelf)
 *          bestand.php?m=<bericht-id>&thumb=1    (de kleine JPEG, alleen bij foto's)
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';

$ik = vacom_user();
if ($ik === null) { http_response_code(401); exit; }

$id    = (int)($_GET['m'] ?? 0);
$thumb = !empty($_GET['thumb']);
if ($id <= 0) { http_response_code(404); exit; }

$db = vacom_db();
$st = $db->prepare('SELECT m.attachment, m.deleted FROM messages m
                    JOIN channel_members cm ON cm.channel_id = m.channel_id AND cm.user_id = ?
                    WHERE m.id = ?');
$st->execute([(int)$ik['id'], $id]);
$r = $st->fetch();
$a = $r && (int)$r['deleted'] === 0 && !empty($r['attachment']) ? json_decode((string)$r['attachment'], true) : null;
if (!$a) { http_response_code(404); exit; }

$map = VACOM_DATA . '/bestanden/' . $id;
if ($thumb && !empty($a['thumb'])) {
    $pad = $map . '/thumb.jpg';
    $mime = 'image/jpeg';
    $naam = 'thumb.jpg';
    $inline = true;
} else {
    $naam = basename((string)$a['name']);
    $pad  = $map . '/' . $naam;
    $mime = (string)($a['mime'] ?? 'application/octet-stream');
    // Alleen echte beeld- en PDF-types inline; de rest als download met een
    // neutraal type (nooit text/html, image/svg+xml, javascript, ...).
    $inline = (bool)preg_match('#^(image/(jpeg|png|gif|webp)|application/pdf)$#', $mime);
    if (!$inline) $mime = 'application/octet-stream';
}
if (!is_file($pad)) { http_response_code(404); exit; }

$ascii = preg_replace('/[^\x20-\x7E]/', '_', $naam) ?: 'bestand';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($pad));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $ascii) . "\"; filename*=UTF-8''" . rawurlencode($naam));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=31536000, immutable'); // bericht-id is uniek, inhoud verandert nooit
readfile($pad);
