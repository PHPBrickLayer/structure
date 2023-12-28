<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\Brick;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\Domain;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;

class Make implements CmdLayout
{
    use Domain;
    use Brick;

    private EnginePlug $plug;
    private array $tags;
    private string $internal_dir;

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;
        $this->internal_dir = $this->plug->server->lay . "__internal" . $this->plug->s;

        $plug->add_arg($this, ["make:domain"], 'make_domain', 0, 1);
        $plug->add_arg($this, ["make:brick"], 'make_brick', 0, 1);
    }

    public function _spin(): void
    {
        $this->tags = $this->plug->tags;

        $this->brick();
        $this->domain();
    }

}