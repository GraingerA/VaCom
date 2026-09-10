<?php
/**
 * VaCom — pushmeldingen (Web Push, v0.9).
 *
 * Puur PHP + OpenSSL, geen library: VAPID (ES256-JWT) en de versleuteling van
 * de inhoud volgens RFC 8291 (aes128gcm). Het VAPID-sleutelpaar wordt bij het
 * eerste gebruik aangemaakt en bewaard in de datamap (vapid.json, buiten de
 * webroot en buiten Git). De browser abonneert zich met de publieke sleutel;
 * de server stuurt versleutelde berichtjes naar de push-dienst van de
 * browser (Apple, Google, Mozilla), die ze op het apparaat aflevert.
 *
 * Wat de push-dienst ziet: alleen versleutelde bytes en het endpoint. Wat wij
 * bewaren: per apparaat het endpoint en twee sleutels van de browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Windows-ontwikkelmachine: OpenSSL vindt zijn configuratie anders niet en kan
// dan geen EC-sleutels maken. Op de Linux-server is dit niet nodig.
if (PHP_OS_FAMILY === 'Windows' && !getenv('OPENSSL_CONF') && is_file('C:/php/extras/ssl/openssl.cnf')) {
    putenv('OPENSSL_CONF=C:/php/extras/ssl/openssl.cnf');
}

function vacom_b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function vacom_b64url_decode(string $s): string {
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** Kan deze server pushen? (OpenSSL met EC, HKDF, AES-GCM en cURL) */
function vacom_push_kan(): bool {
    return function_exists('openssl_pkey_new') && function_exists('openssl_pkey_derive')
        && function_exists('hash_hkdf') && function_exists('curl_multi_init');
}

/** Raw (65 bytes, 0x04||x||y) publieke sleutel uit een OpenSSL-key. */
function vacom_ec_pub_raw($key): string {
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** VAPID-sleutelpaar: laden, of bij het eerste gebruik aanmaken. null = kan niet. */
function vacom_vapid(): ?array {
    static $keys = null;
    if ($keys !== null) return $keys ?: null;
    $pad = VACOM_DATA . '/vapid.json';
    if (is_file($pad)) {
        $keys = json_decode((string)file_get_contents($pad), true) ?: false;
        return $keys ?: null;
    }
    if (!vacom_push_kan()) { $keys = false; return null; }
    $k = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k || !openssl_pkey_export($k, $pem)) { $keys = false; return null; }
    $keys = ['private_pem' => $pem, 'public' => vacom_b64url(vacom_ec_pub_raw($k))];
    vacom_ensure_data_dir();
    file_put_contents($pad, json_encode($keys, JSON_PRETTY_PRINT), LOCK_EX);
    return $keys;
}

function vacom_vapid_public(): string {
    $k = vacom_vapid();
    return $k ? $k['public'] : '';
}

/** ECDSA-handtekening van DER naar raw r||s (64 bytes), zoals JWT wil. */
function vacom_der_naar_raw(string $der): string {
    $pos = 1;
    $seqLen = ord($der[$pos++]);
    if ($seqLen & 0x80) $pos += $seqLen & 0x7F;
    $uit = '';
    for ($i = 0; $i < 2; $i++) {
        $pos++; // INTEGER-tag
        $len = ord($der[$pos++]);
        $int = ltrim(substr($der, $pos, $len), "\0");
        $pos += $len;
        $uit .= str_pad($int, 32, "\0", STR_PAD_LEFT);
    }
    return $uit;
}

/** Het VAPID-token (JWT, ES256) voor één push-dienst ($aud = scheme://host). */
function vacom_vapid_jwt(string $aud, array $keys): string {
    $kop    = vacom_b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = vacom_b64url(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:ad@invite.nl']));
    $priv   = openssl_pkey_get_private($keys['private_pem']);
    openssl_sign("$kop.$claims", $sig, $priv, OPENSSL_ALGO_SHA256);
    return "$kop.$claims." . vacom_b64url(vacom_der_naar_raw($sig));
}

/** Raw publieke sleutel (65 bytes) → OpenSSL-key, via een DER SubjectPublicKeyInfo. */
function vacom_ec_pub_key(string $raw) {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

/** Versleutelt de inhoud voor één abonnee (RFC 8291, aes128gcm). null = kan niet. */
function vacom_push_versleutel(string $p256dh, string $auth, string $inhoud): ?string {
    $clientPub  = vacom_b64url_decode($p256dh);
    $authSecret = vacom_b64url_decode($auth);
    if (strlen($clientPub) !== 65 || strlen($authSecret) !== 16) return null;
    $eph = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$eph) return null;
    $ephPub    = vacom_ec_pub_raw($eph);
    $clientKey = vacom_ec_pub_key($clientPub);
    if (!$clientKey) return null;
    $shared = openssl_pkey_derive($clientKey, $eph, 32);
    if (!$shared) return null;
    $ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $clientPub . $ephPub, $authSecret);
    $salt  = random_bytes(16);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $tag   = '';
    $ct    = openssl_encrypt($inhoud . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ct === false) return null;
    // Header: salt (16) · recordgrootte (4) · lengte sleutel (1) · sleutel (65), dan de versleutelde inhoud + tag.
    return $salt . pack('N', 4096) . chr(65) . $ephPub . $ct . $tag;
}

/**
 * Stuurt één melding naar een lijst abonnementen [{endpoint, p256dh, auth}].
 * Parallel via curl_multi, korte timeouts. Geeft de endpoints terug die niet
 * meer bestaan (404/410), zodat de aanroeper ze kan opruimen.
 */
function vacom_push_stuur(array $subs, array $melding): array {
    $keys = vacom_vapid();
    if (!$keys || !$subs) return [];
    $json = json_encode($melding, JSON_UNESCAPED_UNICODE);
    $mh = curl_multi_init();
    $handles = [];
    foreach ($subs as $s) {
        $body = vacom_push_versleutel((string)$s['p256dh'], (string)$s['auth'], $json);
        $u = parse_url((string)$s['endpoint']);
        if ($body === null || empty($u['scheme']) || empty($u['host'])) continue;
        $jwt = vacom_vapid_jwt($u['scheme'] . '://' . $u['host'], $keys);
        $c = curl_init((string)$s['endpoint']);
        curl_setopt_array($c, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm',
                'TTL: 86400', 'Urgency: normal',
                'Authorization: vapid t=' . $jwt . ', k=' . $keys['public'],
                'Content-Length: ' . strlen($body),
            ],
        ]);
        curl_multi_add_handle($mh, $c);
        $handles[] = [$c, (string)$s['endpoint']];
    }
    do {
        $status = curl_multi_exec($mh, $actief);
        if ($actief) curl_multi_select($mh, 1.0);
    } while ($actief && $status === CURLM_OK);
    $weg = [];
    foreach ($handles as [$c, $endpoint]) {
        $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        if ($code === 404 || $code === 410) $weg[] = $endpoint;
        elseif ($code < 200 || $code >= 300) error_log("VaCom push: HTTP $code voor $endpoint: " . substr((string)curl_multi_getcontent($c), 0, 200));
        curl_multi_remove_handle($mh, $c);
        curl_close($c);
    }
    curl_multi_close($mh);
    return $weg;
}
