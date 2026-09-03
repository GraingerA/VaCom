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
foreach (['C:/Windows/Fonts/segoeuib.ttf', 'C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'] as $f) {
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
    $schaal = $maskable ? 0.58 : 0.72;
    $b = (int)round($maat * $schaal);          // ballonbreedte
    $hgt = (int)round($b * 0.82);              // ballonhoogte
    $x = (int)(($maat - $b) / 2);
    $y = (int)(($maat - $hgt) / 2) - (int)($maat * 0.04);
    $r = (int)round($b * 0.22);                // hoekradius

    // Afgeronde rechthoek
    imagefilledrectangle($img, $x + $r, $y, $x + $b - $r, $y + $hgt, $goud);
    imagefilledrectangle($img, $x, $y + $r, $x + $b, $y + $hgt - $r, $goud);
    foreach ([[$x + $r, $y + $r], [$x + $b - $r, $y + $r], [$x + $r, $y + $hgt - $r], [$x + $b - $r, $y + $hgt - $r]] as [$cx, $cy]) {
        imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $goud);
    }
    // Staartje linksonder
    $s = (int)round($b * 0.16);
    imagefilledpolygon($img, [
        $x + (int)($b * 0.18), $y + $hgt - 2,
        $x + (int)($b * 0.18), $y + $hgt + $s,
        $x + (int)($b * 0.18) + $s + (int)($s * 0.6), $y + $hgt - 2,
    ], $goud);

    // De letter w, gecentreerd in de ballon
    $pt = $b * 0.52 * 0.75;                    // puntgrootte ~ pixels * 0.75
    $box = imagettfbbox($pt, 0, $font, 'V');
    $tw = $box[2] - $box[0];
    $th = $box[1] - $box[7];
    $tx = (int)($x + ($b - $tw) / 2 - $box[0]);
    $ty = (int)($y + ($hgt + $th) / 2 - $box[1] - $th * 0.06);
    imagettftext($img, $pt, 0, $tx, $ty, $bg, $font, 'V');

    imagepng($img, $pad, 6);
    echo "→ $pad\n";
}

maak(192, "$uit/icon-192.png", $font, false);
maak(512, "$uit/icon-512.png", $font, false);
maak(512, "$uit/icon-512-maskable.png", $font, true);
maak(180, "$uit/apple-touch-icon.png", $font, false);
