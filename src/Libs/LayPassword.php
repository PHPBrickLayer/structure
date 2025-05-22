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
        return parent::basic($string, $encrypt);
    }
}