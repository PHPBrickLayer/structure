<?php

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\Exception;
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
     * Make a directory if it doesn't exist. Throws error if application doesn't have permission to access the location
     * @param string $directory
     * @param int $permission
     * @param bool $recursive
     * @param $context
     * @return bool
     * @throws \Exception
     */
    public static function mkdir(
        string $directory,
        int $permission = 0755,
        bool $recursive = false,
        $context = null
    ) : bool
    {
        if(!is_dir($directory)) {
            umask(0);
            if(!@mkdir($directory, $permission, $recursive, $context))
                Exception::throw_exception("Failed to create directory on location: ($directory); access denied; modify permissions and try again", "CouldNotMkDir");
        }

        return true;
    }

    public static function read_dir(string $directory, callable $action) : void
    {
        if(!is_dir($directory))
            Exception::throw_exception(
                "You are attempting to read a directory [$directory] that doesn't exist!",
                "DirDoesNotExist"
            );

        $dir = dir($directory);

        while (false !== ($entry = $dir->read())) {
            if($entry == "." || $entry == "..")
                continue;

            $action($entry, $directory);
        }
    }

}