<?php

namespace BrickLayer\Lay\Libs\LayCrypt;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayCrypt\Enums\HashType;
use BrickLayer\Lay\Libs\LayCrypt\Enums\JwtError;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayFn;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;

class LayCrypt
{
    private static string $jwt_secret;

    /**
     * Hash a password using PHP's default password_hash method.
     *
     * @param string $password
     * @return string
     */
    public static function hash(string $password): string
    {
        return password_hash($password,PASSWORD_DEFAULT);
    }

    /**
     * Verify hashed password
     * @param string $plain_password
     * @param string $hashed_password
     * @return bool
     */
    public static function verify(string $plain_password, string $hashed_password) : bool
    {
        return password_verify($plain_password,$hashed_password);
    }
    /**
     * Encrypts and Decrypts
     *
     * @param string|null $string value to encrypt
     * @param bool $encrypt true [default]
     *
     * @return null|string
     */
    public static function basic(?string $string, bool $encrypt = true): string|null
    {
        if($string == null) return null;

        $salt = LayFn::env('LAY_CRYPT_SALT', LayConfig::app_id() ?? 'weak-salted-key');

        $layer = hash("sha512", "ukpato-" . $salt . "-nohaso");

        $encrypt_method = "AES-256-CBC";
        $key = hash( 'sha512', $layer);
        $iv = substr( hash( 'sha512', $salt ), 0, 16 );

        if($encrypt)
            $output = base64_encode(openssl_encrypt($string, $encrypt_method, $key, 0, $iv));
        else
            $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);

        if(!$output) $output = null;

        return $output;
    }

    public static function csrf_gen(string $user_data, ?string $key = null) : string
    {
        if(!$key)
            $key = LayFn::env('LAY_CSRF_KEY', LayConfig::get_project_identity() . '_csrf_gen');

        return hash_hmac('sha256', $user_data, $key);
    }

    private static function jwt_algo() : AlgorithmManager
    {
        return new AlgorithmManager([ new HS256() ]);
    }

    private static function gen_jwk() : JWK
    {
        return JWKFactory::createFromSecret(
            hash(
                'sha256',
                self::$jwt_secret ?? LayFn::env(
                'LAY_JWT_SECRET',
                LayConfig::get_project_identity()
            )
            )
        );
    }

    public static function set_jwt_secret(string $secret) : void
    {
        self::$jwt_secret = $secret;
    }

    public static function gen_jwt(
        ?array $payload = null,
        ?string $issuer = null,
        ?array $audience = null,
        string|int $expires = '60 seconds',
    ): string
    {
        $payload = [ 'data' => $payload ];
        $payload['iat'] = LayDate::now();
        $payload['exp'] = LayDate::unix($expires);
        $payload['iss'] = $issuer ?? LayConfig::site_data()->base_no_proto_no_www;
        $payload['nbf'] ??= $payload['iat'] - 50;

        if($audience)
            $payload['aud'] = $audience;

        return (new CompactSerializer())->serialize(
            (  new JWSBuilder( self::jwt_algo() )  )
                ->create()
                ->withPayload(json_encode($payload))
                ->addSignature(self::gen_jwk(), ["alg" => HashType::SHA256, "typ" => "JWT"])
                ->build(),
            0
        );
    }

    /**
     * @param string $jwt
     * @return array{
     *     valid: bool,
     *     why?: JwtError,
     *     message: string,
     *     data: ?array,
     * }
     */
    public static function verify_jwt(string $jwt): array
    {
        $jws = null;

        try {
            $jws = (new CompactSerializer())->unserialize($jwt);
        } catch (\Throwable $e) {
            LayException::log("", $e);
            return [
                "valid" => false,
                "why" => JwtError::INVALID_TOKEN,
                "message" => "Invalid token received!",
                "data" => null,
            ];
        }

        if (!(new JWSVerifier(self::jwt_algo()))->verifyWithKey($jws, self::gen_jwk(), 0))
            return [
                "valid" => false,
                "why" => JwtError::INVALID_TOKEN,
                "message" => "Invalid token!",
                "data" => null,
            ];

        $payload = json_decode($jws->getPayload(), true);

        if(!$payload)
            return [
                "valid" => false,
                "why" => JwtError::INVALID_PAYLOAD,
                "message" => "Invalid payload!",
                "data" => null,
            ];


        if (LayDate::expired($payload['exp']))
            return [
                "valid" => false,
                "why" => JwtError::EXPIRED,
                "message" => "Token has expired!",
                "data" => null,
            ];

        if (LayDate::greater($payload['nbf'], invert: true))
            return [
                "valid" => false,
                "why" => JwtError::NBF,
                "message" => "Token is not yet active!",
                "data" => null,
            ];

        return [
            "valid" => true,
            "message" => "Token valid!",
            "data" => $payload['data']
        ];
    }
}