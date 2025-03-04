<?php

namespace BrickLayer\Lay\Libs\Captcha;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayFn;
use Random\RandomException;
use ReallySimpleJWT\Token;

class Captcha
{
    /**
     * @throws RandomException
     */
    private static function gen_code() : string
    {
        $random_num    = md5(random_bytes(64));
        return substr($random_num, 0, 6);
    }

    public static function as_img() : void
    {
        LayFn::header("Content-Type: image/png");

        $code  = self::gen_code();

        $font = "./font.ttf";

        $_SESSION['LAY_CAPTCHA_CODE'] = $code;

        $layer = imagecreatetruecolor(90, 50);

        imagealphablending($layer, TRUE);
        imagesavealpha($layer, true);

        $white = imagecolorallocate($layer, 255, 255, 255);
        $black = imagecolorallocate($layer, 0, 0, 0);
        $transparent = imagecolorallocatealpha($layer, 255, 255, 255, 127);

        imagefill($layer, 0, 0, $transparent);

        imagettftext($layer, 26, 2, 2, 39, $black, $font, $code);

        imagettftext($layer, 26, 2, 1, 39, $white, $font, $code);

        imagepng($layer);
        imagedestroy($layer);

    }

    /**
     * @throws RandomException
     */
    public static function as_jwt() : string
    {
        return Token::create(
            self::gen_code(),
            $_ENV['CAPTCHA_SECRET'] ?? $_COOKIE["PHPSESSID"] ?? LayConfig::get_project_identity(),
            (int) LayDate::date("5 minutes", figure: true),
            "server"
        );
    }

    public static function validate_as_jwt(string $token) : bool
    {
        return Token::validate(
            $token,
            $_ENV['CAPTCHA_SECRET'] ?? $_COOKIE["PHPSESSID"] ?? LayConfig::get_project_identity(),
        );
    }

    public static function validate_as_session(string $value) : bool
    {
        if (!isset($_SESSION["LAY_CAPTCHA_CODE"]))
            return false;

        if ($value == $_SESSION["LAY_CAPTCHA_CODE"])
            return true;

        return false;
    }

}