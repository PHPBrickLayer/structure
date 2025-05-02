<?php

namespace BrickLayer\Lay\Libs\Cron;

use BrickLayer\Lay\Core\LayConfig;

final class JustOnce
{
    public static function yes(string $schedule, string $job, string $job_id) : string
    {
        return $schedule . " " . LayCron::php_bin() . " " . LayConfig::server_data()->root . "bob cron:once \"$job\" --id $job_id" . PHP_EOL;
    }
}