# VaCom — projectcontext voor Claude

VaCom (van Vastgoed Communicatie) is de interne Slack-variant van Invite:
kanalen, directe berichten, zoeken door alles. Doorzoekbaarheid is de kernbelofte.
Werktitel tot 3 sept 2026 was "wurd"; identifiers zijn toen hernoemd naar `vacom`.
Op 7 sept 2026 heette de app één dag "Petear"; sinds 8 sept (v0.5.2) weer VaCom.

VaCom is een **eigen repo** (GitHub `GraingerA/VaCom`), lokaal in een eigen map
naast de Portal: `Documents\Claude\VaCom\`. Online draait het als submap van de
Portal: `portal.invite.nl/vacom/` (Portal-modus), geüpload naar `public_html/vacom/`.
Van 3 sept tot 2 nov 2026 zat VaCom als map `vacom/` ín de Portal-repo
(`GraingerA/Invite-Project-Portal`); die geschiedenis is met `git subtree split`
meegenomen naar deze repo en de map is daar daarna verwijderd. De Portal-repo
heeft `vacom/` sindsdien in zijn `.gitignore`.

## Gebruiker

Ad (ad@invite.nl) is geen programmeur en communiceert in het Nederlands. Leg
techniek eenvoudig uit en maak zelf de verstandige technische keuzes. De
interface is volledig Nederlands; ±25 collega's op Mac, PC en vooral iPhone.

## Harde randvoorwaarden

- Draait op **gedeelde PHP-webhosting** (LiteSpeed, PHP 8.1) — géén Node, VPS,
  composer of build-stap. Puur PHP + vanilla JS/CSS. Syntax PHP 8.1-compatibel houden.
- **Geen WebSockets**: live-gevoel via polling (`api.php?actie=poll`, elke 3 s,
  goedkoop als er niets nieuws is). Geen long-polling: gedeelde hosting beperkt
  het aantal gelijktijdige PHP-processen.
- **Opslag: SQLite** (`vacom.sqlite`, WAL, FTS5 voor zoeken) in een datamap
  **buiten de webroot** (`../vacom_data/` naast `public_html`, dus naast `ipp_data/`;
  lokaal `./data/`). `.htaccess` is op deze hosting niet betrouwbaar — daar nooit op leunen.
- Schemawijzigingen alleen via `vacom_migreer()` in `inc/db.php` (user_version).
- Geen externe libraries tenzij expliciet besproken. Google Fonts (Manrope) mag.
- **Volledig gescheiden van de Portal**: alle VaCom-bestanden staan in `vacom/`;
  VaCom leest uit `ipp_data/` maar schrijft er nooit in en raakt geen Portal-code aan.

## Twee standen: Portal-modus en losse modus

`inc/config.php` kijkt of `config_ipp.php` in de webroot staat (of één map boven
VaCom). Zo ja → `VACOM_PORTAL = true`:

- **Login = Portal-sessie.** Geen eigen sessienaam; VaCom leest `logged_in_as`,
  `role`, `is_external` uit de sessie die de Portal zet. `login.php` stuurt naam +
  wachtwoord vanuit de browser naar `../api_ipp.php` (`action=login`). Klanten
  (`is_external`) worden geweigerd. Uitloggen vernietigt de gedeelde sessie.
- **Collega's = `ipp_data/users.json`** (rol `external` overgeslagen; `superadmin`
  blijft superadmin, de rest wordt `lid`). Rijen in `users` hebben dan een lege
  `password_hash`; `gebruikers.php` is alleen-lezen en verwijst naar de Portal.
- **Projectkanalen = `ipp_data/projects.json`**: per project een kanaal met
  `channels.project_token`; naam = slug van de projectnaam, topic "Project · manager".
  In `trash.json` of verdwenen → `archived = 1` (blijft doorzoekbaar). Header toont
  "Open in Portal" → `../project_ipp.php?p=TOKEN`.
- Synchronisatie in `inc/portal.php` (`vacom_portal_sync()`): draait bij elk request
  maar doet alleen iets als de filemtime van users/projects/trash veranderd is
  (`meta.portal_stempel`).

Zonder Portal ernaast → losse modus: eigen accounts, bootstrap-superadmin "Ad",
sessienaam `vacom_sessie`. Test-URL daarvoor: `test.invite.nl/vacom/`.

Waar VaCom op leunt in de Portal (niet wegrefactoren zonder VaCom mee te nemen):
sessievelden `logged_in_as`/`role`/`is_external`, API-actie `login` (POST `name`,
`password`), bestanden `users.json`/`projects.json`/`trash.json`, en de link
`project_ipp.php?p=`.

Waar de Portal op leunt in VaCom (v0.5.1 / Portal v4.13): de projectvelden
`vacom_chat` + `vacom_chat_by` in `projects.json` (Portal zet ze, de sync maakt
het kanaal en maakt de starter 'handmatig' lid), en de URL's
`index.php?embed=1&project=TOKEN` (alleen de gesprekskaart, `body.embed`, in de
popup op de projectkaart) en `index.php?project=TOKEN` (volledige app, kanaal open).

## Twee bedrijven (v0.11, Portal plan 2)

Sinds Portal v5.4 draait dezelfde Portal ook op `portal.incombinatie.nl`
(DirectAdmin-alias van dezelfde map), dus VaCom ook: `portal.incombinatie.nl/vacom/`.
Eén VaCom voor beide bedrijven (zelfde SQLite, zelfde kanalen): collega's van
Invite en Incombinatie zien elkaar, zoals in de Hub. Wat per merk anders is:
`vacom_merk()` in `inc/portal.php` laadt `merk_ipp.php` van de Portal (los,
zonder config_ipp.php) en geeft het merk van het bezochte adres;
`vacom_merk_head()` zet `ipp_merk_head($merk, '../')` ná de stylesheets op
elke pagina (kleurtokens `--goud/--blauw/…`, `--voet-logo`, favicon-link),
`vacom_merk_naam()` staat in de login-tekst, de voet en gebruikers.php. VaCom
heeft verder geen vaste Invite-kleuren meer in app.css, dus hun kleuren komen
vanzelf mee. Projectkanalen: ook de tweede projectmanager van een gedeeld
project (`managers_extra` in projects.json, Portal v5.5.1) is "gekoppeld".
Niet per bedrijf gescheiden: kanalen, DM's, zoeken (bewust: één team).

## v0.18: nalooprondje (veiligheid, snelheid, opruimen)

Uitkomst van een audit op 10 nov 2026. Wat er sindsdien anders werkt:

- **Sessie vrijgeven:** `api.php` (na de CSRF-controle), `bestand.php` en
  `avatar.php` doen `session_write_close()`. Wie daarna de sessie nodig heeft
  (`uitloggen`, `portal_push_info` via `vacom_csrf()`) opent hem opnieuw met
  `vacom_session_start()`. Nieuwe code die `$_SESSION` wil schrijven moet dat
  dus ook doen.
- **Eerst antwoorden, dan het trage werk:** `vacom_antwoord_dan($data, $daarna)`
  (api.php) stuurt het JSON-antwoord, sluit het verzoek af met
  `litespeed_finish_request()` / `fastcgi_finish_request()` en doet dan pas de
  push (`stuur`, `stuur_bestand`, `stemming_maak`). Bestaat geen van beide
  functies (lokale testserver), dan zoals voorheen: eerst het werk.
  Linkvoorvertoning bij `stuur`: uit de cache = meteen mee; anders ná het
  antwoord ophalen, `messages.preview` zetten en `vacom_bump_mx()` (komt via
  de poll-`updates` binnen).
- **Linkvoorvertoning:** `vacom_link_haal()` volgt doorverwijzingen zelf
  (hooguit 3), per stap alleen http/https en `host_publiek_ip()` (publiek
  adres, vastgezet met `CURLOPT_RESOLVE`). Cache `link_previews`: gelukt 30
  dagen geldig, mislukt 1 dag, ouder dan 90 dagen wordt gewist.
- **Push:** `push_abonneer` neemt alleen endpoints van de meldingsdiensten van
  browsers aan (`vacom_push_dienst_ok()` in inc/push.php: Google, Mozilla,
  Windows, Apple; nieuwe dienst = daar toevoegen). Uitloggen in de app meldt
  het apparaat eerst af (`push_afmelden` + `unsubscribe`). Uitloggen via de
  Portal of de Hub doet dat niet (open punt).
- **Bijlagen:** `bestand.php` stuurt `Cache-Control: private, no-cache` met een
  `ETag` en antwoordt 304; de toegangscontrole draait dus bij elke weergave.
  Avatars blijven een jaar in de cache (`?v=mtime`).
- **Minder prijsgeven:** de poll geeft `typing` alleen aan kanaalleden;
  `portal_ongelezen` alleen je eigen projectkanalen; `kanaal_deelnemen` alleen
  als `VACOM_PROJECTKANALEN` aan staat; `kanaal_topic` weigert een
  projectkanaal; `vacom_public_user($u, $metEmail)` geeft het e-mailadres
  alleen bij `me`.
- **Systeemberichten** tellen nergens meer als ongelezen (ook niet in de poll,
  `portal_ongelezen` en `portal_vermeldingen`) en staan niet in `zoek`, ook
  niet met alleen filters. Elke nieuwe telling: `x.systeem = 0` erbij.
- **Opmaak (app.js `opmaak()`):** links worden net als code weggezet met
  `bewaar()` vóór de @namen en de opmaak, zodat die niet in de `href` komen.
- **Database:** migratie 14 (alleen indexen: `idx_reactions_message` weg,
  `saved(message_id)`, `stemming_opties(stemming_id)`, `push_subs(user_id)`
  erbij). `vacom_metas([...])` leest meer meta-waarden in één query.
  `vacom_portal_sync()` gebruikt `BEGIN IMMEDIATE` en kijkt na het slot opnieuw
  naar de stempel; het werk zelf staat in `vacom_portal_sync_werk()`.
- **Bewust niet gedaan:** tellers `rx`/`mx` per kanaal (nu globaal: elke
  wijziging laat elke open client de laatste 150 berichten van zijn kanaal
  nakijken). Vraagt een wijziging in de poll aan beide kanten en een test in
  de browser.
- **Geparkeerd, geen aanroeper meer** (Portal-koppeling weg sinds Portal
  v4.21): `portal_ongelezen`, `portal_project`, `portal_push_info`,
  `kanaal_deelnemen`, de embed-modus. Alleen `portal_vermeldingen` wordt nog
  gebruikt (Invite Hub, het bolletje op de tegel): antwoordformaat niet wijzigen.

## v0.13: kop, projectkanalen uit, verwijderen

- **Kop** (`.app-kop`, geen `.vlak` meer): open regel zonder kader zoals de
  Portal-kop; woordmerk `var(--letter-titel)`/800 links, knoppen rechts op de
  basislijn (`align-items: flex-end`). Mobiel 20px.
- **Projectkanalen uit** via `VACOM_PROJECTKANALEN` (inc/config.php, nu
  `false`): sectie `#sectie-projecten` krijgt `hidden`, `window.VACOM.projectkanalen`
  laat `renderZijbalk()` bestaande projectkanalen bij Kanalen zetten, de API
  weigert `projecten_beschikbaar`/`projectkanaal_aanmaken`. De sync in
  inc/portal.php (leden, archiveren bij prullenbak) blijft werken voor
  bestaande projectkanalen. Op `true` komt alles terug.
- **Verwijderen:** `kanaal_verwijderen` mag nu door `mag_kanaal_beheren()`
  (superadmin óf wie het kanaal aanmaakte; `kan_wissen` in `kanaal_leden`),
  sinds v0.13.1 ook voor een projectkanaal (zet `pk_gewist:<token>` in meta,
  zoals `beheer_kanaal_wissen`).
  Nieuw `gesprek_verwijderen` (DM, door een van de twee deelnemers): wist
  berichten, reacties, bijlagen, leden en het kanaal; knop
  `#opties-dm-verwijderen` in de opties-popup (CSS `.dm`). "Uit mijn lijst
  halen" (`gesprek_verbergen`) blijft.

## v0.17: kanaal hernoemen

API `kanaal_naam` (channel_id, name): `mag_kanaal_beheren()`, geen projectkanaal,
zelfde slug-regels als `kanaal_nieuw`, uniek; schrijft een systeembericht
"… heeft het kanaal hernoemd van #a naar #b". `kanaal_leden` geeft `kan_hernoemen`.
UI: veld `#opties-naam` (`.hash-veld`) boven Onderwerp in `#dialoog-opties`,
uitgeschakeld tot `kan_hernoemen`; opslaan bij `change`.

## v0.16: kop en zijbalk-kopjes met de maten van de Portal

- `.app-kop`: titel `var(--letter-pagina)` (40px; mobiel `--letter-cijfer`),
  knoppen in `.kop-acties` met `margin-bottom: 10px` (basislijn van de
  titel, zoals `.kop-acties` in style_ipp.css), themaknop `.knop.icoon.doorzichtig`.
  Verticale ruimte bewust kleiner dan de Portal-kop (chat moet eronder passen).
- Zijbalk: `summary` van de secties oogt als `.kopje` (geen gouden driehoek
  meer); een klein pijltje `::after` achter `.sectie-titel` draait als de sectie
  dicht is.

## v0.15: kop, profiel en inloggen zoals de Portal

- **Kop:** knoppen in de Portal-volgorde (zoeken, @, opgeslagen, instellingen,
  uitloggen, thema), als laatste `#kop-ik.kop-profiel` (het rondje uit
  invite_stijl.css; `renderKopIk()` vult foto of letter; klik = Instellingen).
  `.kop-ik`-pil en `#kop-naam` bestaan niet meer.
- **Profiel uit de Portal (Portal-modus):** `vacom_public_user()` leest de
  foto-versie uit `ipp_data/profiel/<sha1(sleutel)>.jpg` en de status uit
  `ipp_data/profielen.json` (`vacom_portal_naam_sleutel()` = kopie van
  `ipp_name_key()` in de Portal: hou ze gelijk). `avatar.php` serveert die
  Portal-foto (`vacom_foto_pad()`). Wijzigen gaat vanuit de browser via de
  Portal-API (`portalPost()` in app.js: `profiel_status`, `profiel_foto` met
  veld `foto`, `profiel_foto_weg`), daarna `overzicht` opnieuw; de eigen acties
  `status_zetten`/`avatar_upload`/`avatar_verwijderen` weigeren in
  Portal-modus en blijven alleen voor de losse modus. `users.status` en
  `data/avatars/` worden in Portal-modus niet meer gebruikt.
- **Inloggen:** login.php gebruikt het `.inlog`-vak van de Portal
  (invite_stijl.css sectie 13 sinds Portal v5.5.5: merkfoto via `--inlog-foto`,
  `.veld.groot.glas`, `.inlog-melding`) met `#login-ms-blok` + `../ms_login_ipp.js`
  (`redirectUri` = login.php zelf; **in Entra moet `…/vacom/login.php` als
  SPA-redirect staan**, voor beide merkdomeinen; staat erin sinds 9 nov 2026,
  in de app-registratie Invite Transfer). Daaronder `.voet.login`.
  gebruikers.php en changelog.php eindigen met `.voet` + `.voet-logo`.

## v0.14: systeemberichten

`messages.systeem` (migratie 13). `systeembericht($db, $kanaalId, $doorId,
$tekst)` in api.php voegt een gewone rij toe met `systeem = 1`; aangeroepen bij
`kanaal_nieuw` (aangemaakt), `kanaal_deelnemen` en `lid_toevoegen` (doet mee /
is toegevoegd door), `lid_verwijderen` (heeft het kanaal verlaten / is
verwijderd door). `bericht_uit()` geeft `systeem` mee; de client tekent
`.bericht.systeem` (één grijze regel, geen avatar/acties, breekt het groeperen
van vervolgberichten). Niet meegeteld als ongelezen (`x.systeem = 0` in
overzicht.php en `portal_project`), niet in `zoek`, geen push en geen
vermeldingen (die lopen alleen via `stuur`). Verwijderen van een kanaal of
gesprek geeft geen bericht: het kanaal is dan voor iedereen weg.

## Opbouw

| Bestand | Wat |
|---|---|
| `index.php` | de app (één pagina); geeft het eerste overzicht mee in `window.VACOM` |
| `api.php` | alle acties als JSON (POST + header `X-VaCom-Csrf`; via GET alleen de alleen-lezen acties in `VACOM_GET_ACTIES`, de rest 405) |
| `login.php`, `gebruikers.php`, `changelog.php` | inloggen, gebruikers, wat is er nieuw |
| `avatar.php`, `bestand.php` | profielfoto's en bijlagen uit de datamap serveren (alleen ingelogd / kanaallid) |
| `inc/config.php` | versie, paden, Portal-detectie, helpers (`h()`, `vacom_json()`, `asset()`) |
| `inc/db.php` | SQLite openen + migraties |
| `inc/auth.php` | sessies, CSRF, rate-limit, gebruikers, bootstrap (losse modus) |
| `inc/portal.php` | Portal-modus: sync van collega's en projectkanalen, sessiegebruiker |
| `inc/overzicht.php` | het overzicht (kanalen + tellers) voor index én api |
| `assets/app.js`, `assets/app.css` | de client en de vormgeving |
| `manifest.json`, `sw.js`, `offline.html` | PWA (installeerbaar; SW cachet bewust niets) |
| `tools/` | eenmalig hulpscript `vacom_check.php` (servercheck, toont serverdetails zonder inlog) — niet uploaden |

Datamodel: `users`, `channels` (type public/dm; DM = `dm_key` "kleinsteId:grootsteId";
`project_token` bij projectkanalen), `channel_members` (met `last_read_id`, `via`, `hidden`),
`messages` (id = globale oplopende klok; `parent_id` klaar voor threads; `attachment`
JSON v0.6 — het bestand zelf staat in `VACOM_DATA/bestanden/<bericht-id>/`, met
`thumb.jpg` bij foto's, geserveerd door `bestand.php?m=ID` aan kanaalleden),
`messages_fts`, `reactions`, `mentions` (v0.7: wie in welk bericht met @Naam genoemd
is; gevuld door `vacom_vermeldingen_opslaan()` bij sturen/bewerken, jezelf telt niet),
`stemmingen` + `stemming_opties` + `stemming_stemmen` (polls v0.8: de vraag is de
message-body, CASCADE op het bericht; API `stemming_maak/stem/sluit`, de stand
gaat mee in de poll-'updates' via `mx`),
`meta` (tellers `state` → overzicht opnieuw, `rx` →
reacties, `mx` → bewerkte/verwijderde berichten en poll-stemmen; `portal_stempel`). Iedereen is
automatisch lid van elk openbaar kanaal. Verwijderen van een bericht is een soft
delete (`deleted=1`, body leeg → uit de zoekindex, bijlage van schijf).

## v0.9: threads, push, opslaan/vastzetten, voorvertoning, typt, zoekfilters

- **Threads**: `messages.parent_id` (antwoord op een antwoord hangt aan de starter).
  Kanaallijsten tonen alleen `parent_id IS NULL`; `api thread` geeft starter +
  antwoorden; `stuur` met `parent_id`; de poll krijgt `thread_id` mee en stuurt
  `thread_messages`/reacties/updates voor de open thread. `extra_bij()` levert
  per bericht `replies`, `last_reply`, `reply_user_ids`, `pinned`, `saved`.
- **Push**: `inc/push.php` — VAPID-sleutels in `VACOM_DATA/vapid.json` (bij
  eerste gebruik aangemaakt), RFC 8291-versleuteling, curl_multi. Abonnementen in
  `push_subs`; voorkeur `users.push_alles`. `push_bij_bericht()` bepaalt wie een
  melding krijgt (DM / @vermelding / thread-deelnemer / alles) en slaat mensen
  over die nu naar VaCom kijken: `users.kijkt_at` jonger dan 45 s (v0.9.3; de
  poll stuurt `kijkt=1` mee als het venster zichtbaar is én de aandacht heeft).
  Níet `last_seen` gebruiken: dat wordt door elk verzoek bijgewerkt, ook door
  de achtergrondcontrole van de Portal-header. `sw.js` toont de melding en opent
  `index.php#k=ID&t=THREAD`. Windows-dev: OPENSSL_CONF wordt gezet in push.php.
- **Opslaan/vastzetten**: tabellen `saved` (privé) en `pins` (per kanaal).
- **Voorvertoning**: `messages.preview` (JSON) + cache `link_previews`;
  `vacom_link_preview()` haalt og:title/description/image op met SSRF-guard
  (`host_publiek_ip`, per doorverwijzing — zie v0.18), 4 s timeout, alleen html.
- **Typt**: tabel `typing` (channel_id, user_id, at); `api typ` max. elke 2,5 s
  vanaf de client; de poll stuurt `typing` (namen, laatste 6 s) altijd mee.
- **Zoekfilters**: `zoek` met `user_id`, `channel_id`, `from`, `to`; zonder `q`
  een gewone query op `messages`.

## Geheimen

De Giphy API-sleutel (GIF-knop, v0.5.3) staat NIET in de code of in Git, maar
als platte tekst in `giphy_key.txt` in de datamap (`../vacom_data/` op de
server, `vacom/data/` lokaal — beide buiten Git). Zie `vacom_giphy_key()` in
`inc/config.php`. Zonder bestand is de GIF-knop er gewoon niet. Zoeken gaat via
`api.php?actie=gif_zoeken` (server-side, sleutel blijft op de server).

## Beveiliging — altijd in acht nemen

- CSRF-token op elke schrijfactie; `hash_equals`; wachtwoorden `password_hash`.
- Rate-limiting op login (8 pogingen / 15 min per IP) in losse modus; in
  Portal-modus doet de Portal dat.
- Host header injection: VaCom maakt nergens een volledige URL (alle links zijn
  relatief; `VACOM_BASE_URL` is in v0.18 weggehaald, werd niet gebruikt). Komt er
  ooit een absolute link (e-mail): een vaste waarde of het merk uit `merk_ipp.php`,
  nooit de Host-header. `trim()` bij tokens/wachtwoorden.
- API zet `display_errors` uit: waarschuwingen mogen nooit in JSON lekken.
- Alle uitvoer escapen (`h()` in PHP, `esc()` in JS).

## Vormgeving — de stijlgids van de Portal (sinds v0.10.0)

VaCom gebruikt de gedeelde stijl van Invite: `invite_stijl.css` in de Portal,
met overzichtspagina `stijlgids_ipp.php` (alleen ingelogd). Elke pagina laadt
**eerst `../invite_stijl.css`** (via `vacom_gedeelde_stijl()` in `inc/config.php`;
cache-buster = wijzigingsdatum; in losse modus het bestand van portal.invite.nl)
**en dan `assets/app.css`**; een VaCom-regel met dezelfde specificiteit wint dus.
Zo doen de Hub, de Socials Planner en Transfer het ook. Een wijziging in
`invite_stijl.css` geldt dus ook hier: na een Portal-stijlwijziging VaCom nakijken.

Gebruik de bouwstenen van de stijlgids, niet iets eigens:
- tokens: `--goud --groen --blauw --rood --oranje` (+ `-tekst` voor tekst),
  `--text --muted --border --border-hov --card --surface`, `--letter-mini …
  --letter-pagina`, `--radius` (5px) / `--radius-card` (7px) / `--radius-chip`,
  `--shadow-card --shadow-pop --focus-ring`, `--glass-*`; de aurora zit op `body`;
- `.knop` (+ `.goud` hoofdknop, `.rood` verwijderen, `.klein`, `.icoon`, `.sluit`,
  `.breed`, `.stil`, `.doorzichtig`, `.actief`/`aria-pressed`);
- `.veld` (+ `.klein`, `.groot`, `.mb-0` in een popup), `.veldrij`, `.veldlabel`
  (met `<span>` voor "(optioneel)"), `.veldhint`, `.zoekveld`, `.vink`;
- `.popup` op de `<dialog>` (`.popup-kop` h3 + `.knop.icoon.klein.sluit`,
  `.popup-inhoud`, `.popup-voet`, `.links` = knoppen links), `.melding` (+ `.show`)
  = de zwevende toast rechtsonder (`toast()` in app.js);
- `.chip.klein.klik` (reacties: `.reactie`, `.mijn`), `.label.grijs` (jij, thread,
  gesloten, via Portal), `.teller` (ongelezen; `.vermelding` = blauw), `.vlak`
  (kop, zijbalk, gesprek, kaarten; `.zweef` voor pickers en banners), `.kopje`,
  `.tabel`, `.leeg.groot` (leeg gesprek).

Eigen namen in `app.css` (bewust anders dan de stijlgids, die namen botsten):
`.meldregel` (melding in de pagina, `.fout`/`.info`/`.ok`), `.inst-profiel-*`
(profielblok in Instellingen; `.profiel` is de voet-pil van de Portal), `--tint`
/`--tint-2` (lichte vulling). Verder alleen wat van VaCom is: de app-indeling
(kop, zijbalk, gesprek als losse glazen kaarten op de aurora), berichten,
reacties, threads, polls, bijlagen, voorvertoning, pickers, lichtbak, foto
bijsnijden, banners en de losse pagina's. Thema: localStorage `ipp_theme`
(gedeeld met de Portal). Inputs 16px op mobiel zodat iOS niet inzoomt (zit in
de stijlgids); safe-area insets voor de iPhone-notch.
**Stijlgidspagina:** het VaCom-kader in `stijlgids_ipp.php` (Portal) is sinds
Portal v5.5.4 opgebouwd met de huidige klassen (laadt eerst `invite_stijl.css`,
dan `vacom/assets/app.css`, en volgt het merk via `?merk=`). Komt er een nieuwe
bouwsteen bij, dan daar ook een voorbeeld toevoegen.
**Logo (v0.12):** alleen het woordmerk `.logo > .logo-naam` (`.logo.groot` op
het inlogscherm en in het lege gesprek); `.logo-icoon` bestaat niet meer.
**Icoon (v0.16.1):** `assets/favicon.svg` en `assets/favicon_inc.svg` zijn
kopieën van `iconen/vacom.svg` en `iconen/vacom_inc.svg` uit de Invite Hub
(bij een nieuw Hub-icoon hier ook vervangen); `vacom_favicon()` (inc/portal.php)
kiest per merk en staat ná `vacom_merk_head()`. De PNG's voor de PWA
(`icon-192`, `icon-512`, `icon-512-maskable` met 10% rand op geel,
`apple-touch-icon` 180) zijn uit die svg gerenderd (browser-canvas); bij een
nieuw icoon opnieuw maken. `ICOONTJE.png` is weg; `tools/maak_iconen.php` (tekende
het oude icoon) ook, sinds v0.18.

## Werkwijze bij elke release

1. Verhoog `VACOM_VERSION` in `inc/config.php` (ook cache-buster) en `VERSIE` in `sw.js`.
2. Voeg bovenaan `changelog.php` een blok toe (nieuwste eerst, begrijpelijk Nederlands).
3. Test lokaal, commit + push naar GitHub.
4. Ad upload zelf via FTP naar `public_html/vacom/` op portal.invite.nl — **nooit
   `data/` of `tools/`** meesturen.
5. Noem het nieuwe versienummer in het antwoord.

## Lokaal testen

**Losse modus** (standaard, want deze map staat niet meer in de Portal): start
launch-config `vacom` in `.claude/launch.json` (poort 8400, de winget-PHP 8.1 die
ook pdo_sqlite laadt) en open http://localhost:8400/. Eerste keer: bootstrap-
superadmin "Ad", wachtwoord in `data/`. Lokale VaCom-data staat in `data/` (git-ignored);
daar staan ook twee testaccounts (Tester = superadmin, Collega = lid), wachtwoord in
`data/TESTACCOUNT.txt`. In losse modus komt `invite_stijl.css` van portal.invite.nl
(dus internet nodig).

**Portal-modus** (precies zoals online) testen kan alleen als VaCom als map
`vacom/` naast `config_ipp.php` in de Portal-map staat. Daarvoor een junction
maken (één keer, Windows; de Portal-repo negeert `vacom/` via `.gitignore`):

```bash
cmd //c mklink //J "C:\Users\Ad Grainger\Documents\Claude\Invite Project Portal\vacom" "C:\Users\Ad Grainger\Documents\Claude\VaCom"
```

Start dan de Portal-dev-server (launch-config `portal` in de Portal-map, poort
8000) en open http://localhost:8000/vacom/. Inloggen met een account uit de
lokale `ipp_data/users.json` (lokaal staat daar alleen Admin in; het wachtwoord
staat in `ipp_data/BOOTSTRAP_WACHTWOORD.txt`, niet in Git). Launch-config
`portal` in deze map doet hetzelfde (serveert de Portal-map op poort 8000).

Handmatig, zonder launch-config:

```bash
php -S localhost:8400 -d extension_dir=C:\php\ext -d extension=pdo_sqlite -d extension=openssl -d extension=curl -d extension=mbstring -d extension=fileinfo -d extension=gd
```

Voor EC-sleutels (push, later) op Windows: `OPENSSL_CONF=C:\php\extras\ssl\openssl.cnf`.

## Roadmap

- Fase 1 (v0.1): login, kanalen, DM's, live berichten, zoeken, PWA-basis. ✔
- v0.2: Portal-modus (gedeelde login, collega's en projectkanalen uit de Portal). ✔
- Fase 2: threads, emoji-reacties, bewerken/verwijderen, ongelezen-markering,
  pushnotificaties (Web Push vanuit PHP; server kan ES256 + ECDH), e-mail bij @mention,
  knopje "bespreek in VaCom" bij een project in de Portal (optioneel).
- Fase 3: bestanden en afbeeldingen delen, linkvoorvertoning, zoekfilters.
- Fase 4: licht thema, desktop-wrapper, webhooks.
