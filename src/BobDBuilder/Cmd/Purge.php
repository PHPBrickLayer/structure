<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Purge\AutoDeploy;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Purge\Brick;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Purge\Domain;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Purge\StaticProd;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;

class Purge implements CmdLayout
{
    use Domain;
    use Brick;
    use AutoDeploy;
    use StaticProd;

    private EnginePlug $plug;
    private array $tags;
    private string $internal_dir;

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;
        $this->internal_dir = $this->plug->server->lay . "__internal" . $this->plug->s;

        $plug->add_arg($this, ["purge:domain"], 'purge_domain', 0);
        $plug->add_arg($this, ["purge:brick"], 'purge_brick', 0);
        $plug->add_arg($this, ["purge:auto_deploy"], 'purge_auto_deploy', 0);
        $plug->add_arg($this, ["purge:static_prod"], 'purge_static_prod', 0);
    }

    private function talk(string $msg) : void
    {
        $this->plug->write_talk($msg, ['silent' => true]);
    }

    public function _spin(): void
    {
        $this->tags = $this->plug->tags;

        $this->brick();
        $this->domain();
        $this->auto_deploy();
        $this->static_prod();
    }

}