<?php
/**
 * Petear — inloggen.
 *
 * Losse modus: naam + wachtwoord tegen de eigen gebruikerstabel.
 * Portal-modus: hetzelfde formulier, maar de browser stuurt naam + wachtwoord
 * naar de login-actie van de Portal (../api_ipp.php). Die zet de sessie, en
 * Petear leest die daarna. Eén wachtwoord voor beide apps; wie in de Portal al
 * ingelogd is, ziet dit scherm nooit.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

if (vacom_user() !== null) {
    header('Location: index.php');
    exit;
}
vacom_bootstrap_if_needed();

$fout = '';
if (VACOM_PORTAL && !empty($_SESSION['is_external'])) {
    $fout = 'Petear is alleen voor medewerkers van Invite.';
}
if (!VACOM_PORTAL && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
$eersteKeer = !VACOM_PORTAL && is_file(VACOM_BOOTSTRAP_FILE);
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f1319">
<title>Inloggen — Petear</title>
<link rel="icon" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="manifest" href="manifest.json">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body class="login-body">
<script>try{if(localStorage.getItem('ipp_theme')==='light')document.body.classList.add('light-mode')}catch(e){}</script>
<div class="aurora" aria-hidden="true"></div>
<main class="login-kaart glas">
  <div class="logo groot">
    <img class="logo-icoon" src="assets/icon-192.png" alt="">
    <span class="logo-naam">Petear</span>
  </div>
  <p class="login-sub">Het interne kanaal van Invite</p>

  <p class="melding fout" id="fout" <?= $fout === '' ? 'hidden' : '' ?>><?= h($fout) ?></p>
  <?php if ($eersteKeer): ?>
    <p class="melding info">Eerste start: het startwachtwoord voor <strong>Ad</strong> staat in
      <code>BOOTSTRAP_WACHTWOORD.txt</code> in de datamap.</p>
  <?php endif; ?>
  <?php if (VACOM_PORTAL): ?>
    <p class="melding info">Log in met je naam en wachtwoord van de <strong>Portal</strong>.</p>
  <?php endif; ?>

  <form method="post" autocomplete="on" id="form-login">
    <input type="hidden" name="csrf" value="<?= h(vacom_csrf()) ?>">
    <label>Naam
      <input type="text" name="naam" required autofocus autocapitalize="words" autocomplete="username"
             value="<?= h((string)($_POST['naam'] ?? '')) ?>">
    </label>
    <label>Wachtwoord
      <input type="password" name="wachtwoord" required autocomplete="current-password">
    </label>
    <button type="submit" class="knop primair breed" id="knop-login">Inloggen</button>
  </form>
  <p class="login-voet">Petear v<?= VACOM_VERSION ?> · Invite</p>
</main>
<?php if (VACOM_PORTAL): ?>
<script>
(() => {
  const form = document.getElementById('form-login');
  const fout = document.getElementById('fout');
  const knop = document.getElementById('knop-login');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    knop.disabled = true; fout.hidden = true;
    const fd = new FormData();
    fd.append('action', 'login');
    fd.append('name', form.naam.value.trim());
    fd.append('password', form.wachtwoord.value);
    try {
      const r = await fetch('<?= VACOM_PORTAL_URL ?>api_ipp.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json().catch(() => ({}));
      if (j.success && !j.is_external) { location.href = 'index.php'; return; }
      fout.textContent = j.success ? 'Petear is alleen voor medewerkers van Invite.' : (j.error || 'Inloggen is niet gelukt.');
      fout.hidden = false;
    } catch (err) {
      fout.textContent = 'Geen verbinding met de Portal.'; fout.hidden = false;
    }
    knop.disabled = false;
  });
})();
</script>
<?php endif; ?>
</body>
</html>
