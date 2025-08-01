<?php

namespace BrickLayer\Lay\Libs\ID;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use BrickLayer\Lay\Orm\SQL;
use Closure;
use Random\RandomException;

final class Gen
{
    use IsSingleton;

    private static int $recursion_index = 0;

    private static string $prepend;
    private static string $append;
    private static int $digit_length = 7;
    private static string $confirm_table;
    private static string $confirm_column;
    private static ?Closure $more_query;

    /**
     * Generate a simple UUID
     * @param int $length
     * @return string
     * @throws \Exception
     */
    public static function uuid(int $length = 13): string
    {
        try {
            if (function_exists("random_bytes"))
                $bytes = random_bytes(ceil($length / 2));

            elseif (function_exists("openssl_random_pseudo_bytes"))
                $bytes = openssl_random_pseudo_bytes(ceil($length / 2));
        } catch (\Exception $e) {
            Exception::throw_exception("", "UUIDError", exception: $e);
        }

        if(!isset($bytes))
            Exception::throw_exception("openssl_random_pseudo_bytes or random_bytes doesn't exist!", "NoCryptoFunc");

        return substr(bin2hex($bytes), 0, $length);
    }

    /**
     * This method is responsible for checking if the generated token exists in the database
     * @param string $table
     * @param string $column
     * @param string $value
     * @return bool
     */
    protected static function token_exists(string $table, string $column, string $value) : bool
    {
        $value = Escape::clean($value,EscapeType::STRIP_TRIM_ESCAPE, [ "strict" => true ]);

        $db = LayConfig::get_orm()->open($table)->where($column, "$value");

        if(isset(self::$more_query))
            (self::$more_query)($db);

        return $db->count() > 0;
    }

    /**
     * length of the result of a [Generator Function]. Default is 7
     * @param int $length
     * @return $this
     */
    public function digit(int $length = 7) : self {
        self::$digit_length = $length;
        return $this;
    }

    /**
     * an alias for digit()
     * @param int $length
     * @return $this
     * @see digit()
     */
    public function length(int $length) : self
    {
        return $this->digit($length);
    }

    /**
     * String to prepend to the generated result of a [Generator Function]
     * @param string|null $string
     * @return $this
     */
    public function prepend(?string $string = null) : self {
        self::$prepend = $string;
        return $this;
    }

    /**
     * String to append to the generated result of a [Generator Function]
     * @param string|null $string
     * @return $this
     */
    public function append(?string $string = null) : self {
        self::$append = $string;
        return $this;
    }

    /**
     * Use this to check if the result generated by a [Generator Function] exits already.
     * If it does, it will instruct the generator to come up with a new token
     *
     * @param string $confirm_table
     * @param string $confirm_column
     * @return $this
     */
    public function db_confirm(string $confirm_table, string $confirm_column, ?callable $more = null) : self {
        self::$confirm_table = $confirm_table;
        self::$confirm_column = $confirm_column;

        if($more)
            self::$more_query = $more;

        return $this;
    }

    /**
     * [Generator Function] Generate random numbers by default.
     *
     *
     * However, you can use the other methods like `db_confirm`, `prepend`, etc, to tweak the result to your use case
     * @return string|null
     */
    public function gen() : ?string{
        self::$recursion_index++;

        $pre = self::$prepend ?? null;
        $end = self::$append ?? null;
        $length = self::$digit_length != 0 ?  self::$digit_length - 1 : 0;
        $table = self::$confirm_table ?? null;
        $column = self::$confirm_column ?? null;

        if(self::$recursion_index > 5) {
            self::$recursion_index = 1;
            self::$digit_length++;
            $length++;

            LayException::log(
                "While generating the last token, the system had to increase the token length because every possible combination already exists on your database. 
                Please manually increase the digit length using the `->length()` method, beyond the current length [$length] for optimum token generation",
                log_title: "Gen::Info"
            );
        }

        $min = 10 ** $length;
        $rand = rand($min, 9 * $min);

        if($pre)
            $rand = $pre . $rand;
        if($end)
            $rand = $rand . $end;

        $rand .= "";

        if($table && $column && self::token_exists($table, $column, $rand))
            return $this->digit($length)->prepend($pre)->append($end)->db_confirm($table, $column)->gen();

        return $rand;
    }

    /**
     * alias for gen())
     * @return string|null
     * @see gen()
     */
    public function token() : ?string
    {
        return $this->gen();
    }

    /**
     * [Generator Function] Generate random alphabets by default
     *
     * However, you can use the other methods like `db_confirm`, `prepend`, etc, to tweak the result to your use case
     * @param string|null ...$remove_chars
     * @return string|null
     * @throws \Random\RandomException
     */
    public function string(?string ...$remove_chars) : ?string {
        self::$recursion_index++;

        $length = self::$digit_length;
        $table = self::$confirm_table ?? null;
        $column = self::$confirm_column ?? null;
        $pre = self::$prepend ?? null;
        $end = self::$append ?? null;

        if(self::$recursion_index > 5) {
            self::$recursion_index = 1;
            self::$digit_length++;
            $length++;

            LayException::log(
                "While generating the last token, the system had to increase the token length because every possible combination already exists on your database. 
                Please manually increase the digit length using the `->length()` method, beyond the current length [$length] for optimum token generation",
                log_title: "Gen::Info"
            );
        }

        $rand = str_replace($remove_chars, '', base64_encode($pre . md5(time() . "") . random_bytes($length) . $end));
        $rand = substr($rand,0,$length);

        if($table && $column && self::token_exists($table,$column,$rand))
            return $this->string(...$remove_chars);

        return $rand;
    }

    /**
     * An alias for `$this->string`
     * @param string|null ...$remove_chars
     * @return string|null
     * @throws RandomException
     * @see string()
     */
    public function letters(?string ...$remove_chars) : ?string
    {
        return $this->string(...$remove_chars);
    }

    public function slug(string $text) : string
    {
        self::$recursion_index++;

        $table = self::$confirm_table ?? null;
        $column = self::$confirm_column ?? null;

        $slug = Escape::clean($text, EscapeType::P_URL);

        if($table && $column && self::token_exists($table, $column, $text)) {
            $rand = explode("-", SQL::new()->uuid());
            $rand = trim(end($rand));
            return $this->slug($text . "-" . $rand);
        }

        return $slug;
    }

}