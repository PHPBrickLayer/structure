<?php
declare(strict_types=1);

if (!isset($_SESSION))
    session_start();

header("Content-Type: image/png");

// Generate captcha code
$random_num    = md5(random_bytes(64));
$code  = substr($random_num, 0, 6);

$font = "./font.ttf";

// Assign captcha in session
$_SESSION['CAPTCHA_CODE'] = $code;

// Create image
$layer = imagecreatetruecolor(90, 50);

imagealphablending($layer, TRUE);
imagesavealpha($layer, true);

$white = imagecolorallocate($layer, 255, 255, 255);
$grey = imagecolorallocate($layer, 128, 128, 128);
$black = imagecolorallocate($layer, 0, 0, 0);
$transparent = imagecolorallocatealpha($layer, 255, 255, 255, 127);

// Make background transparent
imagefill($layer, 0, 0, $transparent);

// text
imagettftext($layer, 26, 2, 2, 39, $black, $font, $code);
//// text shadow
imagettftext($layer, 26, 2, 1, 39, $white, $font, $code);

imagepng($layer);
imagedestroy($layer);
