<?php

namespace BrickLayer\Lay\Libs\Captcha;

use BrickLayer\Lay\Libs\LayCrypt\LayCrypt;
use BrickLayer\Lay\Libs\LayFn;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Random\RandomException;

final class Captcha
{
    /**
     * @throws RandomException
     */
    private static function gen_code() : string
    {
        $random_num    = md5(random_bytes(64));
        return substr($random_num, 0, 6);
    }

    /**
     * Return a base 64 encoded captcha image stored in a session called `LAY_CAPTCHA_CODE`
     * @param string|null $code
     * @param bool $set_header
     * @return null|string
     * @throws RandomException
     * @throws \ImagickDrawException
     * @throws \ImagickException
     */
    public static function as_img(?string $code = null, bool $set_header = false) : string|null
    {
        $code ??= self::gen_code();

        $_SESSION['LAY_CAPTCHA_CODE'] = $code;
        $width = 100;
        $height = 55;

        $image = new Imagick();
        $image->newImage($width, $height, new ImagickPixel('transparent'));
        $image->setImageFormat('png');

        $draw = new ImagickDraw();
        $draw->setFont(__DIR__ . "/font.ttf");
        $draw->setFontSize(30);
        $draw->setFillColor(new ImagickPixel('white'));
        $draw->setTextAntialias(true);

        $metrics = $image->queryFontMetrics($draw, $code);
        $textX = ($width - $metrics['textWidth']) / 2 - 5;
        $textY = ($height + $metrics['textHeight']) / 2 - 15;

        // Shadow
        $shadow = clone $draw;
        $shadow->setFillColor(new ImagickPixel('black'));
        $image->annotateImage($shadow, $textX - 1, $textY - 1, 0, $code); // White shadow

        // Actual text
        $image->annotateImage($draw, $textX, $textY, 0, $code); // black foreground

        // Add noise (random dots)
        $noise = new ImagickDraw();
        for ($i = 0; $i < 150; $i++) {
            $noise->setFillColor(new ImagickPixel(sprintf('#%06X', mt_rand(0, 0xFFFFFF))));
            $x = mt_rand(0, $width);
            $y = mt_rand(0, $height);
            $noise->point($x, $y);
        }
        $image->drawImage($noise);

        if($set_header) {
            header("Content-Type: image/png");
            echo $image;
            $image->clear();
            return null;
        }

        $data = $image->getImageBlob();
        $image->clear();

        return "data:image/png;base64," . base64_encode($data);
    }

    /**
     * Return an array with a base64 image and a JWT token
     * @return array{
     *     img: ?string,
     *     jwt: string,
     * }
     * @throws RandomException
     * @throws \ImagickDrawException
     * @throws \ImagickException
     */
    public static function as_img_jwt() : array
    {
        $code = self::gen_code();

        LayCrypt::set_jwt_secret(LayFn::env('CAPTCHA_SECRET'));

        return [
            "img" => self::as_img($code),
            "jwt"  => LayCrypt::gen_jwt([
                "captcha" => $code,
            ])
        ];
    }

    /**
     * @param string $jwt
     * @param string $captcha_value
     *
     * @return (array|bool|null|string)[]
     *
     * @psalm-return array{valid: bool, message: string, data?: array|null}
     */
    public static function validate_as_jwt(string $jwt, string $captcha_value) : array
    {
        LayCrypt::set_jwt_secret(LayFn::env('CAPTCHA_SECRET'));

        $test = LayCrypt::verify_jwt($jwt);

        if(!$test['valid'])
            return $test;

        if ($test['data']['captcha'] !== $captcha_value)
            return [
                "valid" => false,
                "message" => "Invalid captcha value!",
            ];

        return [
            "valid" => true,
            "message" => "Valid captcha value!",
        ];
    }

    /**
     * @param string $value
     *
     * @return (bool|string)[]
     *
     * @psalm-return array{valid: bool, message: 'Captcha value is not set'|'Invalid captcha received!'|'Valid captcha value!'}
     */
    public static function validate_as_session(string $value) : array
    {
        if (!isset($_SESSION["LAY_CAPTCHA_CODE"]))
            return [
                "valid" => false,
                "message" => "Captcha value is not set",
            ];

        if ($value == $_SESSION["LAY_CAPTCHA_CODE"])
            return [
                "valid" => true,
                "message" => "Valid captcha value!",
            ];

        return [
            "valid" => false,
            "message" => "Invalid captcha received!",
        ];
    }

}