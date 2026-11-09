<?php
/**
 * VaCom — de app. Eén pagina; assets/app.js doet de rest via api.php.
 * Het eerste overzicht (kanalen, collega's) wordt hier al meegegeven, zodat de
 * app direct staat zonder extra rondje naar de server.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/push.php'; // v0.9

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
    'vapid'     => vacom_vapid_public(),      // v0.9: publieke sleutel voor pushmeldingen ('' = server kan het niet)
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
<link rel="stylesheet" href="<?= h(vacom_gedeelde_stijl()) ?>">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
<?= vacom_merk_head() /* v0.11: kleuren en logo van het merk (Invite / Incombinatie), ná de stylesheets */ ?>
</head>
<body data-pane="lijst"<?= $embed ? ' class="embed"' : '' ?>>
<script>
// v0.6.1: thema al vóór het tekenen zetten (anti-flits), met dezelfde regel
// als in app.js: opgeslagen keuze, anders de Portal-standaard (licht) in
// Portal-modus en donker in losse modus.
try { if ((localStorage.getItem('ipp_theme') || '<?= VACOM_PORTAL ? 'light' : 'dark' ?>') === 'light') document.body.classList.add('light-mode'); } catch (e) {}
</script>
<script>/* v0.5: thema direct zetten (vóór render, geen flits). Zelfde voorkeur als de Portal. */try{if(localStorage.getItem('ipp_theme')==='light')document.body.classList.add('light-mode')}catch(e){}</script>

<div class="app">

  <!-- Header (v0.5), zoals de Portal-headerbalk: logo links, rechts wie je
       bent en de knoppen zoeken · thema · instellingen · uitloggen. -->
  <header class="app-kop vlak">
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
    <!-- v0.9: opgeslagen berichten (privé, "later") -->
    <button class="knop icoon" id="knop-opgeslagen" title="Opgeslagen berichten" aria-label="Opgeslagen berichten">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M6 3h12v18l-6-4-6 4z"/></svg>
    </button>
    <button class="knop icoon" id="knop-thema" title="Licht / donker" aria-label="Licht of donker thema">☀</button>
    <button class="knop icoon" id="knop-instellingen" title="Instellingen" aria-label="Instellingen">⚙</button>
    <button class="knop icoon" id="knop-uitloggen" title="Uitloggen" aria-label="Uitloggen">⏻</button>
  </header>

  <!-- Zijbalk: kanalen, DM's, collega's -->
  <aside class="zijbalk vlak" id="zijbalk">
    <nav class="zijbalk-lijsten">
      <!-- v0.5: elke sectie is inklapbaar (details/summary); de stand wordt
           per browser onthouden. De +-knopjes in de kop toggelen niét. -->
      <details class="sectie" data-sectie="kanalen" open>
        <summary><span class="sectie-titel">Kanalen</span> <span class="teller sectie-badge" id="badge-kanalen" hidden></span> <button type="button" class="knop icoon klein" id="knop-kanaal-nieuw" title="Nieuw kanaal">+</button></summary>
        <ul id="lijst-kanalen"></ul>
      </details>
      <details class="sectie" data-sectie="berichten" open>
        <summary><span class="sectie-titel">Berichten</span> <span class="teller sectie-badge" id="badge-berichten" hidden></span> <button type="button" class="knop icoon klein" id="knop-dm-nieuw" title="Nieuw gesprek met een collega">+</button></summary>
        <ul id="lijst-dms"></ul>
      </details>
      <details class="sectie" data-sectie="projecten" id="sectie-projecten" open>
        <summary><span class="sectie-titel">Projecten</span> <span class="teller sectie-badge" id="badge-projecten" hidden></span> <button type="button" class="knop icoon klein" id="knop-project-nieuw" title="Kanaal starten voor een project waar je aan gekoppeld bent">+</button></summary>
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
  <main class="chat vlak" id="chat">
    <header class="chat-kop">
      <button class="knop icoon alleen-mobiel" id="knop-terug" aria-label="Terug naar overzicht">‹</button>
      <div class="chat-titel">
        <h1 id="chat-naam">Welkom</h1>
        <p id="chat-topic" class="chat-topic"></p>
      </div>
      <a class="knop klein chat-portal" id="chat-portal" hidden target="_blank" rel="noopener">Open in Portal ↗</a>
      <button class="knop icoon" id="knop-kanaal-opties" hidden title="Kanaal-opties" aria-label="Kanaal-opties">⋯</button>
      <!-- v0.5.1: alleen ingesloten in de Portal — deze kop is dan dé kop van de popup -->
      <a class="knop klein goud" id="chat-open-vacom" hidden target="_blank" rel="noopener" title="Het gesprek in de volledige VaCom-app openen (nieuw venster)">Open in VaCom ↗</a>
      <button class="knop icoon" id="chat-sluit" hidden title="Sluiten" aria-label="Sluiten">✕</button>
    </header>

    <!-- v0.9: vastgezette berichten in dit kanaal -->
    <button type="button" class="pins-balk" id="pins-balk" hidden>📌 <span id="pins-tekst"></span></button>

    <!-- Alleen-lezen-balk voor een gesloten kanaal (v0.4.1) -->
    <div class="gesloten-balk" id="gesloten-balk" hidden>
      <span class="slotje" aria-hidden="true">🔒</span>
      <span id="gesloten-tekst"></span>
      <button type="button" class="knop klein" id="knop-heropenen" hidden>Heropenen</button>
    </div>

    <div class="berichten" id="berichten">
      <div class="leeg groot" id="leeg">
        <div class="logo groot"><img class="logo-icoon" src="assets/icon-192.png" alt=""><span class="logo-naam">VaCom</span></div>
        <p>Kies links een kanaal of een collega.</p>
      </div>
    </div>

    <!-- v0.8: zwevend "naar beneden"-knopje, zichtbaar zolang je niet onderaan staat -->
    <button type="button" class="knop naar-onder" id="knop-onder" hidden title="Naar het laatste bericht" aria-label="Naar het laatste bericht">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
    </button>
    <!-- v0.9: wie typt er nu -->
    <div class="typt" id="typt" hidden></div>
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
      <textarea id="invoer" rows="1" placeholder="Schrijf een bericht…" autocomplete="off" enterkeyhint="send" title="Opmaak: **vet** _cursief_ ~~doorgehaald~~ `code` · regel met > = citaat, met - = lijst · Ctrl+B / Ctrl+I"></textarea>
      <button type="submit" class="knop icoon goud" id="knop-stuur" aria-label="Versturen">
        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M3 11.5 21 3l-8.5 18-2.5-7.5z" fill="currentColor"/></svg>
      </button>
    </form>

    <!-- v0.9: thread-paneel — rechts naast het gesprek (op de telefoon over het gesprek heen) -->
    <aside class="thread" id="thread" hidden>
      <div class="thread-kop">
        <div class="thread-titel"><strong>Thread</strong><span class="stil" id="thread-waar"></span></div>
        <button type="button" class="knop icoon" id="thread-sluit" title="Sluiten" aria-label="Thread sluiten">✕</button>
      </div>
      <div class="thread-berichten" id="thread-berichten"></div>
      <form class="schrijver thread-schrijver" id="thread-schrijver">
        <textarea id="thread-invoer" rows="1" placeholder="Antwoord in thread…" autocomplete="off" enterkeyhint="send"></textarea>
        <button type="submit" class="knop icoon goud" id="thread-stuur" aria-label="Antwoord versturen">
          <svg viewBox="0 0 24 24" width="20" height="20"><path d="M3 11.5 21 3l-8.5 18-2.5-7.5z" fill="currentColor"/></svg>
        </button>
      </form>
    </aside>
  </main>
</div>

<!-- Zoeken (v0.9: met filters op persoon, kanaal en periode) -->
<dialog id="dialoog-zoeken" class="popup">
  <form method="dialog" class="popup-kop">
    <span class="zoekveld"><svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="6.5" cy="6.5" r="4.5"/><path d="M10 10l4 4"/></svg><input type="search" id="zoek-invoer" class="veld" placeholder="Zoek in alle berichten…" autocomplete="off" aria-label="Zoeken"></span>
    <button type="button" class="knop klein" id="zoek-filters-knop" title="Filters" aria-label="Filters">Filters</button>
    <button class="knop icoon klein sluit" value="sluit" aria-label="Sluiten">&times;</button>
  </form>
  <div class="zoek-filters" id="zoek-filters" hidden>
    <select id="zoek-van" class="veld klein" aria-label="Van wie"><option value="">Van iedereen</option></select>
    <select id="zoek-in" class="veld klein" aria-label="In welk kanaal"><option value="">In alle kanalen</option></select>
    <label>van <input type="date" id="zoek-vanaf" class="veld klein"></label>
    <label>tot <input type="date" id="zoek-tot" class="veld klein"></label>
    <button type="button" class="knop klein" id="zoek-filters-wis">Wis filters</button>
  </div>
  <div id="zoek-resultaten" class="zoek-resultaten"></div>
</dialog>

<!-- Opgeslagen berichten (v0.9) -->
<dialog id="dialoog-opgeslagen" class="popup">
  <form method="dialog" class="popup-kop">
    <h3>Opgeslagen berichten</h3>
    <button class="knop icoon klein sluit" value="sluit" aria-label="Sluiten">&times;</button>
  </form>
  <div id="opgeslagen-lijst" class="zoek-resultaten"></div>
</dialog>

<!-- Vastgezette berichten in een kanaal (v0.9) -->
<dialog id="dialoog-pins" class="popup">
  <form method="dialog" class="popup-kop">
    <h3>📌 Vastgezet</h3>
    <button class="knop icoon klein sluit" value="sluit" aria-label="Sluiten">&times;</button>
  </form>
  <div id="pins-lijst" class="zoek-resultaten"></div>
</dialog>

<!-- Vermeldingen (v0.7): berichten waarin je met @naam genoemd bent -->
<dialog id="dialoog-vermeldingen" class="popup">
  <form method="dialog" class="popup-kop">
    <h3>Vermeldingen</h3>
    <button class="knop icoon klein sluit" value="sluit" aria-label="Sluiten">&times;</button>
  </form>
  <div id="vermeldingen-lijst" class="zoek-resultaten"></div>
</dialog>

<!-- Nieuw kanaal -->
<dialog id="dialoog-kanaal" class="popup klein">
  <div class="popup-kop"><h3>Nieuw kanaal</h3></div>
  <form id="form-kanaal">
    <div class="popup-inhoud">
      <div class="veldrij">
        <label class="veldlabel" for="kanaal-naam">Naam</label>
        <!-- autocomplete/data-1p-ignore/data-lpignore: anders denkt 1Password/LastPass dat dit een inlogformulier is -->
        <div class="hash-veld"><span>#</span><input type="text" id="kanaal-naam" class="veld" required maxlength="40" autocapitalize="none" placeholder="bijv. projecten" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore></div>
      </div>
      <div class="veldrij">
        <label class="veldlabel" for="kanaal-topic">Onderwerp <span>(optioneel)</span></label>
        <input type="text" id="kanaal-topic" class="veld" maxlength="200" placeholder="Waar gaat dit kanaal over?" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>
      </div>
      <p class="meldregel fout" id="kanaal-fout" hidden></p>
    </div>
    <div class="popup-voet">
      <button type="button" class="knop" id="kanaal-annuleer">Annuleren</button>
      <button type="submit" class="knop goud">Aanmaken</button>
    </div>
  </form>
</dialog>

<!-- Instellingen (v0.5): avatarfoto, je gegevens uit de Portal, thema -->
<dialog id="dialoog-instellingen" class="popup klein">
  <div class="popup-kop">
    <h3>Instellingen</h3>
    <button type="button" class="knop icoon klein sluit" id="instellingen-sluit" aria-label="Sluiten">&times;</button>
  </div>
  <div class="popup-inhoud">
    <!-- Profiel: foto links, naam/rol/e-mail en de fotoknoppen rechts ernaast (.inst-profiel: .profiel is de voet-pil van de stijlgids) -->
    <div class="inst-profiel">
      <div class="avatar instellingen-avatar" id="instellingen-avatar"></div>
      <div class="inst-profiel-info">
        <div class="inst-profiel-naam" id="inst-naam"></div>
        <div class="inst-profiel-sub"><span id="inst-rol"></span><span class="inst-profiel-punt">·</span><span id="inst-email"></span></div>
        <div class="inst-profiel-knoppen">
          <button type="button" class="knop klein" id="avatar-kiezen">Foto kiezen…</button>
          <button type="button" class="knop klein" id="avatar-weg" hidden>Foto verwijderen</button>
          <input type="file" id="avatar-bestand" accept="image/*" hidden>
        </div>
      </div>
    </div>
    <p class="meldregel fout" id="instellingen-fout" hidden></p>

    <div class="inst-sectie">
      <div class="kopje">Status</div>
      <div class="status-rij">
        <input type="text" id="inst-status" class="veld" maxlength="60" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore placeholder="Bijv. Op kantoor · Vrij tot 14:00 · In overleg">
        <button type="button" class="knop" id="inst-status-opslaan">Opslaan</button>
      </div>
      <p class="inst-hint">Collega's zien je status achter je naam in de zijbalk en bovenin jullie gesprek. Leeg laten = geen status.</p>
    </div>

    <div class="inst-sectie">
      <div class="kopje">Weergave</div>
      <div class="instellingen-rij"><span>Thema</span><button type="button" class="knop klein" id="inst-thema"></button></div>
    </div>

    <!-- v0.9: pushmeldingen, per apparaat/browser -->
    <div class="inst-sectie" id="inst-push">
      <div class="kopje">Meldingen</div>
      <div class="instellingen-rij"><span id="push-status">Op dit apparaat: uit</span><button type="button" class="knop klein" id="push-knop">Aanzetten</button></div>
      <div class="push-keuze" id="push-keuze" hidden>
        <label><input type="radio" name="push-wat" value="0"> Alleen gesprekken, @vermeldingen en threads waar ik in zit</label>
        <label><input type="radio" name="push-wat" value="1"> Alle berichten in mijn kanalen</label>
      </div>
      <!-- v0.9.4: altijd zichtbare testknop, met uitleg als er niets binnenkomt -->
      <div class="instellingen-rij" id="push-test-rij"><span>Werkt het? Test het hier</span><button type="button" class="knop klein" id="push-test">Stuur proefmelding</button></div>
      <p class="inst-hint push-test-uitleg" id="push-test-uitleg" hidden></p>
      <p class="inst-hint" id="push-hint">Je krijgt een melding op dit apparaat als VaCom niet openstaat. Op de iPhone werkt dit alleen als VaCom op het beginscherm staat.</p>
    </div>

    <!-- v0.5.1: kanaalbeheer voor de superadmin — alle kanalen, ook zonder lid te zijn -->
    <div class="inst-sectie" id="inst-beheer" hidden>
      <div class="kopje">Kanaalbeheer <span>superadmin</span></div>
      <p class="inst-hint" style="margin:0 0 8px">Alle kanalen, ook waar je geen lid van bent. Verwijderen is definitief: berichten en zoekresultaten gaan mee.</p>
      <ul class="leden" id="beheer-kanalen"></ul>
    </div>

    <div class="inst-sectie">
      <p class="inst-hint"><?= VACOM_PORTAL ? 'Naam, rol en e-mail komen uit de Portal; wijzigen doe je daar.' : 'Je wachtwoord wijzig je via Gebruikers.' ?></p>
      <div class="instellingen-links">
        <a class="knop klein" href="gebruikers.php"><?= VACOM_PORTAL ? 'Collega\'s' : (vacom_is_superadmin() ? 'Gebruikers' : 'Mijn wachtwoord') ?></a>
        <a class="knop klein" href="changelog.php">Wat is er nieuw</a>
      </div>
    </div>
  </div>
</dialog>

<!-- Poll plaatsen (v0.8) -->
<dialog id="dialoog-poll" class="popup klein">
  <div class="popup-kop"><h3>Poll plaatsen</h3></div>
  <form id="form-poll" autocomplete="off">
    <div class="popup-inhoud">
      <div class="veldrij">
        <label class="veldlabel" for="poll-vraag">Vraag</label>
        <input type="text" id="poll-vraag" class="veld" required maxlength="300" placeholder="Bijv. Wanneer plannen we de teamdag?" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>
      </div>
      <div class="veldlabel">Antwoorden</div>
      <div class="poll-opties" id="poll-opties"></div>
      <button type="button" class="knop klein" id="poll-optie-plus">+ Antwoord</button>
      <label class="vink poll-multi"><input type="checkbox" id="poll-multi"><span>Meerdere antwoorden mogelijk</span></label>
      <p class="meldregel fout" id="poll-fout" hidden></p>
    </div>
    <div class="popup-voet">
      <button type="button" class="knop" id="poll-annuleer">Annuleren</button>
      <button type="submit" class="knop goud" id="poll-plaatsen">Poll plaatsen</button>
    </div>
  </form>
</dialog>

<!-- Poll-uitslag (v0.8): wie stemde wat -->
<dialog id="dialoog-poll-uitslag" class="popup klein">
  <div class="popup-kop">
    <h3>Stemmen</h3>
    <button type="button" class="knop icoon klein sluit" id="poll-uitslag-sluit" aria-label="Sluiten">&times;</button>
  </div>
  <div class="popup-inhoud">
    <p class="poll-uitslag-vraag" id="poll-uitslag-vraag"></p>
    <p class="poll-uitslag-info stil" id="poll-uitslag-info"></p>
    <div id="poll-uitslag-lijst"></div>
    <div class="uitslag-optie uitslag-rest" id="poll-uitslag-rest" hidden></div>
  </div>
</dialog>

<!-- Foto bijsnijden (v0.7.4): slepen om te verschuiven, schuif of scrollwiel om te zoomen -->
<dialog id="dialoog-avatar" class="popup klein">
  <div class="popup-kop">
    <h3>Foto bijsnijden</h3>
    <button type="button" class="knop icoon klein sluit" id="avatar-crop-sluit" aria-label="Sluiten">&times;</button>
  </div>
  <div class="popup-inhoud">
    <div class="crop-kader" id="crop-kader"><img id="crop-beeld" alt="" draggable="false"></div>
    <div class="crop-zoom"><span aria-hidden="true">−</span><input type="range" id="crop-zoom" min="1" max="4" step="0.01" value="1" aria-label="Zoom"><span aria-hidden="true">+</span></div>
    <p class="inst-hint">Sleep de foto om hem te verschuiven; zoom met de schuif of het scrollwiel. Het vierkant wordt je profielfoto.</p>
  </div>
  <div class="popup-voet">
    <button type="button" class="knop" id="avatar-crop-annuleer">Annuleren</button>
    <button type="button" class="knop goud" id="avatar-crop-ok">Gebruiken</button>
  </div>
</dialog>

<!-- Nieuw gesprek (v0.5): kies een collega — of jezelf, voor notities -->
<dialog id="dialoog-dm" class="popup klein">
  <div class="popup-kop">
    <h3>Nieuw gesprek</h3>
    <button type="button" class="knop icoon klein sluit" id="dm-sluit" aria-label="Sluiten">&times;</button>
  </div>
  <div class="popup-inhoud">
    <span class="zoekveld" style="display:block"><svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="6.5" cy="6.5" r="4.5"/><path d="M10 10l4 4"/></svg><input type="search" id="dm-filter" class="veld mb-0" placeholder="Zoek een collega…" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore aria-label="Zoek een collega"></span>
    <ul class="dm-keuze" id="dm-keuze"></ul>
  </div>
</dialog>

<!-- Projectkanaal starten (v0.5): alleen projecten waar je aan gekoppeld bent en die nog geen kanaal hebben -->
<dialog id="dialoog-project" class="popup klein">
  <div class="popup-kop"><h3>Projectkanaal starten</h3></div>
  <form id="form-project">
    <div class="popup-inhoud">
      <p class="stil" id="project-uitleg">Kies een project waar je aan gekoppeld bent. Iedereen die in de Portal aan het project hangt wordt automatisch lid.</p>
      <div class="veldrij">
        <label class="veldlabel" for="project-keuze">Project</label>
        <select id="project-keuze" class="veld" required></select>
      </div>
      <p class="meldregel fout" id="project-fout" hidden></p>
    </div>
    <div class="popup-voet">
      <button type="button" class="knop" id="project-annuleer">Annuleren</button>
      <button type="submit" class="knop goud" id="project-aanmaken">Kanaal starten</button>
    </div>
  </form>
</dialog>

<!-- Kanaal-opties (v0.4): leden, collega toevoegen, verlaten, sluiten/verwijderen -->
<dialog id="dialoog-opties" class="popup klein">
  <div class="popup-kop">
    <h3 id="opties-titel">Kanaal</h3>
    <button type="button" class="knop icoon klein sluit" id="opties-sluit" aria-label="Sluiten">&times;</button>
  </div>
  <div class="popup-inhoud">
    <p class="stil" id="opties-project" hidden>Projectkanaal — leden volgen de koppeling in de Portal (projectmanager en designers).</p>
    <p class="stil" id="opties-dm">Een gesprek uit je lijst halen verwijdert niets: de berichten blijven bewaard en doorzoekbaar. Het gesprek komt vanzelf terug zodra er een nieuw bericht is, of als je het opnieuw opent via de + bij Berichten.</p>
    <p class="meldregel info" id="opties-gesloten" hidden>Dit kanaal is gesloten: iedereen kan het lezen en doorzoeken, maar er kan niets meer in geplaatst worden.</p>
    <div class="veldrij">
      <label class="veldlabel" for="opties-topic">Onderwerp</label>
      <input type="text" id="opties-topic" class="veld" maxlength="200" placeholder="Waar gaat dit kanaal over?" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore>
    </div>
    <h3 class="kopje">Leden <span id="opties-aantal"></span></h3>
    <ul class="leden" id="opties-leden"></ul>
    <div class="toevoegen">
      <select id="opties-collega" class="veld"></select>
      <button type="button" class="knop" id="opties-toevoegen">Toevoegen</button>
    </div>
    <p class="meldregel fout" id="opties-fout" hidden></p>
  </div>
  <div class="popup-voet links">
    <button type="button" class="knop" id="opties-verbergen">Uit mijn lijst halen</button>
    <button type="button" class="knop" id="opties-verlaten" hidden>Kanaal verlaten</button>
    <button type="button" class="knop" id="opties-archiveren" hidden>Kanaal sluiten</button>
    <button type="button" class="knop" id="opties-heropenen" hidden>Kanaal heropenen</button>
    <button type="button" class="knop rood" id="opties-verwijderen" hidden>Verwijderen</button>
  </div>
</dialog>

<!-- v0.9.1: uitnodiging om meldingen aan te zetten (laptop/desktop), tot je kiest -->
<div class="banner" id="banner-push" hidden>
  <p><strong>Wil je een melding als er iets voor je is?</strong> Bij gesprekken, @vermeldingen en threads waar je in zit &mdash; ook als VaCom niet openstaat. Aanpassen kan altijd onder Instellingen.</p>
  <button class="knop klein goud" id="banner-push-aan">Aanzetten</button>
  <button class="knop klein" id="banner-push-later">Niet nu</button>
</div>

<!-- Installatie-tip voor iPhone -->
<div class="banner" id="banner-ios" hidden>
  <p>Zet VaCom op je beginscherm voor meldingen: tik op <strong>Delen</strong> <span class="ios-deel">⎋</span> en dan <strong>Zet op beginscherm</strong>.</p>
  <button class="knop icoon klein sluit" id="banner-ios-sluit" aria-label="Sluiten">&times;</button>
</div>

<script>window.VACOM = <?= json_encode($start, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= asset('assets/emoji.js') ?>"></script>
<script src="<?= asset('assets/app.js') ?>"></script>
</body>
</html>
