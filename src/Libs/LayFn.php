<?php

namespace BrickLayer\Lay\Libs;

use JetBrains\PhpStorm\NoReturn;

final class LayFn
{
    private function __construct(){}
    private function __clone(){}

    public static function num_format(?int $num, int $digits) : string
    {
        if(!$num)
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

        $pattern = $preg_pattern ?? '~^(' . $word . ')|(' . $word . ')$~';
        return preg_replace($pattern, "", $string);
    }

    public static function ltrim_word(string $string, string $word) : string
    {
        return self::trim_word($string, $word, '~^(' . $word . ')~');
    }

    public static function rtrim_word(string $string, string $word) : string
    {
        return self::trim_word($string, $word, '~(' . $word . ')$~');
    }

    #[NoReturn] public static function dump_json(array $data) : void
    {
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

}