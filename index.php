<?php
/**
 * VaCom — de app. Eén pagina; assets/app.js doet de rest via api.php.
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
    'giphy'     => vacom_giphy_key() !== '', // v0.5.3: GIF-knop alleen met sleutel
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
<meta name="apple-mobile-web-app-title" content="VaCom">
<title>VaCom</title>
<link rel="icon" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="<?= asset('assets/apple-touch-icon.png') ?>"><!-- met versie: iOS bewaart het oude icoon anders hardnekkig -->
<link rel="manifest" href="manifest.json">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body data-pane="lijst"<?= $embed ? ' class="embed"' : '' ?>>
<script>
// v0.6.1: thema al vóór het tekenen zetten (anti-flits), met dezelfde regel
// als in app.js: opgeslagen keuze, anders de Portal-standaard (licht) in
// Portal-modus en donker in losse modus.
try { if ((localStorage.getItem('ipp_theme') || '<?= VACOM_PORTAL ? 'light' : 'dark' ?>') === 'light') document.body.classList.add('light-mode'); } catch (e) {}
</script>
<script>/* v0.5: thema direct zetten (vóór render, geen flits). Zelfde voorkeur als de Portal. */try{if(localStorage.getItem('ipp_theme')==='light')document.body.classList.add('light-mode')}catch(e){}</script>
<div class="aurora" aria-hidden="true"></div>

<div class="app">

  <!-- Header (v0.5), zoals de Portal-headerbalk: logo links, rechts wie je
       bent en de knoppen zoeken · thema · instellingen · uitloggen. -->
  <header class="app-kop glas">
    <!-- v0.7.4: het logo brengt je terug naar het overzicht (op de telefoon: uit het kanaal, naar de lijst) -->
    <a class="logo" href="index.php" title="Naar het overzicht">
      <img class="logo-icoon" src="assets/icon-192.png" alt="">
      <span class="logo-naam">VaCom</span>
    </a>
    <button type="button" class="kop-ik" id="kop-ik" title="Instellingen">
      <span class="avatar klein" id="kop-avatar"></span>
      <span class="naam" id="kop-naam"><?= h($ik['name']) ?></span>
    </button>
    <button class="knop icoon" id="knop-zoeken" title="Zoeken (Ctrl+K)" aria-label="Zoeken">
      <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <!-- v0.7: berichten waarin je met @naam genoemd bent; de teller = nog ongelezen -->
    <button class="knop icoon" id="knop-vermeldingen" title="Vermeldingen — berichten waarin je genoemd wordt" aria-label="Vermeldingen">@<span class="kop-teller" id="vermelding-teller" hidden></span></button>
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
      <a class="knop mini primair" id="chat-open-vacom" hidden target="_blank" rel="noopener" title="Het gesprek in de volledige VaCom-app openen (nieuw venster)">Open in VaCom ↗</a>
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
        <div class="logo groot"><img class="logo-icoon" src="assets/icon-192.png" alt=""><span class="logo-naam">VaCom</span></div>
        <p>Kies links een kanaal of een collega.</p>
      </div>
    </div>

    <!-- v0.8: zwevend "naar beneden"-knopje, zichtbaar zolang je niet onderaan staat -->
    <button type="button" class="knop naar-onder" id="knop-onder" hidden title="Naar het laatste bericht" aria-label="Naar het laatste bericht">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
    </button>
    <form class="schrijver" id="schrijver" hidden>
      <button type="button" class="knop icoon" id="knop-emoji" title="Emoji" aria-label="Emoji kiezen">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c.9 1.2 2.1 1.8 3.5 1.8s2.6-.6 3.5-1.8" stroke-linecap="round"/><circle cx="9" cy="9.6" r="1.15" fill="currentColor" stroke="none"/><circle cx="15" cy="9.6" r="1.15" fill="currentColor" stroke="none"/></svg>
      </button>
      <!-- v0.5.3: GIF zoeken via Giphy; de knop verschijnt alleen als er een sleutel is (zie vacom_giphy_key) -->
      <button type="button" class="knop icoon" id="knop-gif" title="GIF zoeken" aria-label="GIF zoeken" hidden><span class="gif-label">GIF</span></button>
      <!-- v0.6: foto of bestand meesturen (ook plakken in het veld of slepen op het gesprek) -->
      <!-- v0.8: poll plaatsen -->
      <button type="button" class="knop icoon" id="knop-poll" title="Poll plaatsen" aria-label="Poll plaatsen">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 20V10M12 20V4M19 20v-7"/></svg>
      </button>
      <button type="button" class="knop icoon" id="knop-bestand" title="Foto of bestand sturen" aria-label="Foto of bestand sturen">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.5 12.6 21a5.5 5.5 0 0 1-7.8-7.8l9-9a3.5 3.5 0 0 1 5 5l-9 9a1.5 1.5 0 0 1-2.1-2.1l8.3-8.3"/></svg>
      </button>
      <input type="file" id="bestand-invoer" multiple hidden>
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

<!-- Vermeldingen (v0.7): berichten waarin je met @naam genoemd bent -->
<dialog id="dialoog-vermeldingen" class="dialoog">
  <form method="dialog" class="dialoog-kop">
    <h2 class="dialoog-titel">Vermeldingen</h2>
    <button class="knop mini" value="sluit" aria-label="Sluiten">✕</button>
  </form>
  <div id="vermeldingen-lijst" class="zoek-resultaten"></div>
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

<!-- Poll plaatsen (v0.8) -->
<dialog id="dialoog-poll" class="dialoog klein">
  <form id="form-poll" autocomplete="off">
    <h2>Poll plaatsen</h2>
    <label>Vraag
      <input type="text" id="poll-vraag" required maxlength="300" placeholder="Bijv. Wanneer plannen we de teamdag?" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>
    </label>
    <label>Antwoorden</label>
    <div class="poll-opties" id="poll-opties"></div>
    <button type="button" class="knop mini" id="poll-optie-plus">+ Antwoord</button>
    <label class="poll-multi"><input type="checkbox" id="poll-multi"> Meerdere antwoorden mogelijk</label>
    <p class="melding fout" id="poll-fout" hidden></p>
    <div class="dialoog-knoppen">
      <button type="button" class="knop" id="poll-annuleer">Annuleren</button>
      <button type="submit" class="knop primair" id="poll-plaatsen">Poll plaatsen</button>
    </div>
  </form>
</dialog>

<!-- Poll-uitslag (v0.8): wie stemde wat -->
<dialog id="dialoog-poll-uitslag" class="dialoog klein">
  <div class="dialoog-kop">
    <h2>Stemmen</h2>
    <button type="button" class="knop mini" id="poll-uitslag-sluit" aria-label="Sluiten">✕</button>
  </div>
  <div class="opties-body">
    <p class="poll-uitslag-vraag" id="poll-uitslag-vraag"></p>
    <p class="poll-uitslag-info stil" id="poll-uitslag-info"></p>
    <div id="poll-uitslag-lijst"></div>
    <div class="uitslag-optie uitslag-rest" id="poll-uitslag-rest" hidden></div>
  </div>
</dialog>

<!-- Foto bijsnijden (v0.7.4): slepen om te verschuiven, schuif of scrollwiel om te zoomen -->
<dialog id="dialoog-avatar" class="dialoog klein">
  <div class="dialoog-kop">
    <h2>Foto bijsnijden</h2>
    <button type="button" class="knop mini" id="avatar-crop-sluit" aria-label="Sluiten">✕</button>
  </div>
  <div class="opties-body">
    <div class="crop-kader" id="crop-kader"><img id="crop-beeld" alt="" draggable="false"></div>
    <div class="crop-zoom"><span aria-hidden="true">−</span><input type="range" id="crop-zoom" min="1" max="4" step="0.01" value="1" aria-label="Zoom"><span aria-hidden="true">+</span></div>
    <p class="inst-hint">Sleep de foto om hem te verschuiven; zoom met de schuif of het scrollwiel. Het vierkant wordt je profielfoto.</p>
    <div class="dialoog-knoppen">
      <button type="button" class="knop" id="avatar-crop-annuleer">Annuleren</button>
      <button type="button" class="knop primair" id="avatar-crop-ok">Gebruiken</button>
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
  <p>Zet VaCom op je beginscherm voor meldingen: tik op <strong>Delen</strong> <span class="ios-deel">⎋</span> en dan <strong>Zet op beginscherm</strong>.</p>
  <button class="knop mini" id="banner-ios-sluit" aria-label="Sluiten">✕</button>
</div>

<script>window.VACOM = <?= json_encode($start, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= asset('assets/emoji.js') ?>"></script>
<script src="<?= asset('assets/app.js') ?>"></script>
</body>
</html>
