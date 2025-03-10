<?php

namespace BrickLayer\Lay\Libs;

use JetBrains\PhpStorm\NoReturn;

final class LayFn
{
    private function __construct(){}
    private function __clone(){}

    public static function num_format(?int $num, int $digits) : string
    {
        if($num == null || $num == 0)
            return "0";

        $lookup = [
            ['value' => 1, 'symbol' => ''],
            ['value' => 1e3, 'symbol' => 'k'],
            ['value' => 1e6, 'symbol' => 'M'],
            ['value' => 1e9, 'symbol' => 'G'],
            ['value' => 1e12, 'symbol' => 'T'],
            ['value' => 1e15, 'symbol' => 'P'],
            ['value' => 1e18, 'symbol' => 'E']
        ];
        $regexp = '/\.0+$|(\.[0-9]*[1-9])0+$/';
        $item = null;

        foreach (array_reverse($lookup) as $val) {
            if ($num >= $val['value']) {
                $item = $val;
                break;
            }
        }

        $num = $item ? $num/$item['value'] : $num;

        return $item ? preg_replace (
                $regexp,
                '',
                number_format($num, $digits)
            ) . $item['symbol'] : '0';
    }

    public static function trim_word(string $string, string $word, ?string $preg_pattern = null) : string
    {
        $len = function ($str) {
            if (function_exists("mb_strlen"))
                return mb_strlen($str);

            return strlen($str);
        };

        if($len($word) < 2)
            return trim($string, $word);

        $word = preg_quote($word, '~');
        $pattern = $preg_pattern ?? '~^(' . $word . ')|(' . $word . ')$~';

        $word = preg_replace($pattern, "", $string);

        return $word ?? $string;
    }

    public static function ltrim_word(string $string, string $word) : string
    {
        return self::trim_word($string, $word, '~^(' . preg_quote($word, '~') . ')~');
    }

    public static function rtrim_word(string $string, string $word) : string
    {
        return self::trim_word($string, $word, '~(' . preg_quote($word,'~') . ')$~');
    }

    #[NoReturn]
    public static function dump_json(array $data, bool $show_trace = true) : void
    {
        if($show_trace)
            $data['dump_trace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

        header("Content-Type: application/json");
        echo json_encode($data);
        die;
    }

    /**
     * Extract the arguments passed to a script through the cli
     *
     * @param string $key
     * @param bool $has_value when true, it means the tag has a value. Ex: --job-uid eec3ds-d2dc-ddd.
     * Else: --invalidate-cache
     *
     * @return string|bool|int|null
     */
    public static function extract_cli_tag(string $key, bool $has_value, ?string $argument = null): string|null|bool|int
    {
        $arg_values = $GLOBALS['argv'] ?? null;

        if($argument)
            $arg_values = explode(" ", $argument);

        $tag_key = array_search($key, $arg_values);
        $value = null;

        if ($tag_key !== false)
            $value = $has_value ? $arg_values[$tag_key + 1] : true;

        return $value;
    }

    public static function header(string $header, bool $replace = true, int $response_code = 0) : void
    {
        if(headers_sent())
            return;

        header($header, $replace, $response_code);
    }

    /**
     * Extract the word from a string using regex
     * @param string $pattern
     * @param string $subject
     * @return array|null
     */
    public static function extract_word(string $pattern, string $subject) : ?array
    {
        $pattern = '~' . $pattern . '~';

        preg_match($pattern, $subject, $out);

        if(empty($out))
            return null;

        return $out;
    }

    /**
     * Checks if a string starts with http or https
     * @param string|null $string
     * @return bool
     */
    public static function is_str_http(?string $string) : bool
    {
        if($string === null) return false;

        return str_starts_with($string, "http");
    }

    /**
     * Update an array in multiple dimensions, using an array of keys
     *
     * @param array $key_chain
     * @param mixed $value
     * @param array $array_to_update
     * @return void
     */
    public static function recursive_array_update(array $key_chain, mixed $value, array &$array_to_update) : void
    {
        $current_key = array_shift($key_chain);

        if (empty($key_chain)) {
            $array_to_update[$current_key] = $value;
            return;
        }

        if (!isset($array_to_update[$current_key]) || !is_array($array_to_update[$current_key])) {
            $array_to_update[$current_key] = [];
        }

        // Recurse to the next level
        self::recursive_array_update($key_chain, $value, $array_to_update[$current_key]);
    }

    /**
     * Return environmental variable
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function env(string $key, mixed $default = null) : mixed
    {
        $key = strtoupper($key);

        $_ENV[$key] ??= $default;

        return $_ENV[$key];
    }
}