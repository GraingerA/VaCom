<?php
/**
 * wurd — de app. Eén pagina; assets/app.js doet de rest via api.php.
 * Het eerste overzicht (kanalen, collega's) wordt hier al meegegeven, zodat de
 * app direct staat zonder extra rondje naar de server.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

$ik = wurd_require_login();

// Het overzicht bouwen we met dezelfde code als de API, zodat het nooit uiteenloopt.
$db = wurd_db();
require_once __DIR__ . '/inc/overzicht.php';
$start = [
    'csrf'      => wurd_csrf(),
    'overzicht' => wurd_overzicht($db, $ik),
    'is_dev'    => WURD_IS_DEV,
];
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="theme-color" content="#0f1319">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="wurd">
<title>wurd</title>
<link rel="icon" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="manifest" href="manifest.json">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body data-pane="lijst">
<div class="aurora" aria-hidden="true"></div>

<div class="app">

  <!-- Zijbalk: kanalen, DM's, collega's -->
  <aside class="zijbalk glas" id="zijbalk">
    <div class="zijbalk-kop">
      <div class="logo">
        <span class="logo-bubbel">w</span>
        <span class="logo-naam">wurd</span>
      </div>
      <button class="knop icoon" id="knop-zoeken" title="Zoeken" aria-label="Zoeken">
        <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </button>
    </div>

    <nav class="zijbalk-lijsten">
      <section>
        <h2>Kanalen <button class="knop mini" id="knop-kanaal-nieuw" title="Nieuw kanaal">+</button></h2>
        <ul id="lijst-kanalen"></ul>
      </section>
      <section>
        <h2>Berichten</h2>
        <ul id="lijst-dms"></ul>
      </section>
      <section>
        <h2>Collega's</h2>
        <ul id="lijst-collegas"></ul>
      </section>
    </nav>

    <div class="zijbalk-voet">
      <span class="ik"><span class="stip online"></span><?= h($ik['name']) ?></span>
      <span class="voet-knoppen">
        <a class="knop mini" href="gebruikers.php" title="<?= wurd_is_superadmin() ? 'Gebruikers' : 'Mijn wachtwoord' ?>">⚙</a>
        <button class="knop mini" id="knop-uitloggen" title="Uitloggen">⏻</button>
      </span>
    </div>
  </aside>

  <!-- Hoofdvlak: het geopende kanaal -->
  <main class="chat" id="chat">
    <header class="chat-kop glas">
      <button class="knop icoon alleen-mobiel" id="knop-terug" aria-label="Terug naar overzicht">‹</button>
      <div class="chat-titel">
        <h1 id="chat-naam">Welkom</h1>
        <p id="chat-topic" class="chat-topic"></p>
      </div>
    </header>

    <div class="berichten" id="berichten">
      <div class="leeg" id="leeg">
        <div class="logo groot"><span class="logo-bubbel">w</span><span class="logo-naam">wurd</span></div>
        <p>Kies links een kanaal of een collega.</p>
      </div>
    </div>

    <form class="schrijver glas" id="schrijver" hidden>
      <textarea id="invoer" rows="1" placeholder="Schrijf een bericht…" autocomplete="off" enterkeyhint="send"></textarea>
      <button type="submit" class="knop primair" id="knop-stuur" aria-label="Versturen">
        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M3 11.5 21 3l-8.5 18-2.5-7.5z" fill="currentColor"/></svg>
      </button>
    </form>
  </main>
</div>

<!-- Zoeken -->
<dialog id="dialoog-zoeken" class="dialoog">
  <form method="dialog" class="dialoog-kop">
    <input type="search" id="zoek-invoer" placeholder="Zoek in alle berichten…" autocomplete="off">
    <button class="knop mini" value="sluit" aria-label="Sluiten">✕</button>
  </form>
  <div id="zoek-resultaten" class="zoek-resultaten"></div>
</dialog>

<!-- Nieuw kanaal -->
<dialog id="dialoog-kanaal" class="dialoog klein">
  <form id="form-kanaal">
    <h2>Nieuw kanaal</h2>
    <label>Naam
      <div class="hash-veld"><span>#</span><input type="text" id="kanaal-naam" required maxlength="40" autocapitalize="none" placeholder="bijv. projecten"></div>
    </label>
    <label>Onderwerp (optioneel)
      <input type="text" id="kanaal-topic" maxlength="200" placeholder="Waar gaat dit kanaal over?">
    </label>
    <p class="melding fout" id="kanaal-fout" hidden></p>
    <div class="dialoog-knoppen">
      <button type="button" class="knop" id="kanaal-annuleer">Annuleren</button>
      <button type="submit" class="knop primair">Aanmaken</button>
    </div>
  </form>
</dialog>

<!-- Installatie-tip voor iPhone -->
<div class="banner" id="banner-ios" hidden>
  <p>Zet wurd op je beginscherm voor meldingen: tik op <strong>Delen</strong> <span class="ios-deel">⎋</span> en dan <strong>Zet op beginscherm</strong>.</p>
  <button class="knop mini" id="banner-ios-sluit" aria-label="Sluiten">✕</button>
</div>

<script>window.WURD = <?= json_encode($start, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= asset('assets/app.js') ?>"></script>
</body>
</html>
