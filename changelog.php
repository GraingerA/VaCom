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
<link rel="stylesheet" href="<?= h(vacom_gedeelde_stijl()) ?>">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
<?= vacom_merk_head() /* v0.11: kleuren en logo van het merk (Invite / Incombinatie), ná de stylesheets */ ?>
</head>
<body class="pagina-body">
<script>try{if(localStorage.getItem('ipp_theme')==='light')document.body.classList.add('light-mode')}catch(e){}</script>
<main class="pagina">
  <header class="pagina-kop">
    <a class="knop" href="index.php"><i>←</i> Terug naar VaCom</a>
    <h1>Wat is er nieuw</h1>
  </header>

  <section class="vlak kaart">
    <h2>v0.14.0 <span class="stil">— 9 november 2026</span></h2>
    <ul>
      <li><strong>Systeemberichten.</strong> In een kanaal verschijnt nu een stille grijze regel als er iets met de leden gebeurt: "Ad heeft het kanaal aangemaakt", "Kim is toegevoegd door Ad", "Pim doet mee in het kanaal", "Ad heeft het kanaal verlaten" of "Kim is uit het kanaal verwijderd door Ad". Zo'n regel telt niet mee als ongelezen, geeft geen pushmelding en is niet doorzoekbaar.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.13.1 <span class="stil">— 9 november 2026</span></h2>
    <ul>
      <li><strong>Ook projectkanalen zijn te verwijderen.</strong> De knop Verwijderen in de kanaal-opties werkte niet voor kanalen die ooit voor een Portal-project zijn gestart; die mochten alleen via de Portal weg. Nu kan de superadmin (of wie het kanaal aanmaakte) ze gewoon verwijderen.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.13.0 <span class="stil">— 9 november 2026</span></h2>
    <ul>
      <li><strong>Kop zoals in de Portal.</strong> Geen glazen balk meer bovenin: het woordmerk staat groot links op een open regel, de knoppen rechts op de basislijn, net als de kop van de Portal-pagina's.</li>
      <li><strong>Projectkanalen staan voorlopig uit.</strong> De sectie Projecten en het +-knopje om een kanaal voor een Portal-project te starten zijn weg. Bestaande projectkanalen staan gewoon bij Kanalen en blijven doorzoekbaar. (Schakelaar <code>VACOM_PROJECTKANALEN</code>; komt mogelijk later terug.)</li>
      <li><strong>Gesprek verwijderen.</strong> In de opties van een gesprek (⋯) kun je het gesprek nu definitief verwijderen, inclusief alle berichten en bijlagen, voor jou én de ander. "Uit mijn lijst halen" blijft bestaan voor wie alleen wil opruimen. Een kanaal verwijderen kan nu ook door wie het kanaal aanmaakte, niet alleen door de superadmin.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.12.0 <span class="stil">— 9 november 2026</span></h2>
    <ul>
      <li><strong>Het VaCom-icoontje is weg.</strong> In de kop, op het inlogscherm en in het lege gesprek staat nu alleen het woordmerk "VaCom", in lijn met de stijlgids van de Portal. Op de telefoon blijft de naam zichtbaar. Het app-icoon op je beginscherm (iPhone/Android) en de favicon blijven gewoon bestaan.</li>
      <li>Het VaCom-kader in de stijlgids van de Portal is opnieuw opgebouwd met de echte bouwstenen van vandaag (Portal v5.5.4).</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.11.0 <span class="stil">— 9 november 2026</span></h2>
    <ul>
      <li><strong>VaCom voor Invite én Incombinatie.</strong> Collega's van beide bedrijven zitten in dezelfde VaCom: één #algemeen, iedereen kan elkaar een bericht sturen. Op <code>portal.incombinatie.nl/vacom/</code> heet het "het interne kanaal van Incombinatie" en krijgt VaCom hun kleuren, net als de Portal en de Hub daar; op portal.invite.nl verandert er niets.</li>
      <li>Heeft een gedeeld project in de Portal een tweede projectmanager (van het andere bedrijf), dan wordt die ook lid van het projectkanaal.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.10.0 <span class="stil">— 2 november 2026</span></h2>
    <ul>
      <li><strong>VaCom volgt nu de stijlgids van de Portal.</strong> Knoppen, invoervelden, popups, labels, tellers en de lichte en donkere kleuren komen rechtstreeks uit het gedeelde stijlbestand van de Invite Project Portal (<code>invite_stijl.css</code>), net als de Invite Hub, de Socials Planner en Transfer. Daardoor ziet alles er in de vier apps hetzelfde uit, en een verbetering in de Portal komt vanzelf ook in VaCom terecht.</li>
      <li>Wat je ziet: dezelfde gouden hoofdknop en neutrale knoppen als in de Portal (gekleurde rand bij aanwijzen), dezelfde invoervelden met gouden focusrand, popups met een vaste kop en voet, kopjes in hoofdletters en een zoekveld met vergrootglas. De indeling van VaCom zelf (kop, zijbalk en gesprek als losse glazen kaarten) is gebleven.</li>
      <li>Achter de schermen is <code>assets/app.css</code> grotendeels opnieuw geschreven: alles wat de stijlgids al levert is eruit, alleen wat echt van VaCom is (berichten, reacties, threads, polls, bijlagen, pickers) staat er nog in. VaCom is sinds vandaag ook weer een eigen repo (<code>GraingerA/VaCom</code>), naast de Portal in plaats van erin.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.9.7 <span class="stil">— 19 oktober 2026</span></h2>
    <ul>
      <li><strong>Veiliger: wijzigen kan niet meer via een gewone link.</strong> Iets veranderen of verwijderen (een bericht, je status, een kanaal) gaat nu altijd via de beveiligde route met controle-sleutel. Een kwaadwillende link op een andere website kan daardoor niet meer ongemerkt iets in VaCom aanpassen. Voor jou verandert er niets.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.9.6 <span class="stil">— 17 september 2026</span></h2>
    <ul>
      <li><strong>Proefmelding controleert eerst het achtergrondscript.</strong> Draait de browser nog een oude versie van het script dat meldingen ontvangt, dan werkt de knop het eerst bij. Zo kan "niet aangekomen" niet meer ten onrechte verschijnen. De versleuteling van de meldingen is los daarvan nagerekend met de officiële testgegevens van de standaard: die klopt.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.9.5 <span class="stil">— 17 september 2026</span></h2>
    <ul>
      <li><strong>Proefmelding vertelt wat er gebeurt.</strong> Na het versturen zie je per apparaat wat de meldingsdienst van die browser antwoordde: "✓ Firefox: afgeleverd" of "✗ Firefox: fout 403 (…)". Staat er een ✓ maar verschijnt er niets, dan ligt het aan de computer (meldingen voor de browser uit, of Focus aan). Staat er een ✗, dan weten we met de foutcode waar het zit.</li>
      <li><strong>Aangekomen of niet?</strong> Na het versturen wacht de knop een paar tellen op een seintje van je eigen browser. Komt dat seintje, dan is de melding aangekomen en aan je computer gegeven; zie je hem dan niet, dan verbergt macOS of Windows hem, en staat erbij waar je moet kijken (op de Mac onder meer de tweede regel <em>Google Chrome Helper (Alerts)</em> en de schakelaar voor meldingen tijdens spiegelen of schermdelen). Komt het seintje niet, dan bereikte de melding je browser niet, meestal door het netwerk.</li>
      <li><strong>De knop herstelt een ontbrekende aanmelding.</strong> Had de browser wel een abonnement maar kende de server het niet (meer), dan gaf de proefmelding "Geen apparaat aangemeld". De knop meldt het apparaat nu eerst opnieuw aan en stuurt daarna pas de proefmelding.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.9.4 <span class="stil">— 17 september 2026</span></h2>
    <ul>
      <li><strong>Proefmelding, goed vindbaar.</strong> Onder Instellingen → Meldingen staat nu altijd de regel "Werkt het? Test het hier" met de knop <em>Stuur proefmelding</em> (voorheen een klein knopje dat pas verscheen als meldingen al aanstonden). Staan meldingen op dat apparaat nog uit, dan zegt hij dat. Na het versturen lees je wat je moet doen als er niets verschijnt: op de Mac bij Systeeminstellingen → Meldingen je browser toestaan en Focus uitzetten, op Windows hetzelfde bij Instellingen → Systeem → Meldingen.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.9.3 <span class="stil">— 17 september 2026</span></h2>
    <ul>
      <li><strong>Meldingen kwamen niet aan als je de Portal of VaCom open had staan.</strong> VaCom sloeg iedereen over die "net actief" was, en telde daarbij elk verzoek mee — ook de stille controle van de VaCom-knop in de Portal-header, elke halve minuut. Wie de Portal de hele dag in een tabblad open had, kreeg dus nooit een melding. Nu wordt een melding alleen nog overgeslagen als je op dat moment echt naar VaCom kijkt: het venster is zichtbaar én heeft de aandacht. Staat VaCom op de achtergrond, in een ander tabblad, of heb je alleen de Portal open, dan komt de melding gewoon.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.9.2 <span class="stil">— 16 september 2026</span></h2>
    <ul>
      <li><strong>Meldingen ook aan te zetten vanuit de Portal.</strong> De Portal (v4.15.3) toont dezelfde uitnodigingsbalk als VaCom en meldt je apparaat via VaCom aan — het resultaat is identiek aan Instellingen → Meldingen hier. Technisch: nieuwe alleen-lezen actie <code>portal_push_info</code>.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.9.1 <span class="stil">— 16 september 2026</span></h2>
    <ul>
      <li><strong>Uitnodiging om meldingen aan te zetten.</strong> Op de laptop verschijnt onderin een balk "Wil je een melding als er iets voor je is?" zolang meldingen op dat apparaat nog uitstaan. Eén klik op Aanzetten regelt de toestemming en de aanmelding; "Niet nu" verbergt de balk twee weken. Aanpassen kan altijd onder Instellingen → Meldingen.</li>
      <li><strong>Duidelijke uitleg als aanzetten niet lukt.</strong> De Engelse browserfout "Registration failed – push service error" is vervangen door een Nederlandse uitleg met wat je moet doen: in Brave de meldingsdienst aanzetten (staat daar standaard uit), bij Edge de Windows-meldingen controleren, of het buiten het kantoornetwerk proberen.</li>
      <li>Voor de Portal: de VaCom-knop in de Portal-header telt nu alle nieuwe berichten (Portal v4.15.1).</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.9.0 <span class="stil">— 10 september 2026</span></h2>
    <p class="stil">Een grote: zeven dingen die je van Slack kent.</p>
    <ul>
      <li><strong>Threads.</strong> Wijs een bericht aan en klik op 💬: er opent rechts een paneel waarin je onder dát bericht reageert, zonder dat het kanaal volloopt. Onder een bericht met antwoorden staat "3 antwoorden · laatste 14:32" met de gezichten van wie meedoet; klik en je zit in de thread. Op de telefoon schuift de thread over het gesprek heen.</li>
      <li><strong>Pushmeldingen.</strong> Onder Instellingen → Meldingen zet je ze per apparaat aan. Je krijgt een melding als VaCom niet openstaat: bij gesprekken, @vermeldingen en threads waar je in zit, of (als je dat kiest) bij alle berichten in je kanalen. Klik op de melding en je staat in het kanaal. Op de iPhone werkt het alleen als VaCom op het beginscherm staat. Er is een knop Proefmelding om te testen.</li>
      <li><strong>Opslaan en vastzetten.</strong> 🔖 bewaart een bericht voor jezelf; je vindt ze terug via het boekenlegger-knopje bovenin. 📌 zet een bericht vast in het kanaal, voor iedereen: bovenin het kanaal verschijnt "📌 2 vastgezette berichten", klik en je ziet ze.</li>
      <li><strong>Links met voorvertoning.</strong> Plak een link en onder je bericht verschijnt een kaartje met de titel, een korte omschrijving en het plaatje van die pagina.</li>
      <li><strong>Opmaak in berichten.</strong> <code>**vet**</code>, <code>_cursief_</code>, <code>~~doorgehaald~~</code>, <code>`code`</code>, een regel die begint met <code>&gt;</code> wordt een citaat, met <code>-</code> een lijstje. Ctrl+B en Ctrl+I werken in het schrijfveld.</li>
      <li><strong>Typt…</strong> Onder het gesprek zie je "Emma typt…" zolang iemand aan het schrijven is.</li>
      <li><strong>Zoekfilters.</strong> In het zoekvenster staat een knop Filters: van wie, in welk kanaal, en een periode. Ook zonder zoekwoord te gebruiken, bijvoorbeeld "alles van Emma in #algemeen in juli".</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.8.0 <span class="stil">— 10 september 2026</span></h2>
    <ul>
      <li><strong>Polls.</strong> Nieuw knopje (staafdiagram) naast het schrijfveld: stel een vraag met twee tot tien antwoorden, eventueel met meerdere antwoorden tegelijk. Klik op een antwoord om te stemmen (nog eens klikken trekt je stem in); je ziet meteen de balken, de aantallen en wie wat koos (aanwijzen). Met <em>Bekijk stemmen</em> zie je per antwoord wie erop stemde, en in een kanaal ook wie nog niet gestemd heeft. Wie de poll plaatste kan hem sluiten, daarna staat alleen de uitslag. De vraag is doorzoekbaar als gewoon bericht.</li>
      <li>Gif-links (Giphy) zijn niet meer klikbaar — ze staan al op ware grootte. Geüploade foto's openen nog wel groot in de lichtbak.</li>
      <li><strong>Knopje "naar beneden".</strong> Open je een kanaal met veel nieuwe berichten, of scrol je terug in de tijd, dan zweeft er rechtsonder een pijltje: één klik en je staat weer bij het laatste bericht.</li>
      <li>In de zijbalk staan bij Collega's en Berichten nu mini-foto's (of de gekleurde initiaal) met het online-stipje in de hoek. De status staat direct achter de naam in plaats van tegen de rechterkant, zodat je meer van de tekst leest.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.7.4 <span class="stil">— 10 september 2026</span></h2>
    <ul>
      <li><strong>Profielfoto bijsnijden.</strong> Kies je een foto bij Instellingen, dan zie je hem eerst in een vierkant kader: slepen om te verschuiven, zoomen met de schuif of het scrollwiel. Wat in het kader staat wordt je foto.</li>
      <li><strong>Logo brengt je terug naar het overzicht.</strong> Vooral handig op de telefoon, waar een kanaal het hele scherm vult: tik op het VaCom-logo linksboven en je staat weer in de lijst.</li>
      <li><strong>Reageer-knopje zweeft.</strong> Het ☺+ om met een emoji te reageren stond onder het bericht en klapte bij aanwijzen open, waardoor alles eronder verschoof. Het zit nu in het zwevende knoppenbalkje rechtsboven op het bericht, naast bewerken en verwijderen, en verschuift niets meer.</li>
      <li><strong>iPhone-icoon zonder witte rand.</strong> Het icoon op het beginscherm had transparante hoeken, en iOS maakt die wit. Het is nu een gevuld vierkant; iOS rondt de hoeken zelf af. De link naar het icoon draagt nu ook een versienummer, zodat iOS niet het oude icoon blijft gebruiken. Haal de app van je beginscherm en zet hem er opnieuw op.</li>
      <li>De status van collega's staat cursief achter hun naam in de lijst, en je staat er nu zelf ook in, bovenaan met "(jij)" — zo zie je je eigen status zoals anderen hem zien. Klikken opent je notities.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.7.3 <span class="stil">— 10 september 2026</span></h2>
    <ul>
      <li><strong>Foto's groot bekijken in een popup.</strong> Klik op een foto, screenshot of gif in het gesprek en hij opent groot over de pagina heen, met de bestandsnaam en knoppen voor Downloaden en Nieuw tabblad. Sluiten met ✕, Esc of een klik ernaast. Ctrl/Cmd-klik opent nog steeds een nieuw tabblad.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.7.2 <span class="stil">— 10 september 2026</span></h2>
    <ul>
      <li><strong>🖕</strong> Op veler verzoek: de middelvinger zit nu in het emoji-paneel (bij Gebaren, zoek op "middelvinger"). Gebruik met beleid.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.7.1 <span class="stil">— 9 september 2026</span></h2>
    <ul>
      <li><strong>Een kanaal opent waar je gebleven was.</strong> Zijn er berichten die je nog niet gelezen hebt, dan springt het gesprek naar het eerste daarvan, met een blauwe streep <em>Nieuwe berichten</em> erboven — per persoon verschillend, net als in Slack. Is er niets nieuws, dan sta je onderaan bij het laatste bericht. Foto's en gifs die nog laden duwen je niet meer van je plek.</li>
      <li>Voor de Portal: de @-indicator in de Portal-header (Portal v4.13.7) leest via VaCom in welke kanalen je genoemd wordt.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.7.0 <span class="stil">— 9 september 2026</span></h2>
    <ul>
      <li><strong>@-vermeldingen die iets doen.</strong> Typ een @ in het schrijfveld en er verschijnt een lijstje collega's; typ door om te filteren, kies met de pijltjes en Enter (of klik). De naam komt als @Naam in je bericht te staan.</li>
      <li><strong>Je ziet het als je genoemd wordt.</strong> Het badge bij een kanaal wordt blauw (in plaats van goud) zodra er een ongelezen bericht bij zit waarin jij genoemd wordt, en de nieuwe @-knop in de header telt hoeveel dat er in totaal zijn. Word je ergens anders genoemd terwijl je in VaCom bezig bent, dan zegt een melding onderin waar.</li>
      <li><strong>Vermeldingen-overzicht.</strong> Klik op de @-knop voor alle berichten waarin je genoemd bent, nieuwste eerst; ongelezen exemplaren hebben een blauw bolletje. Klikken opent het gesprek.</li>
      <li>Bestaande berichten zijn eenmalig nagelopen, dus eerdere @-vermeldingen staan ook meteen in het overzicht. Jezelf noemen telt niet mee.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.6.1 <span class="stil">— 9 september 2026</span></h2>
    <ul>
      <li><strong>Lichte Portal, lichte VaCom.</strong> Collega's die de Portal in het lichte thema gebruiken maar nooit zelf aan het ☀/☽-knopje hadden gezeten, kregen VaCom (ook in de popup op de projectkaart) toch donker. VaCom volgt nu in álle gevallen dezelfde standaard als de Portal, en zet het thema al vóór het tekenen zodat de popup niet eerst even donker opflitst.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.6.0 <span class="stil">— 8 september 2026</span></h2>
    <ul>
      <li><strong>Foto's en bestanden sturen.</strong> Via de paperclip naast het schrijfveld, door een screenshot of foto in het schrijfveld te plakken, of door bestanden op het gesprek te slepen. Foto's verschijnen als beeld in het gesprek (klik = groot); andere bestanden als een kaartje met naam en grootte, klik om te openen of te downloaden. Staat er tekst in het schrijfveld, dan gaat die als bijschrift mee. Max. 25 MB per bestand. Bestanden zijn alleen te openen door leden van het kanaal en de bestandsnaam is doorzoekbaar.</li>
      <li><strong>Berichten bewerken en verwijderen.</strong> Wijs een eigen bericht aan en klik op ✎ om het te bewerken (Enter = opslaan, Esc = annuleren) of op 🗑 om het te verwijderen. Bewerkte berichten krijgen "(bewerkt)"; van een verwijderd bericht blijft alleen "bericht verwijderd" staan — tekst, reacties, bijlage en zoekresultaat zijn weg. De superadmin kan elk bericht verwijderen. Collega's zien wijzigingen binnen een paar seconden.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.5.3 <span class="stil">— 8 september 2026</span></h2>
    <ul>
      <li><strong>GIF-knop.</strong> Naast de emoji-knop staat nu GIF: typ een zoekwoord, kies een gif uit Giphy en hij gaat meteen als bericht het kanaal in. Zonder zoekwoord zie je de populaire gifs van het moment. Het zoeken loopt via onze eigen server, dus Giphy ziet niet wie er zoekt. Het paneel staat vast boven de knop en past altijd in het scherm.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.5.2 <span class="stil">— 8 september 2026</span></h2>
    <ul>
      <li><strong>Heet weer VaCom.</strong> De naam Petear was een kort experiment; overal staat weer VaCom (van Vastgoed Communicatie). Adres en werking veranderen niet.</li>
    </ul>
  </section>

  <section class="vlak kaart">
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

  <section class="vlak kaart">
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

  <section class="vlak kaart">
    <h2>v0.4.1 <span class="stil">— 4 september 2026</span></h2>
    <ul>
      <li><strong>Gesloten kanalen werken nu zoals in Slack.</strong> Een gesloten kanaal verdween uit de zijbalk en was daarna nergens meer terug te vinden; klikken op een zoekresultaat eruit gaf zelfs "Kanaal niet gevonden". Nu staat er onderin de zijbalk een inklapbaar kopje <em>Gesloten kanalen</em>: je kunt zo'n kanaal gewoon openen en teruglezen, alleen schrijven en reageren kan niet meer (er staat een 🔒-balkje boven het gesprek). Zoekresultaten uit gesloten kanalen zijn gemarkeerd en klikbaar.</li>
      <li><strong>Heropenen.</strong> De superadmin of wie het kanaal aanmaakte kan het weer openen — via de knop in het 🔒-balkje of via de kanaal-opties (⋯). Projectkanalen volgen zoals altijd de Portal: project terug uit de prullenbak = kanaal weer open.</li>
      <li>Een gesloten kanaal kan de superadmin ook alsnog definitief verwijderen (via ⋯).</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.4.0 <span class="stil">— 3 september 2026</span></h2>
    <ul>
      <li><strong>Je ziet alleen nog de projectkanalen van je eigen projecten</strong> — waar je projectmanager bent of designer van een middel (zoals in de Portal vastgelegd). Dat geldt voor iedereen, ook voor de superadmin. Word je aan een project gekoppeld, dan verschijnt het kanaal vanzelf; ga je eraf, dan verdwijnt het weer. #algemeen en andere losse kanalen blijven voor iedereen.</li>
      <li><strong>Kanaal-opties</strong> via de ⋯-knop rechtsboven in een kanaal: leden bekijken, een collega toevoegen (mag iedereen), het onderwerp aanpassen en een kanaal verlaten. De superadmin kan losse kanalen sluiten (blijft doorzoekbaar) of definitief verwijderen.</li>
      <li>Collega's die je handmatig toevoegt blijven lid, ook als de Portal ze niet aan het project koppelt.</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.3.0 <span class="stil">— 3 september 2026</span></h2>
    <ul>
      <li><strong>Reageer met emoji.</strong> Wijs een bericht aan (of tik erop) en klik op het ☺+ — kies een emoji en hij verschijnt als knopje onder het bericht. Nog een keer klikken haalt jouw reactie weer weg; je ziet wie er gereageerd hebben als je erop aanwijst.</li>
      <li><strong>Emoji in je bericht.</strong> Nieuw smiley-knopje naast het schrijfveld met een handig palet.</li>
      <li><strong>Gifs en afbeeldingen spelen af in het gesprek.</strong> Plak een link naar een gif of afbeelding (bijv. van Tenor of Giphy: kopieer de gif-link) als los bericht, en het beeld verschijnt gewoon in het kanaal.</li>
      <li>Een bericht met alleen een paar emoji wordt lekker groot getoond. 🎉</li>
    </ul>
  </section>

  <section class="vlak kaart">
    <h2>v0.2.0 <span class="stil">— 3 september 2026</span></h2>
    <ul>
      <li><strong>VaCom hoort nu bij de Portal.</strong> Je logt in met je Portal-naam en -wachtwoord; wie in de Portal al ingelogd is, is in VaCom ook ingelogd. Klanten hebben geen toegang.</li>
      <li>Collega's komen automatisch uit de Portal. Accounts en wachtwoorden beheer je daar.</li>
      <li><strong>Elk Portal-project krijgt zijn eigen kanaal</strong>, onder "Projecten" in de zijbalk, met een knop naar het project. Gaat een project in de prullenbak, dan verdwijnt het kanaal uit de lijst maar blijft het doorzoekbaar.</li>
      <li>Losse kanalen (#algemeen en wat je zelf aanmaakt) blijven gewoon bestaan.</li>
      <li>De naam: VaCom, van Vastgoed Communicatie. Eerder heette de testversie wurd.</li>
    </ul>
  </section>

  <section class="vlak kaart">
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
