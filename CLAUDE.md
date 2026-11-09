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
| `tools/` | eenmalige hulpscripts (iconen maken, servercheck) — niet uploaden |

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
  (`host_publiek`), 4 s timeout, alleen html.
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
- `VACOM_BASE_URL` hardcoded (host header injection); `trim()` bij tokens/wachtwoorden.
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
het inlogscherm en in het lege gesprek); `.logo-icoon` bestaat niet meer. De
bestanden `assets/icon-*.png`, `apple-touch-icon.png` en `ICOONTJE.png` blijven
voor de PWA en de favicon.

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
