<?php
/**
 * VaCom — gebruikers.
 * Losse modus — superadmin: collega's toevoegen, wachtwoord resetten,
 * (de)activeren, rol; iedereen: eigen wachtwoord wijzigen.
 * Portal-modus: accounts en wachtwoorden leven in de Portal; deze pagina
 * toont alleen wie er meedoen en verwijst door.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

$ik = vacom_require_login();
$db = vacom_db();
$admin = vacom_is_superadmin() && !VACOM_PORTAL;
$melding = '';
$fout = '';

if (!VACOM_PORTAL && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!vacom_csrf_ok($_POST['csrf'] ?? null)) {
        $fout = 'De pagina was verlopen. Probeer het opnieuw.';
    } else {
        $actie = (string)($_POST['actie'] ?? '');
        $doelId = (int)($_POST['user_id'] ?? 0);

        if ($actie === 'eigen_wachtwoord') {
            $huidig = (string)($_POST['huidig'] ?? '');
            $nieuw  = trim((string)($_POST['nieuw'] ?? ''));
            if (!password_verify(trim($huidig), $ik['password_hash'])) {
                $fout = 'Je huidige wachtwoord klopt niet.';
            } elseif (mb_strlen($nieuw) < 8) {
                $fout = 'Kies een nieuw wachtwoord van minstens 8 tekens.';
            } else {
                $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($nieuw, PASSWORD_DEFAULT), $ik['id']]);
                $melding = 'Je wachtwoord is gewijzigd.';
            }
        } elseif (!$admin) {
            $fout = 'Alleen een superadmin kan dit.';
        } elseif ($actie === 'nieuw') {
            $naam = (string)($_POST['naam'] ?? '');
            $ww   = trim((string)($_POST['wachtwoord'] ?? ''));
            $rol  = (string)($_POST['rol'] ?? 'lid');
            $u = vacom_create_user($naam, $ww, $rol, (string)($_POST['email'] ?? ''));
            if ($u === null) {
                $fout = 'Niet gelukt: naam leeg of al in gebruik, of wachtwoord korter dan 8 tekens.';
            } else {
                $melding = h($u['name']) . ' is toegevoegd en kan nu inloggen.';
            }
        } elseif ($actie === 'reset' && $doelId > 0) {
            $ww = trim((string)($_POST['wachtwoord'] ?? ''));
            if (mb_strlen($ww) < 8) {
                $fout = 'Nieuw wachtwoord: minstens 8 tekens.';
            } else {
                $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($ww, PASSWORD_DEFAULT), $doelId]);
                $melding = 'Wachtwoord is gereset.';
            }
        } elseif ($actie === 'actief' && $doelId > 0 && $doelId !== (int)$ik['id']) {
            $aan = (int)($_POST['aan'] ?? 0) ? 1 : 0;
            $db->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$aan, $doelId]);
            vacom_bump_state();
            $melding = $aan ? 'Account geactiveerd.' : 'Account gedeactiveerd — kan niet meer inloggen.';
        } elseif ($actie === 'rol' && $doelId > 0 && $doelId !== (int)$ik['id']) {
            $rol = ($_POST['rol'] ?? '') === 'superadmin' ? 'superadmin' : 'lid';
            $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$rol, $doelId]);
            vacom_bump_state();
            $melding = 'Rol gewijzigd.';
        }
    }
}

$gebruikers = $db->query('SELECT * FROM users ORDER BY active DESC, name COLLATE NOCASE')->fetchAll();
$csrf = vacom_csrf();
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f1319">
<title>Gebruikers — VaCom</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= h(vacom_gedeelde_stijl()) ?>">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
<?= vacom_merk_head() /* v0.11: kleuren en logo van het merk (Invite / Incombinatie), ná de stylesheets */ ?>
<?= vacom_favicon() /* v0.16.1: het nieuwe VaCom-icoon (per merk), ná de merk-head zodat deze wint */ ?>
</head>
<body class="pagina-body">
<script>try{if(localStorage.getItem('ipp_theme')==='light')document.body.classList.add('light-mode')}catch(e){}</script>
<main class="pagina">
  <header class="pagina-kop">
    <a class="knop" href="index.php"><i>←</i> Terug naar VaCom</a>
    <h1><?= VACOM_PORTAL ? 'Collega\'s' : ($admin ? 'Gebruikers' : 'Mijn account') ?></h1>
  </header>

  <?php if ($fout !== ''): ?><p class="meldregel fout"><?= h($fout) ?></p><?php endif; ?>
  <?php if ($melding !== ''): ?><p class="meldregel ok"><?= $melding ?></p><?php endif; ?>

  <?php if (VACOM_PORTAL): ?>
  <section class="vlak kaart">
    <h2>Accounts komen uit de Portal</h2>
    <p class="uitleg">VaCom gebruikt de inlog van de <?= h(vacom_merk_naam()) ?> Project Portal. Een collega toevoegen,
      een wachtwoord wijzigen of iemand de toegang ontnemen doe je dus <a href="<?= VACOM_PORTAL_URL ?>">in de Portal</a>;
      VaCom neemt dat vanzelf over. Klanten (externe accounts) hebben geen toegang tot VaCom.</p>
    <table class="tabel">
      <thead><tr><th>Naam</th><th>Rol in VaCom</th><th>Laatst gezien</th></tr></thead>
      <tbody>
      <?php foreach ($gebruikers as $u): if (!(int)$u['active']) continue; ?>
        <tr>
          <td><?= h($u['name']) ?><?= (int)$u['id'] === (int)$ik['id'] ? ' <span class="label grijs">jij</span>' : '' ?></td>
          <td><?= h($u['role']) ?></td>
          <td class="stil"><?= $u['last_seen'] ? h(date('d-m-Y H:i', strtotime($u['last_seen']))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php else: ?>

  <section class="vlak kaart">
    <h2>Mijn wachtwoord wijzigen</h2>
    <form method="post" class="form-rij">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="actie" value="eigen_wachtwoord">
      <div class="veldrij"><label class="veldlabel" for="ww-huidig">Huidig wachtwoord</label><input type="password" id="ww-huidig" class="veld" name="huidig" required autocomplete="current-password"></div>
      <div class="veldrij"><label class="veldlabel" for="ww-nieuw">Nieuw wachtwoord</label><input type="password" id="ww-nieuw" class="veld" name="nieuw" required minlength="8" autocomplete="new-password"></div>
      <button class="knop goud" type="submit">Wijzigen</button>
    </form>
  </section>

  <?php if ($admin): ?>
  <section class="vlak kaart">
    <h2>Collega toevoegen</h2>
    <p class="uitleg">Geef de collega de naam en het wachtwoord door; die kan het daarna zelf wijzigen.</p>
    <form method="post" class="form-rij">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="actie" value="nieuw">
      <div class="veldrij"><label class="veldlabel" for="nw-naam">Naam</label><input type="text" id="nw-naam" class="veld" name="naam" required maxlength="40" autocapitalize="words" placeholder="Voornaam"></div>
      <div class="veldrij"><label class="veldlabel" for="nw-email">E-mail <span>(optioneel)</span></label><input type="email" id="nw-email" class="veld" name="email" placeholder="naam@invite.nl"></div>
      <div class="veldrij"><label class="veldlabel" for="nw-wachtwoord">Wachtwoord</label><input type="text" id="nw-wachtwoord" class="veld" name="wachtwoord" required minlength="8" autocomplete="off" value="<?= h(substr(bin2hex(random_bytes(6)), 0, 10)) ?>"></div>
      <div class="veldrij"><label class="veldlabel" for="nw-rol">Rol</label>
        <select name="rol" id="nw-rol" class="veld"><option value="lid">Lid</option><option value="superadmin">Superadmin</option></select>
      </div>
      <button class="knop goud" type="submit">Toevoegen</button>
    </form>
  </section>

  <section class="vlak kaart">
    <h2>Alle gebruikers</h2>
    <table class="tabel">
      <thead><tr><th>Naam</th><th>Rol</th><th>Laatst gezien</th><th>Acties</th></tr></thead>
      <tbody>
      <?php foreach ($gebruikers as $u): $zelf = (int)$u['id'] === (int)$ik['id']; ?>
        <tr class="<?= (int)$u['active'] ? '' : 'inactief' ?>">
          <td><?= h($u['name']) ?><?= $zelf ? ' <span class="label grijs">jij</span>' : '' ?><?= (int)$u['active'] ? '' : ' <span class="label grijs">uit</span>' ?></td>
          <td>
            <?php if ($zelf): ?><?= h($u['role']) ?><?php else: ?>
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="actie" value="rol"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <select name="rol" class="veld klein" onchange="this.form.submit()">
                <option value="lid" <?= $u['role'] === 'lid' ? 'selected' : '' ?>>Lid</option>
                <option value="superadmin" <?= $u['role'] === 'superadmin' ? 'selected' : '' ?>>Superadmin</option>
              </select>
            </form>
            <?php endif; ?>
          </td>
          <td class="stil"><?= $u['last_seen'] ? h(date('d-m-Y H:i', strtotime($u['last_seen']))) : '—' ?></td>
          <td class="acties">
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="actie" value="reset"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input type="text" name="wachtwoord" placeholder="nieuw wachtwoord" minlength="8" required autocomplete="off" class="veld klein smal">
              <button class="knop klein" type="submit">Reset</button>
            </form>
            <?php if (!$zelf): ?>
            <form method="post" class="inline">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="actie" value="actief"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="aan" value="<?= (int)$u['active'] ? 0 : 1 ?>">
              <button class="knop klein" type="submit"><?= (int)$u['active'] ? 'Deactiveren' : 'Activeren' ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>
  <?php endif; ?>

  <p class="login-voet">VaCom v<?= VACOM_VERSION ?> · <a href="changelog.php">wat is er nieuw</a></p>
  <!-- v0.15: de voet van de Portal (.voet uit invite_stijl.css) -->
  <div class="voet">
    &copy; Copyright &mdash; <a href="<?= h(vacom_merk()['site'] ?? 'https://invite.nl/') ?>" target="_blank" rel="noopener noreferrer" class="voetlink"><?= h(vacom_merk()['lang'] ?? 'Invite Vastgoedcommunicatie') ?></a><br>
    VaCom v<?= VACOM_VERSION ?> &mdash; <a class="voetlink" href="changelog.php">changelog</a> &mdash; created by Ad Grainger &nbsp;&nbsp;&nbsp;*&#92;o/*
  </div>
  <div class="voet-logo"></div>
</main>
</body>
</html>
