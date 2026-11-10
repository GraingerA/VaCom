# VaCom

*VaCom (Vastgoed Communicatie) is het interne kanaal van Invite en Incombinatie, à la Slack.*

Werkt in de browser op Mac en PC, en als app op de iPhone (via "Zet op beginscherm").
Draait als submap van de Invite Project Portal, op dezelfde PHP-hosting — geen
database-server of installatie nodig. Eigen repo: `GraingerA/VaCom`.

## Wat kan het (v0.18)

- **Inloggen met je Portal-account** (wachtwoord of Microsoft) — wie in de Portal is
  ingelogd, is in VaCom ingelogd. Klanten en externe ontwerpers hebben geen toegang.
- **Kanalen** (#algemeen en wat je zelf aanmaakt) en **directe berichten**, voor
  de collega's van beide bedrijven samen.
- **Threads**, emoji-reacties, @vermeldingen, polls, vastzetten en opslaan.
- **Foto's en bestanden** meesturen (tot 25 MB), gifs via Giphy, voorvertoning van links.
- **Zoeken** door alle berichten, met filters op persoon, kanaal en periode.
- **Pushmeldingen** op telefoon en computer; ongelezen-tellers, "typt…", online-stipjes.
- Foto en status komen uit de Portal (dezelfde als in de Hub).
- Projectkanalen (een kanaal per Portal-project) staan voorlopig uit
  (`VACOM_PROJECTKANALEN` in `inc/config.php`).

Wat er per versie veranderd is staat in `changelog.php` (in de app: Instellingen →
Wat is er nieuw).

## Op de server zetten (portal.invite.nl)

1. Upload via FTP alle bestanden naar `public_html/vacom/`, **behalve** de mappen
   `data/`, `tools/` en `.claude/`.
2. Klaar. Open `https://portal.invite.nl/vacom/` (of `https://portal.incombinatie.nl/vacom/`).
   VaCom ziet de Portal ernaast, maakt zelf zijn database aan in `vacom_data/` (naast
   `ipp_data/`, buiten de webroot) en haalt collega's uit de Portal. Een nieuwe versie
   werkt de database zelf bij.
3. Log in met je Portal-account.

`vacom_data/` kopiëren = volledige back-up. Bij een nieuwe versie upload je alleen
code; de data blijft staan.

`tools/vacom_check.php` (servercheck) toont serverdetails zonder inlog: alleen
tijdelijk op de server zetten en daarna weer weghalen.

Zonder Portal ernaast (bijv. op test.invite.nl) werkt VaCom ook: dan met eigen
accounts. Het startwachtwoord voor "Ad" staat dan in `vacom_data/BOOTSTRAP_WACHTWOORD.txt`.

## Op de iPhone

Open de link in Safari, tik op **Delen** en dan **Zet op beginscherm**. Daarna opent
VaCom als een echte app, zonder adresbalk, en kun je meldingen aanzetten.

## Lokaal testen

Losse modus: launch-config `vacom` (poort 8400), open http://localhost:8400/.
Portal-modus (zoals online): VaCom als map `vacom/` in de Portal-map hangen en de
Portal-dev-server starten (poort 8000), open http://localhost:8000/vacom/.
De stappen staan in `CLAUDE.md`.

## Technische opzet

| Onderdeel | Bestand(en) |
|---|---|
| De app | `index.php`, `assets/app.js`, `assets/app.css` |
| API (alle acties) | `api.php` |
| Bijlagen en profielfoto's serveren | `bestand.php`, `avatar.php` |
| Inloggen, gebruikers | `login.php`, `gebruikers.php`, `inc/auth.php` |
| Koppeling met de Portal | `inc/portal.php` |
| Database (SQLite, buiten de webroot) | `inc/db.php` |
| Pushmeldingen | `inc/push.php`, `sw.js` |
| Versie & changelog | `inc/config.php`, `changelog.php` |
| App-installatie (PWA) | `manifest.json`, `sw.js`, `offline.html` |
