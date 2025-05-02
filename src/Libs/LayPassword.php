<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayCrypt\LayCrypt;

/**
 * Password Encrypt Class for basic hashing
 * @deprecated use LayCrypt
*/
abstract class LayPassword extends LayCrypt {
    /**
     * Encrypts and Decrypts
     *
     * @param string|null $string value to encrypt
     * @param bool $encrypt true [default]
     *
     * @return null|string
     *
     * @deprecated use `LayCrypt::basic`
     */
    public static function crypt(?string $string, bool $encrypt = true): string|null {
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
}