<?php

namespace BrickLayer\Lay\Libs\ID;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;

class Gen
{
    use IsSingleton;
    private static int $recursion_index = 0;

    private static string $prepend;
    private static string $append;
    private static int $digit_length = 7;
    private static string $confirm_table;
    private static string $confirm_column;

    /**
     * @throws \Exception
     */
    public static function uuid($length = 13): string
    {
        if (function_exists("random_bytes"))
            $bytes = random_bytes(ceil($length / 2));

        elseif (function_exists("openssl_random_pseudo_bytes"))
            $bytes = openssl_random_pseudo_bytes(ceil($length / 2));

        if(!isset($bytes))
            Exception::throw_exception("openssl_random_pseudo_bytes or random_bytes doesn't exist!", "NoCryptoFunc");

        return substr(bin2hex($bytes), 0, $length);
    }

    protected static function count(string $table, string $column, $value) : bool {
        $orm = LayConfig::get_orm();
        $value = $orm->clean($value,16,'strict');
        return $orm->open($table)->count_row($column,"$column='$value'") > 0;
    }

    public function digit(?int $digit_length = 7) : self {
        self::$digit_length = $digit_length;
        return $this;
    }

    public function length(int $length) : self
    {
        return $this->digit($length);
    }

    public function prepend(?string $string = null) : self {
        self::$prepend = $string;
        return $this;
    }

    public function append(?string $string = null) : self {
        self::$append = $string;
        return $this;
    }

    public function db_confirm(string $confirm_table, string $confirm_column) : self {
        self::$confirm_table = $confirm_table;
        self::$confirm_column = $confirm_column;
        return $this;
    }

    public function gen() : ?string{
        self::$recursion_index++;

        $pre = self::$prepend ?? null;
        $end = self::$append ?? null;
        $length = self::$digit_length != 0 ?  self::$digit_length - 1 : 0;
        $table = self::$confirm_table ?? null;
        $column = self::$confirm_column ?? null;

        if(self::$recursion_index > 10)
            $length++;

        $min = 10 ** $length;
        $rand = rand($min, 9 * $min);

        if($pre)
            $rand = $pre . $rand;
        if($end)
            $rand = $rand . $end;

        if($table && $column && self::count($table,$column,$rand))
            return $this->digit($length)->prepend($pre)->append($end)->db_confirm($table, $column)->gen();

        return $rand . "";
    }

    public function string(?string ...$remove_chars) : ?string {
        self::$recursion_index++;
        $length = self::$digit_length;
        $table = self::$confirm_table ?? null;
        $column = self::$confirm_column ?? null;
        $pre = self::$prepend ?? null;
        $end = self::$append ?? null;

        if(self::$recursion_index > 10)
            $length++;

        $rand = str_replace($remove_chars, '', base64_encode($pre . md5(time() . "") . random_bytes($length) . $end));
        $rand = substr($rand,0,$length);

        if($table && $column && self::count($table,$column,$rand))
            return $this->string(...$remove_chars);

        return $rand;
    }

}