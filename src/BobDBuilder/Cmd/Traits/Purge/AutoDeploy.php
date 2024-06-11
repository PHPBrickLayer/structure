<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Purge;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\Libs\ID\Gen;
use BrickLayer\Lay\Libs\LayDir;


trait AutoDeploy
{
    public function auto_deploy(): void
    {
        if(!isset($this->tags['purge_auto_deploy']))
            return;

        $domain = "GitAutoDeploy";

        $domain_dir = $this->plug->server->domains . $domain;
        $exists = is_dir($domain_dir);

        if (!$exists)
            $this->plug->write_fail(
                "Git auto deploy domain directory *$domain_dir* does not exists!\n"
                . "You may have purged it already"
            );

        LayDir::unlink($domain_dir);
        new BobExec("link:prune --silent");
    }
}