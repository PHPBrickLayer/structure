<?php

namespace BrickLayer\Lay\Libs\Captcha;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayFn;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWT;
use Jose\Component\Core\Util\Base64UrlSafe;
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

    private static function gen_jwk() : JWK
    {
        $secret = hash('sha256', $_ENV['CAPTCHA_SECRET'] ?? LayConfig::get_project_identity(), true);

        return new JWK([
            "kty" => "oct",
            "k" => Base64UrlSafe::encodeUnpadded($secret),
        ]);
    }

    public static function as_img()
    {
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

        ob_start();
        imagepng($layer);
        $img = ob_get_clean();

        imagedestroy($layer);

        $img = "data:image/png;base64," . base64_encode($img);

        return [
            "img" => $img,
            "jwt"  => self::as_jwt($code)
        ];
    }

    /**
     * @throws RandomException
     */
    public static function as_jwt(?string $captcha = null) : string
    {
        $payload = [
            "captcha" => $captcha ?? self::gen_code(),
            "expire" => (int) LayDate::date("60 seconds", figure: true),
            "issued" => LayDate::now(),
        ];


        return (new CompactSerializer())->serialize(
            (  new JWSBuilder( new AlgorithmManager([ new HS256() ]) )  )
                ->create()
                ->withPayload(json_encode($payload))
                ->addSignature(self::gen_jwk(), ["alg" => "HS256"])
                ->build(),
            0
        );
    }

    public static function validate_as_jwt(string $jwt, string $captcha_value) : array
    {
        // The algorithm manager with the HS256 algorithm.
        $algo = new AlgorithmManager([ new HS256() ]);

        // The JWS Verifier.
        $jwsVerifier = new JWSVerifier($algo);

        $serializer = new CompactSerializer();

        $jws = $serializer->unserialize($jwt);

        if (!$jwsVerifier->verifyWithKey($jws, self::gen_jwk(), 0))
            return [
                "valid" => false,
                "message" => "Invalid token!",
            ];

        $payload = json_decode($jws->getPayload(), true);

        if (LayDate::expired($payload['expire']))
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