<?php
/**
 * VaCom — wat is er nieuw. Nieuwste versie bovenaan.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
vacom_require_login();
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f1319">
<title>Wat is er nieuw — VaCom</title>
<link rel="icon" href="assets/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body class="pagina-body">
<div class="aurora" aria-hidden="true"></div>
<main class="pagina">
  <header class="pagina-kop">
    <a class="knop" href="index.php">‹ Terug naar VaCom</a>
    <h1>Wat is er nieuw</h1>
  </header>

  <section class="kaart glas">
    <h2>v0.4.1 <span class="stil">— 4 september 2026</span></h2>
    <ul>
      <li><strong>Gesloten kanalen werken nu zoals in Slack.</strong> Een gesloten kanaal verdween uit de zijbalk en was daarna nergens meer terug te vinden; klikken op een zoekresultaat eruit gaf zelfs "Kanaal niet gevonden". Nu staat er onderin de zijbalk een inklapbaar kopje <em>Gesloten kanalen</em>: je kunt zo'n kanaal gewoon openen en teruglezen, alleen schrijven en reageren kan niet meer (er staat een 🔒-balkje boven het gesprek). Zoekresultaten uit gesloten kanalen zijn gemarkeerd en klikbaar.</li>
      <li><strong>Heropenen.</strong> De superadmin of wie het kanaal aanmaakte kan het weer openen — via de knop in het 🔒-balkje of via de kanaal-opties (⋯). Projectkanalen volgen zoals altijd de Portal: project terug uit de prullenbak = kanaal weer open.</li>
      <li>Een gesloten kanaal kan de superadmin ook alsnog definitief verwijderen (via ⋯).</li>
    </ul>
  </section>

  <section class="kaart glas">
    <h2>v0.4.0 <span class="stil">— 3 september 2026</span></h2>
    <ul>
      <li><strong>Je ziet alleen nog de projectkanalen van je eigen projecten</strong> — waar je projectmanager bent of designer van een middel (zoals in de Portal vastgelegd). Dat geldt voor iedereen, ook voor de superadmin. Word je aan een project gekoppeld, dan verschijnt het kanaal vanzelf; ga je eraf, dan verdwijnt het weer. #algemeen en andere losse kanalen blijven voor iedereen.</li>
      <li><strong>Kanaal-opties</strong> via de ⋯-knop rechtsboven in een kanaal: leden bekijken, een collega toevoegen (mag iedereen), het onderwerp aanpassen en een kanaal verlaten. De superadmin kan losse kanalen sluiten (blijft doorzoekbaar) of definitief verwijderen.</li>
      <li>Collega's die je handmatig toevoegt blijven lid, ook als de Portal ze niet aan het project koppelt.</li>
    </ul>
  </section>

  <section class="kaart glas">
    <h2>v0.3.0 <span class="stil">— 3 september 2026</span></h2>
    <ul>
      <li><strong>Reageer met emoji.</strong> Wijs een bericht aan (of tik erop) en klik op het ☺+ — kies een emoji en hij verschijnt als knopje onder het bericht. Nog een keer klikken haalt jouw reactie weer weg; je ziet wie er gereageerd hebben als je erop aanwijst.</li>
      <li><strong>Emoji in je bericht.</strong> Nieuw smiley-knopje naast het schrijfveld met een handig palet.</li>
      <li><strong>Gifs en afbeeldingen spelen af in het gesprek.</strong> Plak een link naar een gif of afbeelding (bijv. van Tenor of Giphy: kopieer de gif-link) als los bericht, en het beeld verschijnt gewoon in het kanaal.</li>
      <li>Een bericht met alleen een paar emoji wordt lekker groot getoond. 🎉</li>
    </ul>
  </section>

  <section class="kaart glas">
    <h2>v0.2.0 <span class="stil">— 3 september 2026</span></h2>
    <ul>
      <li><strong>VaCom hoort nu bij de Portal.</strong> Je logt in met je Portal-naam en -wachtwoord; wie in de Portal al ingelogd is, is in VaCom ook ingelogd. Klanten hebben geen toegang.</li>
      <li>Collega's komen automatisch uit de Portal. Accounts en wachtwoorden beheer je daar.</li>
      <li><strong>Elk Portal-project krijgt zijn eigen kanaal</strong>, onder "Projecten" in de zijbalk, met een knop naar het project. Gaat een project in de prullenbak, dan verdwijnt het kanaal uit de lijst maar blijft het doorzoekbaar.</li>
      <li>Losse kanalen (#algemeen en wat je zelf aanmaakt) blijven gewoon bestaan.</li>
      <li>De naam: VaCom, van Vastgoed Communicatie. Eerder heette de testversie wurd.</li>
    </ul>
  </section>

  <section class="kaart glas">
    <h2>v0.1.0 <span class="stil">— 3 september 2026</span></h2>
    <ul>
      <li>Eerste testversie: inloggen, kanalen, directe berichten en collega's in de zijbalk.</li>
      <li>Berichten verschijnen binnen een paar seconden bij iedereen (de app vraagt elke 3 seconden om nieuws).</li>
      <li>Zoeken door alle berichten (vergrootglas linksboven).</li>
      <li>Ongelezen-tellers per kanaal en in de tabbladtitel; groene stip bij collega's die online zijn.</li>
      <li>Werkt als app op iPhone, Mac en PC: op de iPhone via "Zet op beginscherm".</li>
      <li>Gebruikersbeheer voor de superadmin; iedereen kan zijn eigen wachtwoord wijzigen.</li>
    </ul>
  </section>
</main>
</body>
</html>
