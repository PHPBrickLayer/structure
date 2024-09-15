<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core;

abstract class Exception {
    /**
     * @throws \Exception
     */
    public static function throw_exception(string $message, string $title = "Generic", bool $kill = true, bool $use_lay_error = true, array $stack_track = [], ?\Throwable $exception = null, bool $throw_500 = true, bool $error_as_json = false, ?array $json = null, bool $as_string = false, bool $ascii = true) : ?array
    {
        return self::new()->use_exception($title, $message, $kill, trace: $stack_track, use_lay_error: $use_lay_error, exception: $exception, throw_500: $throw_500, error_as_json: $error_as_json, json_packet: $json, return_as_string: $as_string, ascii: $ascii);
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

    public static function always_log() : void
    {
        self::new()->log_always();
    }

    public static function log(string $message, $exception = null) : void
    {
        self::throw_exception($message, "ManualLog", kill: false, exception: $exception);
    }

    /**
     * Get the error message the Lay way
     * @param \Throwable $exception
     * @param bool $add_ascii_char
     * @return string
     * @throws \Exception
     */
    public static function text(\Throwable $exception, bool $add_ascii_char = true) : string
    {
        return self::throw_exception("", "Text Extraction", exception: $exception, as_string: true, ascii: $add_ascii_char)['error'];
    }

}