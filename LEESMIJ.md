# VaCom

*VaCom (Vastgoed Communicatie) is het interne kanaal van Invite, à la Slack.*

Werkt in de browser op Mac en PC, en als app op de iPhone (via "Zet op beginscherm").
Draait als submap van de Invite Project Portal, op dezelfde PHP-hosting — geen
database-server of installatie nodig.

## Wat kan het (v0.2)

- **Inloggen met je Portal-account** — wie in de Portal is ingelogd, is in VaCom ingelogd.
  Klanten hebben geen toegang.
- **Per Portal-project een kanaal** (automatisch), met een knop naar het project.
- **Losse kanalen** (#algemeen en wat je zelf aanmaakt) en **directe berichten**.
- Berichten verschijnen binnen een paar seconden bij iedereen.
- **Zoeken** door alle berichten (vergrootglas linksboven, of Ctrl/Cmd+K).
- Ongelezen-tellers, groene stip bij collega's die online zijn.

## Op de server zetten (portal.invite.nl)

1. Maak in `public_html` de map `vacom` en upload daarin via FTP alle bestanden,
   **behalve** de mappen `data/`, `tools/` en `.claude/`.
2. Klaar. Open `https://portal.invite.nl/vacom/`. VaCom ziet de Portal ernaast,
   maakt zelf zijn database aan in `vacom_data/` (naast `ipp_data/`, buiten de
   webroot) en haalt collega's en projecten uit de Portal.
3. Log in met je Portal-naam en -wachtwoord.

`vacom_data/` kopiëren = volledige back-up. Bij een nieuwe versie upload je alleen
code; de data blijft staan. Weghalen = de mappen `vacom/` en `vacom_data/` verwijderen;
de Portal merkt er niets van.

Zonder Portal ernaast (bijv. op test.invite.nl) werkt VaCom ook: dan met eigen
accounts. Het startwachtwoord voor "Ad" staat dan in `vacom_data/BOOTSTRAP_WACHTWOORD.txt`.

## Op de iPhone

Open de link in Safari, tik op **Delen** en dan **Zet op beginscherm**. Daarna opent
VaCom als een echte app, zonder adresbalk.

## Lokaal testen

Zie `CLAUDE.md` (launch-configs `vacom-in-portal` en `vacom`).

## Technische opzet

| Onderdeel | Bestand(en) |
|---|---|
| De app | `index.php`, `assets/app.js`, `assets/app.css` |
| API (alle acties) | `api.php` |
| Inloggen, gebruikers | `login.php`, `gebruikers.php`, `inc/auth.php` |
| Koppeling met de Portal | `inc/portal.php` |
| Database (SQLite, buiten de webroot) | `inc/db.php` |
| Versie & changelog | `inc/config.php`, `changelog.php` |
| App-installatie (PWA) | `manifest.json`, `sw.js`, `offline.html` |
