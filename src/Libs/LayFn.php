<?php

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Core\View\Domain;
use JetBrains\PhpStorm\NoReturn;

final class LayFn
{
    private function __construct(){}
    private function __clone(){}

    public static function var_cache(string $key, callable $action, bool $invalidate = false) : mixed
    {
        if(LayConfig::$ENV_IS_DEV)
            $invalidate = true;

        $cache = LayCache::new()->cache_file("LAY_VAR_CACHE/$key.json");

        $old = $cache->read("store");

        if($old && !$invalidate)
            return $old;

        $data = $action();

        if(empty($data)) return $data;

        $cache->store("store", $data);

        return $data;
    }

    public static function get_var_cache(string $key, bool $throw_error = false) : mixed
    {
        $cache = LayCache::new()->cache_file("LAY_VAR_CACHE/$key.json");

        if($old = $cache->read("store")) return $old;

        if($throw_error)
            LayException::throw("Trying to access a key [$key] that doesn't exits in VAR_CACHE", "OutOfBoundVarCache");

        return null;
    }

    public static function num_format(?int $num, int $digits = 0, string $decimal_separator = ".", string $thousands_separator = ",") : string
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
        $num_format = number_format($num, $digits, $decimal_separator, $thousands_separator);

        return $item ? preg_replace ($regexp, '', $num_format) . ($item['symbol'] ?? '') : '0';
    }

    public static function trim_word(string $string, string $word, ?string $preg_pattern = null) : string
    {
        $len = /**
         * @psalm-return int<0, max>
         */
            function ($str): int {
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

    /**
     * Var dump in JSON format
     * @param array $data
     * @param bool $show_trace
     * @param bool $kill
     * @return void
     */
    public static function vd_json(array $data, bool $show_trace = true, bool $kill = true) : void
    {
        if($show_trace)
            $data['dump_trace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

        header("Content-Type: application/json");
        echo json_encode($data);

        if($kill) die;
    }

    #[NoReturn]
    /**
     * var_dump and die. But with CORS and all the important headers in place for better debugging across domains
     * @retrun void
     */
    public static function vd(mixed $value, mixed ...$values) : void
    {
        Domain::set_entries_from_file();

        self::header("Content-Type: text/html");
        LayConfig::call_lazy_cors();

        $message['dump'] = $value;
        $message['trace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        var_dump($message, ...$values);
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

    private static int $prev_http_code;

    public static function http_response_code(ApiStatus|int $code = 0, bool $overwrite = false) : int|bool
    {
        if(headers_sent($file, $line))
            LayException::log("Headers sent already in file [$file] line [$line]");

        if(!$overwrite) {
            self::$prev_http_code ??= http_response_code();
            return self::$prev_http_code;
        }

        if($code instanceof ApiStatus)
            $code = $code->value;

        self::$prev_http_code = $code;
        return http_response_code($code);
    }

    /**
     * @param mixed $value
     * @param int $flags [optional] <p>
     *  Bitmask consisting of <b>JSON_HEX_QUOT</b>,
     *  <b>JSON_HEX_TAG</b>,
     *  <b>JSON_HEX_AMP</b>,
     *  <b>JSON_HEX_APOS</b>,
     *  <b>JSON_NUMERIC_CHECK</b>,
     *  <b>JSON_PRETTY_PRINT</b>,
     *  <b>JSON_UNESCAPED_SLASHES</b>,
     *  <b>JSON_FORCE_OBJECT</b>,
     *  <b>JSON_UNESCAPED_UNICODE</b>.
     *  <b>JSON_THROW_ON_ERROR</b> The behaviour of these
     *  constants is described on
     *  the JSON constants page.
     *  </p>
     * @param int $depth
     * @return string|false
     *@see json_encode()
     */
    public static function json_encode(mixed $value, int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, int $depth = 512) : string|false
    {
        return json_encode($value, $flags, $depth);
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

        if(gettype($default) == "boolean")
            $_ENV[$key] = filter_var($_ENV[$key], FILTER_VALIDATE_BOOL);

        if(is_numeric($default))
            $_ENV[$key] = filter_var($_ENV[$key], FILTER_VALIDATE_INT);

        return $_ENV[$key];
    }
}