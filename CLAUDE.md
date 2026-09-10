# VaCom — projectcontext voor Claude

VaCom (van Vastgoed Communicatie) is de interne Slack-variant van Invite:
kanalen, directe berichten, zoeken door alles. Doorzoekbaarheid is de kernbelofte.
Werktitel tot 3 sept 2026 was "wurd"; identifiers zijn toen hernoemd naar `vacom`.
Op 7 sept 2026 heette de app één dag "Petear"; sinds 8 sept (v0.5.2) weer VaCom.

VaCom is een **submap van de Portal-repo**: lokaal `Invite Project Portal\root\vacom\`,
online `portal.invite.nl/vacom/` (Portal-modus), GitHub `GraingerA/Invite-Project-Portal`
(map `vacom/`). Eén repo, één upload — maar VaCom heeft zijn eigen versienummer,
changelog en deze CLAUDE.md. Tot 3 sept 2026 was het een losse repo (`GraingerA/VaCom`).

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

## Opbouw

| Bestand | Wat |
|---|---|
| `index.php` | de app (één pagina); geeft het eerste overzicht mee in `window.VACOM` |
| `api.php` | alle acties als JSON (POST + header `X-VaCom-Csrf`; alleen `poll` via GET) |
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
  over die de laatste 75 s actief waren. `sw.js` toont de melding en opent
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

## Vormgeving

Huisstijl Invite Project Portal: donker met aurora-achtergrond, goud `#fbbc43`,
blauw `#8ecae8`, groen `#4da875`, Manrope, glazen header/kaarten, hoekradius
5px (knoppen) / 7px (kaarten). Inputs 16px zodat iOS niet inzoomt; safe-area
insets voor de iPhone-notch.

## Werkwijze bij elke release

1. Verhoog `VACOM_VERSION` in `inc/config.php` (ook cache-buster) en `VERSIE` in `sw.js`.
2. Voeg bovenaan `changelog.php` een blok toe (nieuwste eerst, begrijpelijk Nederlands).
3. Test lokaal, commit + push naar GitHub.
4. Ad upload zelf via FTP naar `public_html/vacom/` op portal.invite.nl — **nooit
   `data/` of `tools/`** meesturen.
5. Noem het nieuwe versienummer in het antwoord.

## Lokaal testen

Start de Portal-dev-server (launch-config `portal` in `root\.claude\launch.json`,
poort 8010; die laadt ook pdo_sqlite) en open http://localhost:8010/vacom/.
Inloggen met een account uit de lokale `ipp_data/users.json` (testaccount
Bram / test1234). VaCom draait dan in Portal-modus, precies zoals online.
Lokale VaCom-data staat in `vacom/data/` (git-ignored).

Losse modus testen (zonder Portal): serveer alleen de map `vacom`:

```bash
php -S localhost:8400 -t vacom -d extension_dir=C:\php\ext -d extension=pdo_sqlite -d extension=openssl -d extension=curl -d extension=mbstring -d extension=fileinfo -d extension=gd
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
