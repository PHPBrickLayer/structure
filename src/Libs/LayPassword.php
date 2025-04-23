<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;

/**
 * Password Encrypt Class for basic hashing
*/
abstract class LayPassword {

    /**
     * Has a password using PHP's default password_hash method.
     * ## Please don't use this method for verification, call the `verify` method instead.
     * If a `$hashed_password` is null, this method will return a new password instead of returning false
     *
     * @param string $password
     * @param string|null $hashed_password
     * @return string|bool
     */
    public static function hash(string $password, ?string $hashed_password = null): string|bool {
        if(is_null($hashed_password))
            return password_hash($password,PASSWORD_DEFAULT);

        LayException::log("You are verifying password the wrong way");
        return password_verify($password, $hashed_password);
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
     * @return string|null
     */
    public static function crypt(?string $string, bool $encrypt = true): ?string {
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

    public static function csrf_gen(string $user_data, ?string $key = null) : string {
        $key = $key === null ? date("YmdHis") : $key;
        return hash_hmac('sha256', $user_data, $key);
    }
}