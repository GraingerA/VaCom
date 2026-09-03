<?php
/**
 * VaCom — inloggen.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

if (vacom_user() !== null) {
    header('Location: index.php');
    exit;
}
vacom_bootstrap_if_needed();

$fout = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!vacom_csrf_ok($_POST['csrf'] ?? null)) {
        $fout = 'De pagina was verlopen. Probeer het opnieuw.';
    } else {
        $r = vacom_login((string)($_POST['naam'] ?? ''), (string)($_POST['wachtwoord'] ?? ''));
        if (is_array($r)) {
            header('Location: index.php');
            exit;
        }
        $fout = $r;
    }
}
$eersteKeer = is_file(VACOM_BOOTSTRAP_FILE);
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f1319">
<title>Inloggen — VaCom</title>
<link rel="icon" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="manifest" href="manifest.json">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body class="login-body">
<div class="aurora" aria-hidden="true"></div>
<main class="login-kaart glas">
  <div class="logo groot">
    <span class="logo-bubbel">V</span>
    <span class="logo-naam">VaCom</span>
  </div>
  <p class="login-sub">Het interne kanaal van Invite</p>

  <?php if ($fout !== ''): ?>
    <p class="melding fout"><?= h($fout) ?></p>
  <?php endif; ?>
  <?php if ($eersteKeer): ?>
    <p class="melding info">Eerste start: het startwachtwoord voor <strong>Ad</strong> staat in
      <code>BOOTSTRAP_WACHTWOORD.txt</code> in de datamap.</p>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= h(vacom_csrf()) ?>">
    <label>Naam
      <input type="text" name="naam" required autofocus autocapitalize="words" autocomplete="username"
             value="<?= h((string)($_POST['naam'] ?? '')) ?>">
    </label>
    <label>Wachtwoord
      <input type="password" name="wachtwoord" required autocomplete="current-password">
    </label>
    <button type="submit" class="knop primair breed">Inloggen</button>
  </form>
  <p class="login-voet">VaCom v<?= VACOM_VERSION ?> · Invite</p>
</main>
</body>
</html>
