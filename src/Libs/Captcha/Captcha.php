<?php

namespace BrickLayer\Lay\Libs\Captcha;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayFn;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWT;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
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

        $font = __DIR__ . "/font.ttf";

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
    public static function as_jwt(?string $captcha = null) : string
    {
        $secret = $_ENV['CAPTCHA_SECRET'] ?? LayConfig::get_project_identity();

        $payload = [
            "captcha" => $captcha ?? self::gen_code(),
            "expire" => (int) LayDate::date("60 seconds", figure: true),
            "issued" => LayDate::now(),
        ];

        $jwk = new JWK([
            "kty" => "oct",
            "k" => base64_encode($secret),
        ]);

        return (new CompactSerializer())->serialize(
            (  new JWSBuilder( new AlgorithmManager([ new HS256() ]) )  )
                ->create()
                ->withPayload(json_encode($payload))
                ->addSignature($jwk, ["alg" => "HS256"])
                ->build(),
            0
        );
    }

    public static function validate_as_jwt(string $jwt, string $captcha_value) : array
    {
        $secret = $_ENV['CAPTCHA_SECRET'] ?? LayConfig::get_project_identity();

        // The algorithm manager with the HS256 algorithm.
        $algo = new AlgorithmManager([ new HS256() ]);

        // Our key.
        $jwk = new JWK([
            'kty' => 'oct',
            'k' => base64_encode($secret),
        ]);

        // The JWS Verifier.
        $jwsVerifier = new JWSVerifier($algo);

        $serializer = new CompactSerializer();

        $jws = $serializer->unserialize($jwt);

        if (!$jwsVerifier->verifyWithKey($jws, $jwk, 0))
            return [
                "valid" => false,
                "message" => "Invalid token!",
            ];

        $payload = json_decode($jws->getPayload(), true);

        if (LayDate::expired($payload['exp']))
            return [
                "valid" => false,
                "message" => "Token has expired!",
            ];

        if ($payload['captcha'] !== $captcha_value)
            return [
                "valid" => false,
                "message" => "Invalid captcha value!",
            ];

        return [
            "valid" => true,
            "message" => "Valid captcha value!",
        ];
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