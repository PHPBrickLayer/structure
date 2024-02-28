<?php

namespace BrickLayer\Lay\Libs\Cron;

class JustOnce
{
    public static function yes(string $schedule, string $job, string $job_id) : string
    {
        return $schedule . " " . LayCron::PHP_BIN . " bob cron:once \"$job\" --id $job_id --now";
    }
}