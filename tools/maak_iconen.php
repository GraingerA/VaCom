<?php
/**
 * Maakt de app-iconen (PNG) in assets/ met GD. Eenmalig lokaal draaien:
 *
 *   php -d extension_dir=C:\php\ext -d extension=gd tools/maak_iconen.php
 *
 * Ontwerp: donkere achtergrond, gouden tekstballon met een 'V'.
 */

declare(strict_types=1);

$uit = dirname(__DIR__) . '/assets';
$font = null;
// v0.3: extra vet — eerst de 'Black'-varianten proberen, dan pas Bold.
foreach (['C:/Windows/Fonts/seguibl.ttf', 'C:/Windows/Fonts/ariblk.ttf',
          'C:/Windows/Fonts/segoeuib.ttf', 'C:/Windows/Fonts/arialbd.ttf',
          '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'] as $f) {
    if (is_file($f)) { $font = $f; break; }
}
if ($font === null) { fwrite(STDERR, "Geen TTF-lettertype gevonden.\n"); exit(1); }

function maak(int $maat, string $pad, string $font, bool $maskable): void {
    $img = imagecreatetruecolor($maat, $maat);
    imagesavealpha($img, true);
    $bg   = imagecolorallocate($img, 15, 19, 25);      // #0f1319
    $goud = imagecolorallocate($img, 251, 188, 67);    // #fbbc43
    imagefill($img, 0, 0, $bg);

    // Maskable-iconen worden door Android rond of afgerond bijgesneden: houd
    // de ballon dan kleiner (binnen de 'safe zone' van 80%).
    // v0.3: nieuwe vorm — een bijna vierkant, royaal afgerond vlak waarvan
    // alleen de linkeronderhoek scherp is. Die scherpe hoek ís het
    // tekstballon-staartje; geen los driehoekje meer eronder.
    $schaal = $maskable ? 0.60 : 0.76;
    $b = (int)round($maat * $schaal);          // breedte
    $hgt = $b;                                 // vierkant
    $x = (int)(($maat - $b) / 2);
    $y = (int)(($maat - $hgt) / 2);
    $r = (int)round($b * 0.30);                // ruime hoekradius

    // Basis: twee rechthoeken die samen het kruis van de afgeronde vorm vormen
    imagefilledrectangle($img, $x + $r, $y, $x + $b - $r, $y + $hgt, $goud);
    imagefilledrectangle($img, $x, $y + $r, $x + $b, $y + $hgt - $r, $goud);
    // Drie ronde hoeken (linksboven, rechtsboven, rechtsonder)
    foreach ([[$x + $r, $y + $r], [$x + $b - $r, $y + $r], [$x + $b - $r, $y + $hgt - $r]] as [$cx, $cy]) {
        imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $goud);
    }
    // Linksonder: scherpe hoek — het vierkantje gewoon opvullen
    imagefilledrectangle($img, $x, $y + $hgt - $r, $x + $r, $y + $hgt, $goud);

    // De letter V, gecentreerd in de ballon (v0.3: groter en in de
    // Black-fontvariant). Omdat zelfs 'Black' op een klein icoon nog dun
    // oogt, tekenen we de letter meerdere keren met een mini-verschuiving
    // in een cirkeltje — dat dikt de lijnen aan alle kanten gelijk aan.
    $pt = $b * 0.56 * 0.75;                    // puntgrootte ~ pixels * 0.75
    $box = imagettfbbox($pt, 0, $font, 'V');
    $tw = $box[2] - $box[0];
    $th = $box[1] - $box[7];
    $tx = (int)($x + ($b - $tw) / 2 - $box[0]);
    $ty = (int)($y + ($hgt + $th) / 2 - $box[1] - $th * 0.06);
    $dik = max(1, (int)round($b * 0.025));     // extra lijndikte per kant
    for ($hoek = 0; $hoek < 360; $hoek += 30) {
        $dx = (int)round(cos(deg2rad($hoek)) * $dik);
        $dy = (int)round(sin(deg2rad($hoek)) * $dik);
        imagettftext($img, $pt, 0, $tx + $dx, $ty + $dy, $bg, $font, 'V');
    }
    imagettftext($img, $pt, 0, $tx, $ty, $bg, $font, 'V');

    imagepng($img, $pad, 6);
    echo "→ $pad\n";
}

maak(192, "$uit/icon-192.png", $font, false);
maak(512, "$uit/icon-512.png", $font, false);
maak(512, "$uit/icon-512-maskable.png", $font, true);
maak(180, "$uit/apple-touch-icon.png", $font, false);
