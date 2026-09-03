<?php
/**
 * wurd — wat is er nieuw. Nieuwste versie bovenaan.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
wurd_require_login();
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f1319">
<title>Wat is er nieuw — wurd</title>
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
    <a class="knop" href="index.php">‹ Terug naar wurd</a>
    <h1>Wat is er nieuw</h1>
  </header>

  <section class="kaart glas">
    <h2>v0.1.0 <span class="stil">— 3 september 2026</span></h2>
    <ul>
      <li>Eerste testversie van wurd: inloggen, kanalen, directe berichten en collega's in de zijbalk.</li>
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
