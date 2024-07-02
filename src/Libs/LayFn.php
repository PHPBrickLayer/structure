<?php

namespace BrickLayer\Lay\Libs;

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

}