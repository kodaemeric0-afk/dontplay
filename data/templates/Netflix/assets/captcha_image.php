<?php
// Vérifier que GD est installé
if (!extension_loaded('gd')) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('GD extension not loaded');
}

session_start();

// Vérifier que l'ID est fourni
if (!isset($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    exit('Missing captcha ID');
}

// Vérifier que l'ID correspond
if (!isset($_SESSION['captcha_id']) || $_GET['id'] !== $_SESSION['captcha_id']) {
    header('HTTP/1.1 403 Forbidden');
    exit('Invalid captcha ID');
}

// Vérifier que les valeurs du captcha existent
if (!isset($_SESSION['captcha_resultat'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('No captcha data');
}

$result = $_SESSION['captcha_resultat'];

$valeur1 = rand(1, min(10, $result - 1));
$valeur2 = $result - $valeur1;

$width = 250;
$height = 80;
$image = imagecreate($width, $height);

// Vérifier que l'image a été créée
if (!$image) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Failed to create image');
}

$bg_color = imagecolorallocate($image, 255, 255, 255);

// Couleur de base : #1537AA (R=21, G=55, B=170)
$text_color = imagecolorallocate(
    $image,
    max(0, min(255, 21 + rand(-20, 20))),  // Rouge (variation légère)
    max(0, min(255, 55 + rand(-20, 20))),  // Vert (variation légère)
    max(0, min(255, 170 + rand(-25, 25)))  // Bleu (variation légère)
);

$line_color = imagecolorallocate($image, 200, 200, 200);
$noise_color = imagecolorallocate($image, 220, 220, 220);

imagefilledrectangle($image, 0, 0, $width, $height, $bg_color);

for ($i = 0; $i < 100; $i++) {
    imagesetpixel($image, rand(0, $width), rand(0, $height), $noise_color);
}

for ($i = 0; $i < 5; $i++) {
    imageline($image, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $line_color);
}

$text = "{$valeur1} + {$valeur2}    ?";

$font_size = 5; 

$text_width = imagefontwidth($font_size) * strlen($text);
$text_height = imagefontheight($font_size);
$x = ($width - $text_width) / 2 + rand(-5, 5);
$y = ($height - $text_height) / 2 + rand(-3, 3);

$shadow_color = imagecolorallocate($image, 200, 200, 200);
imagestring($image, $font_size, $x + 2, $y + 2, $text, $shadow_color);
imagestring($image, $font_size, $x, $y, $text, $text_color);

for ($i = 0; $i < 30; $i++) {
    imagesetpixel($image, rand(0, $width), rand(0, $height), $noise_color);
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Envoyer l'image
imagepng($image);
imagedestroy($image);
?>