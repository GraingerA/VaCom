# VaCom

*VaCom (Vastgoed Communicatie) is het interne kanaal van Invite, à la Slack.*

Werkt in de browser op Mac en PC, en als app op de iPhone (via "Zet op beginscherm").
Draait op gewone PHP-hosting — geen database-server of installatie nodig.

## Wat kan het (v0.1)

- **Kanalen** (#algemeen en wat je zelf aanmaakt) — iedereen is automatisch lid
- **Directe berichten** met een collega
- Berichten verschijnen binnen een paar seconden bij iedereen
- **Zoeken** door alle berichten (vergrootglas linksboven, of Ctrl/Cmd+K)
- Ongelezen-tellers, groene stip bij collega's die online zijn
- Gebruikersbeheer voor de superadmin; iedereen wijzigt zijn eigen wachtwoord

## Op de server zetten (test.invite.nl)

1. Upload via FTP alle bestanden naar de submap `vacom` van de webruimte,
   **behalve** de mappen `data/`, `tools/` en `.claude/`.
2. Klaar. Open `https://test.invite.nl/vacom/` — bij de allereerste start maakt
   VaCom zelf de database en het kanaal #algemeen aan, plus het account **Ad**.
3. Het startwachtwoord staat in `vacom_data/BOOTSTRAP_WACHTWOORD.txt`, één map
   *boven* `public_html` (dus alleen via FTP te lezen, niet via de browser).
   Log in, wijzig je wachtwoord via ⚙, en voeg collega's toe.

De data (database) staat in `/home/testinvite/domains/test.invite.nl/vacom_data/`.
Die map kopiëren = volledige back-up. Bij een nieuwe versie upload je alleen code;
de data blijft staan.

## Op de iPhone

Open de link in Safari, tik op **Delen** en dan **Zet op beginscherm**. Daarna opent
VaCom als een echte app, zonder adresbalk. (Nodig voor pushmeldingen, die in een
volgende versie komen.)

## Lokaal testen

```bash
php -S localhost:8400 -d extension_dir=C:\php\ext -d extension=pdo_sqlite -d extension=openssl -d extension=curl -d extension=mbstring -d extension=fileinfo -d extension=gd
```

Open http://localhost:8400 — het startwachtwoord staat dan in `data/BOOTSTRAP_WACHTWOORD.txt`.

## Technische opzet

| Onderdeel | Bestand(en) |
|---|---|
| De app | `index.php`, `assets/app.js`, `assets/app.css` |
| API (alle acties) | `api.php` |
| Inloggen, gebruikers | `login.php`, `gebruikers.php`, `inc/auth.php` |
| Database (SQLite, buiten de webroot) | `inc/db.php` |
| Versie & changelog | `inc/config.php`, `changelog.php` |
| App-installatie (PWA) | `manifest.json`, `sw.js`, `offline.html` |
