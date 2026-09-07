<?php
/**
 * Petear — de app. Eén pagina; assets/app.js doet de rest via api.php.
 * Het eerste overzicht (kanalen, collega's) wordt hier al meegegeven, zodat de
 * app direct staat zonder extra rondje naar de server.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';

$ik = vacom_require_login();

// Het overzicht bouwen we met dezelfde code als de API, zodat het nooit uiteenloopt.
$db = vacom_db();
require_once __DIR__ . '/inc/overzicht.php';
// v0.5.1: ingesloten in de Portal (popup op de projectkaart, Portal v4.13):
// ?embed=1&project=TOKEN toont alleen de gesprekskaart van dat projectkanaal.
// Zonder embed opent ?project=TOKEN gewoon dat kanaal in de volledige app.
$embed   = !empty($_GET['embed']);
$project = preg_replace('/[^a-f0-9]/', '', (string)($_GET['project'] ?? ''));
$start = [
    'csrf'      => vacom_csrf(),
    'overzicht' => vacom_overzicht($db, $ik),
    'is_dev'    => VACOM_IS_DEV,
    'embed'     => $embed,
    'project'   => $project,
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
<meta name="apple-mobile-web-app-title" content="Petear">
<title>Petear</title>
<link rel="icon" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="manifest" href="manifest.json">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body data-pane="lijst"<?= $embed ? ' class="embed"' : '' ?>>
<script>/* v0.5: thema direct zetten (vóór render, geen flits). Zelfde voorkeur als de Portal. */try{if(localStorage.getItem('ipp_theme')==='light')document.body.classList.add('light-mode')}catch(e){}</script>
<div class="aurora" aria-hidden="true"></div>

<div class="app">

  <!-- Header (v0.5), zoals de Portal-headerbalk: logo links, rechts wie je
       bent en de knoppen zoeken · thema · instellingen · uitloggen. -->
  <header class="app-kop glas">
    <div class="logo">
      <img class="logo-icoon" src="assets/icon-192.png" alt="">
      <span class="logo-naam">Petear</span>
    </div>
    <button type="button" class="kop-ik" id="kop-ik" title="Instellingen">
      <span class="avatar klein" id="kop-avatar"></span>
      <span class="naam" id="kop-naam"><?= h($ik['name']) ?></span>
    </button>
    <button class="knop icoon" id="knop-zoeken" title="Zoeken (Ctrl+K)" aria-label="Zoeken">
      <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <button class="knop icoon" id="knop-thema" title="Licht / donker" aria-label="Licht of donker thema">☀</button>
    <button class="knop icoon" id="knop-instellingen" title="Instellingen" aria-label="Instellingen">⚙</button>
    <button class="knop icoon" id="knop-uitloggen" title="Uitloggen" aria-label="Uitloggen">⏻</button>
  </header>

  <!-- Zijbalk: kanalen, DM's, collega's -->
  <aside class="zijbalk glas" id="zijbalk">
    <nav class="zijbalk-lijsten">
      <!-- v0.5: elke sectie is inklapbaar (details/summary); de stand wordt
           per browser onthouden. De +-knopjes in de kop toggelen niét. -->
      <details class="sectie" data-sectie="kanalen" open>
        <summary><span class="sectie-titel">Kanalen</span> <span class="badge sectie-badge" id="badge-kanalen" hidden></span> <button type="button" class="knop mini" id="knop-kanaal-nieuw" title="Nieuw kanaal">+</button></summary>
        <ul id="lijst-kanalen"></ul>
      </details>
      <details class="sectie" data-sectie="berichten" open>
        <summary><span class="sectie-titel">Berichten</span> <span class="badge sectie-badge" id="badge-berichten" hidden></span> <button type="button" class="knop mini" id="knop-dm-nieuw" title="Nieuw gesprek met een collega">+</button></summary>
        <ul id="lijst-dms"></ul>
      </details>
      <details class="sectie" data-sectie="projecten" id="sectie-projecten" open>
        <summary><span class="sectie-titel">Projecten</span> <span class="badge sectie-badge" id="badge-projecten" hidden></span> <button type="button" class="knop mini" id="knop-project-nieuw" title="Kanaal starten voor een project waar je aan gekoppeld bent">+</button></summary>
        <ul id="lijst-projecten"></ul>
      </details>
      <details class="sectie" data-sectie="collegas" open>
        <summary><span class="sectie-titel">Collega's</span></summary>
        <ul id="lijst-collegas"></ul>
      </details>
      <!-- Gesloten kanalen (v0.4.1): ingeklapt onderin, zoals de archief-lijst in Slack -->
      <section id="sectie-gesloten" hidden>
        <details class="gesloten-details">
          <summary>Gesloten kanalen <span class="stil" id="gesloten-aantal"></span></summary>
          <ul id="lijst-gesloten"></ul>
        </details>
      </section>
    </nav>
  </aside>

  <!-- Hoofdvlak: het geopende kanaal -->
  <!-- Eén kaart: kanaalkop, gesprek en schrijfveld horen bij elkaar. -->
  <main class="chat glas" id="chat">
    <header class="chat-kop">
      <button class="knop icoon alleen-mobiel" id="knop-terug" aria-label="Terug naar overzicht">‹</button>
      <div class="chat-titel">
        <h1 id="chat-naam">Welkom</h1>
        <p id="chat-topic" class="chat-topic"></p>
      </div>
      <a class="knop mini chat-portal" id="chat-portal" hidden target="_blank" rel="noopener">Open in Portal ↗</a>
      <button class="knop icoon" id="knop-kanaal-opties" hidden title="Kanaal-opties" aria-label="Kanaal-opties">⋯</button>
      <!-- v0.5.1: alleen ingesloten in de Portal — deze kop is dan dé kop van de popup -->
      <a class="knop mini primair" id="chat-petear" hidden target="_blank" rel="noopener" title="Het gesprek in de volledige Petear-app openen (nieuw venster)">Open in Petear ↗</a>
      <button class="knop icoon" id="chat-sluit" hidden title="Sluiten" aria-label="Sluiten">✕</button>
    </header>

    <!-- Alleen-lezen-balk voor een gesloten kanaal (v0.4.1) -->
    <div class="gesloten-balk" id="gesloten-balk" hidden>
      <span class="slotje" aria-hidden="true">🔒</span>
      <span id="gesloten-tekst"></span>
      <button type="button" class="knop mini" id="knop-heropenen" hidden>Heropenen</button>
    </div>

    <div class="berichten" id="berichten">
      <div class="leeg" id="leeg">
        <div class="logo groot"><img class="logo-icoon" src="assets/icon-192.png" alt=""><span class="logo-naam">Petear</span></div>
        <p>Kies links een kanaal of een collega.</p>
      </div>
    </div>

    <form class="schrijver" id="schrijver" hidden>
      <button type="button" class="knop icoon" id="knop-emoji" title="Emoji" aria-label="Emoji kiezen">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c.9 1.2 2.1 1.8 3.5 1.8s2.6-.6 3.5-1.8" stroke-linecap="round"/><circle cx="9" cy="9.6" r="1.15" fill="currentColor" stroke="none"/><circle cx="15" cy="9.6" r="1.15" fill="currentColor" stroke="none"/></svg>
      </button>
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
      <!-- autocomplete/data-1p-ignore/data-lpignore: anders denkt 1Password/LastPass dat dit een inlogformulier is -->
      <div class="hash-veld"><span>#</span><input type="text" id="kanaal-naam" required maxlength="40" autocapitalize="none" placeholder="bijv. projecten" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore></div>
    </label>
    <label>Onderwerp (optioneel)
      <input type="text" id="kanaal-topic" maxlength="200" placeholder="Waar gaat dit kanaal over?" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>
    </label>
    <p class="melding fout" id="kanaal-fout" hidden></p>
    <div class="dialoog-knoppen">
      <button type="button" class="knop" id="kanaal-annuleer">Annuleren</button>
      <button type="submit" class="knop primair">Aanmaken</button>
    </div>
  </form>
</dialog>

<!-- Instellingen (v0.5): avatarfoto, je gegevens uit de Portal, thema -->
<dialog id="dialoog-instellingen" class="dialoog klein">
  <div class="dialoog-kop">
    <h2>Instellingen</h2>
    <button type="button" class="knop mini" id="instellingen-sluit" aria-label="Sluiten">✕</button>
  </div>
  <div class="opties-body">
    <!-- Profiel: foto links, naam/rol/e-mail en de fotoknoppen rechts ernaast -->
    <div class="profiel">
      <div class="avatar instellingen-avatar" id="instellingen-avatar"></div>
      <div class="profiel-info">
        <div class="profiel-naam" id="inst-naam"></div>
        <div class="profiel-sub"><span id="inst-rol"></span><span class="profiel-punt">·</span><span id="inst-email"></span></div>
        <div class="profiel-knoppen">
          <button type="button" class="knop mini" id="avatar-kiezen">Foto kiezen…</button>
          <button type="button" class="knop mini" id="avatar-weg" hidden>Foto verwijderen</button>
          <input type="file" id="avatar-bestand" accept="image/*" hidden>
        </div>
      </div>
    </div>
    <p class="melding fout" id="instellingen-fout" hidden></p>

    <div class="inst-sectie">
      <div class="inst-kop">Status</div>
      <div class="status-rij">
        <input type="text" id="inst-status" maxlength="60" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore placeholder="Bijv. Op kantoor · Vrij tot 14:00 · In overleg">
        <button type="button" class="knop" id="inst-status-opslaan">Opslaan</button>
      </div>
      <p class="inst-hint">Collega's zien je status achter je naam in de zijbalk en bovenin jullie gesprek. Leeg laten = geen status.</p>
    </div>

    <div class="inst-sectie">
      <div class="inst-kop">Weergave</div>
      <div class="instellingen-rij"><span>Thema</span><button type="button" class="knop mini" id="inst-thema"></button></div>
    </div>

    <!-- v0.5.1: kanaalbeheer voor de superadmin — alle kanalen, ook zonder lid te zijn -->
    <div class="inst-sectie" id="inst-beheer" hidden>
      <div class="inst-kop">Kanaalbeheer <span class="stil">· superadmin</span></div>
      <p class="inst-hint" style="margin:0 0 8px">Alle kanalen, ook waar je geen lid van bent. Verwijderen is definitief: berichten en zoekresultaten gaan mee.</p>
      <ul class="leden" id="beheer-kanalen"></ul>
    </div>

    <div class="inst-sectie">
      <p class="inst-hint"><?= VACOM_PORTAL ? 'Naam, rol en e-mail komen uit de Portal; wijzigen doe je daar.' : 'Je wachtwoord wijzig je via Gebruikers.' ?></p>
      <div class="dialoog-knoppen instellingen-links">
        <a class="knop" href="gebruikers.php"><?= VACOM_PORTAL ? 'Collega\'s' : (vacom_is_superadmin() ? 'Gebruikers' : 'Mijn wachtwoord') ?></a>
        <a class="knop" href="changelog.php">Wat is er nieuw</a>
      </div>
    </div>
  </div>
</dialog>

<!-- Nieuw gesprek (v0.5): kies een collega — of jezelf, voor notities -->
<dialog id="dialoog-dm" class="dialoog klein">
  <div class="dialoog-kop">
    <h2>Nieuw gesprek</h2>
    <button type="button" class="knop mini" id="dm-sluit" aria-label="Sluiten">✕</button>
  </div>
  <div class="opties-body">
    <input type="text" id="dm-filter" placeholder="Zoek een collega…" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>
    <ul class="dm-keuze" id="dm-keuze"></ul>
  </div>
</dialog>

<!-- Projectkanaal starten (v0.5): alleen projecten waar je aan gekoppeld bent en die nog geen kanaal hebben -->
<dialog id="dialoog-project" class="dialoog klein">
  <form id="form-project">
    <h2>Projectkanaal starten</h2>
    <p class="stil" id="project-uitleg">Kies een project waar je aan gekoppeld bent. Iedereen die in de Portal aan het project hangt wordt automatisch lid.</p>
    <label>Project
      <select id="project-keuze" required></select>
    </label>
    <p class="melding fout" id="project-fout" hidden></p>
    <div class="dialoog-knoppen">
      <button type="button" class="knop" id="project-annuleer">Annuleren</button>
      <button type="submit" class="knop primair" id="project-aanmaken">Kanaal starten</button>
    </div>
  </form>
</dialog>

<!-- Kanaal-opties (v0.4): leden, collega toevoegen, verlaten, sluiten/verwijderen -->
<dialog id="dialoog-opties" class="dialoog klein">
  <div class="dialoog-kop">
    <h2 id="opties-titel">Kanaal</h2>
    <button type="button" class="knop mini" id="opties-sluit" aria-label="Sluiten">✕</button>
  </div>
  <div class="opties-body">
    <p class="stil" id="opties-project" hidden>Projectkanaal — leden volgen de koppeling in de Portal (projectmanager en designers).</p>
    <p class="stil" id="opties-dm">Een gesprek uit je lijst halen verwijdert niets: de berichten blijven bewaard en doorzoekbaar. Het gesprek komt vanzelf terug zodra er een nieuw bericht is, of als je het opnieuw opent via de + bij Berichten.</p>
    <p class="melding info" id="opties-gesloten" hidden>Dit kanaal is gesloten: iedereen kan het lezen en doorzoeken, maar er kan niets meer in geplaatst worden.</p>
    <label>Onderwerp
      <input type="text" id="opties-topic" maxlength="200" placeholder="Waar gaat dit kanaal over?" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>
    </label>
    <h3 class="opties-kop">Leden <span class="stil" id="opties-aantal"></span></h3>
    <ul class="leden" id="opties-leden"></ul>
    <div class="toevoegen">
      <select id="opties-collega"></select>
      <button type="button" class="knop" id="opties-toevoegen">Toevoegen</button>
    </div>
    <p class="melding fout" id="opties-fout" hidden></p>
    <div class="dialoog-knoppen opties-knoppen">
      <button type="button" class="knop" id="opties-verbergen">Uit mijn lijst halen</button>
      <button type="button" class="knop" id="opties-verlaten" hidden>Kanaal verlaten</button>
      <button type="button" class="knop" id="opties-archiveren" hidden>Kanaal sluiten</button>
      <button type="button" class="knop" id="opties-heropenen" hidden>Kanaal heropenen</button>
      <button type="button" class="knop gevaar" id="opties-verwijderen" hidden>Verwijderen</button>
    </div>
  </div>
</dialog>

<!-- Installatie-tip voor iPhone -->
<div class="banner" id="banner-ios" hidden>
  <p>Zet Petear op je beginscherm voor meldingen: tik op <strong>Delen</strong> <span class="ios-deel">⎋</span> en dan <strong>Zet op beginscherm</strong>.</p>
  <button class="knop mini" id="banner-ios-sluit" aria-label="Sluiten">✕</button>
</div>

<script>window.VACOM = <?= json_encode($start, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= asset('assets/emoji.js') ?>"></script>
<script src="<?= asset('assets/app.js') ?>"></script>
</body>
</html>
