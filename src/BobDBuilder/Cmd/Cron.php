<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Libs\Cron\LayCron;

class Cron implements CmdLayout
{
    private EnginePlug $plug;

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;

        $plug->add_arg($this, ["cron:once"], 'cron_once', 0, 2, 3);
    }

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
        $dont_schedule = @$tags[2] == '--now';

        if(empty($id) || empty($job))
            $this->plug->write_warn(
                "Cron id is required for a 'once' cronjob, please use the *--id* tag\n" .
                "Example: php bob cron:once \"x/1 x x x x /usr/bin/php bob --help\" --id new-one-job"
            );

        if($dont_schedule)
            shell_exec($job); // execute the job instantly
        else
            LayCron::new()->job_id($id)->exec($job); // Execute raw cron job based on the schedule passed

        LayCron::new()->unset($id); // unset cron job after executing, if the id is saved
    }

}