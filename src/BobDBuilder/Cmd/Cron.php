<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Libs\Cron\LayCron;
use BrickLayer\Lay\Libs\LayDate;
use Override;

final class Cron implements CmdLayout
{
    private EnginePlug $plug;

    #[Override]
    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;

        $plug->add_arg($this, ["cron:once"], 'cron_once', 0, 2, 3);
    }

    #[Override]
    public function _spin(): void
    {
        if (!isset($this->plug->tags['cron_once']))
            return;

        $this->once();
    }

    public function once() : void
    {
        $tags = $this->plug->tags['cron_once'];

        $job = $tags[0] ?? null;
        $id = $tags[1] ?? null;

        if(empty($id) || empty($job))
            $this->plug->write_warn(
                "Cron id is required for a 'once' cronjob, please use the *--id* tag\n" .
                "Example: php bob cron:once \"x/1 x x x x /usr/bin/php bob --help\" --id new-one-job"
            );

        // Unset the cronjob that triggered this function before executing
        LayCron::new()->unset($id);

        exec($job . " 2>&1 &", $out);

        LayCron::new()->log_output(
            "[" . LayDate::date() . "]\n" .
            implode("\n", $out)
        );
    }
}