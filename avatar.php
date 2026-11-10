<?php
/**
 * VaCom — avatarfoto serveren (v0.5).
 *
 * De foto's staan in de datamap (buiten de webroot), dus de browser kan er
 * niet rechtstreeks bij: dit script leest 'm en stuurt 'm door. Alleen voor
 * ingelogde collega's. De URL bevat de mtime als versie (?v=), dus de browser
 * mag agressief cachen: een nieuwe foto = een nieuwe URL.
 *
 * Aanroep: avatar.php?u=<gebruikers-id>&v=<mtime>
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

if (vacom_user() === null) { http_response_code(401); exit; }
session_write_close(); // v0.18: sessie vrijgeven, anders laden de foto's één voor één (en wacht de poll erop)

$id  = (int)($_GET['u'] ?? 0);
// v0.15: in Portal-modus de foto uit de Portal (ipp_data/profiel/…), anders de eigen
$u   = $id > 0 ? vacom_user_by_id($id) : null;
$pad = $u ? vacom_foto_pad($u) : vacom_avatar_pad($id);
if ($id <= 0 || !is_file($pad)) { http_response_code(404); exit; }

header('Content-Type: image/jpeg');
header('Content-Length: ' . (string)filesize($pad));
header('Cache-Control: private, max-age=31536000, immutable');
readfile($pad);
