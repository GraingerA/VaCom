# VaCom — projectcontext voor Claude

VaCom (van Vastgoed Communicatie) is de interne Slack-variant van Invite:
kanalen, directe berichten, zoeken door alles. Doorzoekbaarheid is de kernbelofte.

Lokale map: `Documents\VaCom`. Online (testfase): `test.invite.nl/vacom/`; bij
ingebruikname wordt dat `vacom.invite.nl`.

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
  **buiten de webroot** (`../vacom_data/` naast `public_html`; lokaal `./data/`).
  `.htaccess` is op deze hosting niet betrouwbaar — daar nooit op leunen.
- Schemawijzigingen alleen via `vacom_migreer()` in `inc/db.php` (user_version).
- Geen externe libraries tenzij expliciet besproken. Google Fonts (Manrope) mag.

## Opbouw

| Bestand | Wat |
|---|---|
| `index.php` | de app (één pagina); geeft het eerste overzicht mee in `window.VACOM` |
| `api.php` | alle acties als JSON (POST + header `X-VaCom-Csrf`; alleen `poll` via GET) |
| `login.php`, `gebruikers.php`, `changelog.php` | inloggen, gebruikersbeheer/eigen wachtwoord, wat is er nieuw |
| `inc/config.php` | versie, paden, helpers (`h()`, `vacom_json()`, `asset()`) |
| `inc/db.php` | SQLite openen + migraties |
| `inc/auth.php` | sessies, CSRF, rate-limit, gebruikers, bootstrap-superadmin "Ad" |
| `inc/overzicht.php` | het overzicht (kanalen + tellers) voor index én api |
| `assets/app.js`, `assets/app.css` | de client en de vormgeving |
| `manifest.json`, `sw.js`, `offline.html` | PWA (installeerbaar; SW cachet bewust niets) |
| `tools/` | eenmalige hulpscripts (iconen maken, servercheck) — niet uploaden |

Datamodel: `users`, `channels` (type public/dm; DM = `dm_key` "kleinsteId:grootsteId"),
`channel_members` (met `last_read_id`), `messages` (id = globale oplopende klok;
`parent_id` klaar voor threads), `messages_fts`, `reactions`, `meta.state`
(teller die verandert bij nieuw kanaal/gebruiker → client haalt overzicht opnieuw).
Iedereen is automatisch lid van elk openbaar kanaal.

## Beveiliging — altijd in acht nemen

- CSRF-token op elke schrijfactie; `hash_equals`; wachtwoorden `password_hash`.
- Rate-limiting op login (8 pogingen / 15 min per IP).
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
4. Ad upload zelf via FTP naar `test.invite.nl/vacom/` — **nooit `data/`** meesturen;
   op de server staat de data toch al buiten de webroot.
5. Noem het nieuwe versienummer in het antwoord.

## Lokaal testen

```bash
php -S localhost:8400 -d extension_dir=C:\php\ext -d extension=pdo_sqlite -d extension=openssl -d extension=curl -d extension=mbstring -d extension=fileinfo -d extension=gd
```

(`.claude/launch.json` bevat dezelfde configuratie.) Eerste start maakt
`data/BOOTSTRAP_WACHTWOORD.txt` met het wachtwoord van superadmin "Ad".
Voor EC-sleutels (push, later) op Windows: `OPENSSL_CONF=C:\php\extras\ssl\openssl.cnf`.

## Roadmap

- Fase 1 (v0.1): login, kanalen, DM's, live berichten, zoeken, PWA-basis. ✔
- Fase 2: threads, emoji-reacties, bewerken/verwijderen, ongelezen-markering,
  pushnotificaties (Web Push vanuit PHP; server kan ES256 + ECDH), e-mail bij @mention.
- Fase 3: bestanden en afbeeldingen delen, linkvoorvertoning, zoekfilters.
- Fase 4: licht thema, desktop-wrapper, webhooks.
