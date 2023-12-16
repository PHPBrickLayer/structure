<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Enum\CmdOutType;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Libs\LayCopyDir;

class Make implements CmdLayout
{
    use IsSingleton;

    private readonly EnginePlug $plug;
    private readonly array $tags;
    public function _init(EnginePlug $plug) : void
    {
        $this->plug = $plug;
        $plug->add_arg($this, ["make:domain"], 'make_domain', 0);
    }

    public function _spin(): void
    {
        $this->tags = $this->plug->tags;

        $this->make();
    }

    public function make() : void
    {
        $domain = $this->tags['make_domain'][0] ?? null;

        if(!$domain)
            $this->plug->write_fail("No domain specified");



    }

}