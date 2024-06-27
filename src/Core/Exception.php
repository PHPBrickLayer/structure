<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core;

abstract class Exception {
    /**
     * @throws \Exception
     */
    public static function throw_exception(string $message, string $title = "Generic", bool $kill = true, bool $use_lay_error = true, array $stack_track = [], $exception = null, bool $thow_500 = true) : void
    {
        self::new()->use_exception("LayExp_$title", $message, $kill, trace: $stack_track, use_lay_error: $use_lay_error, exception: $exception, throw_500: $thow_500);
    }

    public static function new() : \BrickLayer\Lay\Core\CoreException
    {
        return \BrickLayer\Lay\Core\CoreException::new();
    }

    /**
     * @throws \Exception
     */
    public static function kill_and_trace() : void
    {
        self::new()->kill_with_trace();
    }
}