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
<script>try{if(localStorage.getItem('ipp_theme')==='light')document.body.classList.add('light-mode')}catch(e){}</script>
<div class="aurora" aria-hidden="true"></div>
<main class="pagina">
  <header class="pagina-kop">
    <a class="knop" href="index.php">‹ Terug naar VaCom</a>
    <h1>Wat is er nieuw</h1>
  </header>

  <section class="kaart glas">
    <h2>v0.6.1 <span class="stil">— 9 september 2026</span></h2>
    <ul>
      <li><strong>Lichte Portal, lichte VaCom.</strong> Collega's die de Portal in het lichte thema gebruiken maar nooit zelf aan het ☀/☽-knopje hadden gezeten, kregen VaCom (ook in de popup op de projectkaart) toch donker. VaCom volgt nu in álle gevallen dezelfde standaard als de Portal, en zet het thema al vóór het tekenen zodat de popup niet eerst even donker opflitst.</li>
    </ul>
  </section>

  <section class="kaart glas">
    <h2>v0.6.0 <span class="stil">— 8 september 2026</span></h2>
    <ul>
      <li><strong>Foto's en bestanden sturen.</strong> Via de paperclip naast het schrijfveld, door een screenshot of foto in het schrijfveld te plakken, of door bestanden op het gesprek te slepen. Foto's verschijnen als beeld in het gesprek (klik = groot); andere bestanden als een kaartje met naam en grootte, klik om te openen of te downloaden. Staat er tekst in het schrijfveld, dan gaat die als bijschrift mee. Max. 25 MB per bestand. Bestanden zijn alleen te openen door leden van het kanaal en de bestandsnaam is doorzoekbaar.</li>
      <li><strong>Berichten bewerken en verwijderen.</strong> Wijs een eigen bericht aan en klik op ✎ om het te bewerken (Enter = opslaan, Esc = annuleren) of op 🗑 om het te verwijderen. Bewerkte berichten krijgen "(bewerkt)"; van een verwijderd bericht blijft alleen "bericht verwijderd" staan — tekst, reacties, bijlage en zoekresultaat zijn weg. De superadmin kan elk bericht verwijderen. Collega's zien wijzigingen binnen een paar seconden.</li>
    </ul>
  </section>

  <section class="kaart glas">
    <h2>v0.5.3 <span class="stil">— 8 september 2026</span></h2>
    <ul>
      <li><strong>GIF-knop.</strong> Naast de emoji-knop staat nu GIF: typ een zoekwoord, kies een gif uit Giphy en hij gaat meteen als bericht het kanaal in. Zonder zoekwoord zie je de populaire gifs van het moment. Het zoeken loopt via onze eigen server, dus Giphy ziet niet wie er zoekt. Het paneel staat vast boven de knop en past altijd in het scherm.</li>
    </ul>
  </section>

  <section class="kaart glas">
    <h2>v0.5.2 <span class="stil">— 8 september 2026</span></h2>
    <ul>
      <li><strong>Heet weer VaCom.</strong> De naam Petear was een kort experiment; overal staat weer VaCom (van Vastgoed Communicatie). Adres en werking veranderen niet.</li>
    </ul>
  </section>

  <section class="kaart glas">
    <h2>v0.5.1 <span class="stil">— 7 september 2026</span></h2>
    <ul>
      <li><strong>VaCom op de projectkaart in de Portal.</strong> Op elke projectkaart staat nu het VaCom-icoontje. Klik erop en je start het projectkanaal (je ziet vooraf wie erin komen) of je krijgt het gesprek meteen in een popup, midden in de Portal. Via "Open in VaCom" spring je naar de volledige app.</li>
      <li><strong>Gesprek uit je lijst halen.</strong> Een leeg of oud gesprek (DM) kun je via ⋯ uit je lijst halen, zoals in Slack. Er wordt niets gewist: het komt terug bij een nieuw bericht of als je het opnieuw opent via +.</li>
      <li><strong>Projectkanalen: leden in beeld, niet meer te verlaten.</strong> Onder de naam van een projectkanaal staan nu de namen van wie erin zit (in plaats van "Project · manager"). Een projectkanaal verlaten kan niet meer — je hoort erbij zolang je aan het project gekoppeld bent. Sta je er toch buiten (bijvoorbeeld als niet-gekoppelde collega vanuit de Portal), dan is er een knop <em>Deelnemen</em>.</li>
      <li><strong>Kanaalbeheer voor de superadmin.</strong> Onder Instellingen staat een lijst van álle kanalen, ook die waar de superadmin geen lid van is, met leden- en berichtaantallen. Verwijderen is definitief: de berichten gaan weg en zijn ook niet meer te doorzoeken. Een zo gewist projectkanaal komt niet vanzelf terug; opnieuw starten vanuit de Portal kan wel.</li>
      <li><strong>Veel meer emoji, met zoeken.</strong> Het emoji-paneel heeft nu een zoekveld (typ bijv. "taart", "duim" of "feest"), categorieën en bovenaan je recent gebruikte emoji. Enter kiest de eerste treffer.</li>
      <li>De wachtwoordmanager bood bij "Nieuw kanaal" een wachtwoord aan in het naamveld — dat doet hij niet meer.</li>
    </ul>
  </section>

  <section class="kaart glas">
    <h2>v0.5.0 <span class="stil">— 4 september 2026</span></h2>
    <ul>
      <li><strong>Projectkanalen start je nu bewust.</strong> Er komt niet meer automatisch voor elk Portal-project een kanaal. Klik op de + naast "Projecten": je kiest een project waar je aan gekoppeld bent, en het kanaal is er — met meteen iedereen erin die in de Portal aan dat project hangt. Projectkanalen die nog nooit gebruikt waren zijn opgeruimd; kanalen met berichten blijven bestaan.</li>
      <li><strong>Zijbalk-secties inklappen.</strong> Kanalen, Berichten, Projecten en Collega's klap je in en uit met een klik op de kop; VaCom onthoudt hoe je het had staan. Is een sectie ingeklapt, dan zie je in de kop hoeveel ongelezen berichten erin zitten.</li>
      <li><strong>Licht en donker.</strong> Nieuw knopje ☀/☽ in de header. De voorkeur is dezelfde als in de Portal: zet je daar licht, dan is VaCom ook licht (en andersom).</li>
      <li>Scrollbalken in de huisstijlkleur, en het echte app-icoon als logo in de zijbalk en op de inlogpagina.</li>
      <li><strong>Header met jouw naam en foto.</strong> Bovenin staat nu een headerbalk zoals in de Portal: links het logo, rechts wie je bent en de knoppen zoeken · licht/donker · instellingen · uitloggen. Onder Instellingen (of klik op je naam) upload je een profielfoto — die verschijnt bij al je berichten — en zie je je gegevens uit de Portal.</li>
      <li><strong>Eigen status.</strong> Onder Instellingen zet je een korte status ("Op kantoor", "Vrij tot 14:00", "In overleg"). Collega's zien die achter je naam in de zijbalk en bovenin jullie gesprek.</li>
      <li><strong>Nieuw gesprek starten met +.</strong> Naast "Berichten" staat nu een +-knopje: kies een collega en het gesprek staat open. Bovenaan die lijst staat <em>Jijzelf</em> — een gesprek met jezelf, handig voor notities en links die je even wilt bewaren.</li>
      <li><strong>Vormgeving zoals de Portal.</strong> De header, de zijbalk en het gesprek zijn nu losse glazen kaarten op de aurora-achtergrond — met exact de kleuren en randen van de Portal. Kanaalkop, berichten en schrijfveld zitten samen in één kaart; de kanaal-opties (⋯) staan rechtsboven. De inklap-pijltjes zijn echte driehoekjes geworden.</li>
    </ul>
  </section>

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
