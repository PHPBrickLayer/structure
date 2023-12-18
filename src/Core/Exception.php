<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core;

abstract class Exception {
    /**
     * @throws \Exception
     */
    public static function throw_exception(string $message, string $title = "Generic", bool $kill = true, bool $use_lay_error = true, array $stack_track = []) : void
    {
        self::new()->use_exception("LayExp_$title", $message, $kill, trace: $stack_track, use_lay_error: $use_lay_error);
    }

    public static function new() : \BrickLayer\Lay\Core\CoreException
    {
        return \BrickLayer\Lay\Core\CoreException::new();
    }
}