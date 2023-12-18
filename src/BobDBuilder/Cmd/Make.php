<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\Brick;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\Domain;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\Traits\IsSingleton;

class Make implements CmdLayout
{
    use IsSingleton;

    use Domain;
    use Brick;

    private readonly EnginePlug $plug;
    private readonly array $tags;
    private readonly string $internal_dir;

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;
        $this->internal_dir = $this->plug->server->lay . "__internal" . $this->plug->s;

        $plug->add_arg($this, ["make:domain"], 'make_domain', 0, 1);
    }

    public function _spin(): void
    {
        $this->tags = $this->plug->tags;

        $this->domain();
    }

}