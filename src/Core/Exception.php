<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core;

use Throwable;

abstract class Exception {
    /**
     * @throws \Exception
     */
    public static function throw_exception(string $message, string $title = "Generic", bool $kill = true, bool $use_lay_error = true, array $stack_track = [], ?Throwable $exception = null, bool $throw_500 = true, bool $error_as_json = true, ?array $json = null, bool $as_string = false, bool $ascii = true, bool $echo_error = true, array $opts = []) : ?array
    {
        return self::new()->use_exception($title, $message, $kill, trace: $stack_track, use_lay_error: $use_lay_error, opts: $opts, exception: $exception, throw_500: $throw_500, error_as_json: $error_as_json, json_packet: $json, return_as_string: $as_string, ascii: $ascii, echo_error: $echo_error);
    }

    public static function new() : CoreException
    {
        return CoreException::new();
    }

    /**
     * @throws \Exception
     */
    public static function kill_and_trace(bool $show_error = true) : void
    {
        self::new()->kill_with_trace($show_error);
    }

    public static function hide_x_info() : void
    {
        self::new()->hide_x_info();
    }

    public static function error_as_html() : void
    {
        self::new()::$ERROR_AS_HTML = true;
    }

    public static function always_log() : void
    {
        self::new()->log_always();
    }

    public static function log(mixed $message, Throwable $exception = null, string $log_title = "") : void
    {
        self::always_log();
        self::throw_exception(var_export($message, true), "ManualLog:$log_title", kill: false, exception: $exception, throw_500: false, error_as_json: false, echo_error: false, opts: ['type' => 'log']);
    }

    /**
     * Get the error message the Lay way
     * @param Throwable $exception
     * @param bool $add_ascii_char
     * @return string
     * @throws \Exception
     */
    public static function text(Throwable $exception, bool $add_ascii_char = true) : string
    {
        return self::throw_exception("", "Text Extraction", kill: false, exception: $exception, throw_500: false, as_string: true, ascii: $add_ascii_char, opts: ['type' => 'text'])['error'];
    }

}