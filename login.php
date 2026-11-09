<?php
/**
 * VaCom — inloggen.
 *
 * Losse modus: naam + wachtwoord tegen de eigen gebruikerstabel.
 * Portal-modus: hetzelfde formulier, maar de browser stuurt naam + wachtwoord
 * naar de login-actie van de Portal (../api_ipp.php). Die zet de sessie, en
 * VaCom leest die daarna. Eén wachtwoord voor beide apps; wie in de Portal al
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
    $fout = 'VaCom is alleen voor medewerkers.';
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
<title>Inloggen — VaCom</title>
<link rel="apple-touch-icon" href="<?= asset('assets/apple-touch-icon.png') ?>">
<link rel="manifest" href="manifest.json">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= h(vacom_gedeelde_stijl()) ?>">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
<?= vacom_merk_head() /* v0.11: kleuren en logo van het merk (Invite / Incombinatie), ná de stylesheets */ ?>
<?= vacom_favicon() /* v0.16.1: het nieuwe VaCom-icoon (per merk), ná de merk-head zodat deze wint */ ?>
</head>
<body class="login-body">
<script>try{if(localStorage.getItem('ipp_theme')==='light')document.body.classList.add('light-mode')}catch(e){}</script>
<!-- v0.15: hetzelfde inlogvak als de Portal en de Hub (.inlog uit invite_stijl.css): de merkfoto,
     Inloggen met Microsoft (alleen in Portal-modus, via ms_login_ipp.js van de Portal) en het wachtwoord. -->
<div class="inlog">
  <div class="inlog-foto"></div>
  <div class="inlog-inhoud">
    <div class="inlog-titel">VaCom</div>
    <p class="inlog-tekst">Het interne kanaal van <?= h(vacom_merk_naam()) ?></p>
    <div class="inlog-melding" id="fout"<?= $fout === '' ? '' : ' style="display:block"' ?>><?= h($fout) ?></div>
    <?php if ($eersteKeer): ?>
      <p class="inlog-tekst">Eerste start: het startwachtwoord voor <strong>Ad</strong> staat in <code>BOOTSTRAP_WACHTWOORD.txt</code> in de datamap.</p>
    <?php endif; ?>
    <?php if (VACOM_PORTAL): ?>
    <div id="login-ms-blok" hidden>
      <button type="button" class="knop breed glas inlog-ms" id="login-ms">Inloggen met Microsoft</button>
      <div class="inlog-of"><span>of met je wachtwoord</span></div>
    </div>
    <?php endif; ?>
    <form method="post" autocomplete="on" id="form-login">
      <input type="hidden" name="csrf" value="<?= h(vacom_csrf()) ?>">
      <input type="text" id="login-naam" class="veld groot glas" name="naam" required autocapitalize="words" autocomplete="username" placeholder="Jouw naam"
             value="<?= h((string)($_POST['naam'] ?? '')) ?>">
      <input type="password" id="login-wachtwoord" class="veld groot glas" name="wachtwoord" required autocomplete="current-password" placeholder="Jouw wachtwoord">
      <button type="submit" class="knop goud breed" id="knop-login">Inloggen</button>
    </form>
    <?php if (VACOM_PORTAL): ?><a class="tekstlink inlog-link" href="<?= VACOM_PORTAL_URL ?>">Naar de Portal</a><?php endif; ?>
  </div>
</div>
<div class="voet login">
  &copy; Copyright &mdash; <a href="<?= h(vacom_merk()['site'] ?? 'https://invite.nl/') ?>" target="_blank" rel="noopener noreferrer" class="voetlink"><?= h(vacom_merk()['lang'] ?? 'Invite Vastgoedcommunicatie') ?></a><br>
  VaCom v<?= VACOM_VERSION ?> &mdash; created by Ad Grainger &nbsp;&nbsp;&nbsp;*&#92;o/*
</div>
<?php if (VACOM_PORTAL): ?>
<script src="<?= VACOM_PORTAL_URL ?>ms_login_ipp.js"></script><!-- v0.15: Inloggen met Microsoft, uit de Portal (knop blijft weg zonder ipp_data/microsoft.json) -->
<script>
(() => {
  const form = document.getElementById('form-login');
  const fout = document.getElementById('fout');
  const knop = document.getElementById('knop-login');
  const meld = (t) => { fout.textContent = t; fout.style.display = t ? 'block' : 'none'; };
  // v0.15: de Microsoft-knop; redirectUri = deze pagina (moet in Entra als SPA-redirect staan)
  if (window.IPP_MS) IPP_MS.init({ blok: document.getElementById('login-ms-blok'), knop: document.getElementById('login-ms'), melding: meld, redirectUri: location.origin + location.pathname });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    knop.disabled = true; meld('');
    const fd = new FormData();
    fd.append('action', 'login');
    fd.append('name', form.naam.value.trim());
    fd.append('password', form.wachtwoord.value);
    try {
      const r = await fetch('<?= VACOM_PORTAL_URL ?>api_ipp.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json().catch(() => ({}));
      if (j.success && !j.is_external) { location.href = 'index.php'; return; }
      meld(j.success ? 'VaCom is alleen voor medewerkers.' : (j.error || 'Inloggen is niet gelukt.'));
    } catch (err) {
      meld('Geen verbinding met de Portal.');
    }
    knop.disabled = false;
  });
})();
</script>
<?php endif; ?>
</body>
</html>
