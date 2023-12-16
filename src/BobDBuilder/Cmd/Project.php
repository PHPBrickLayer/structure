<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\Engine;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Enum\CmdOutType;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Libs\LayCopyDir;

class Project implements CmdLayout
{
    use IsSingleton;

    private readonly EnginePlug $plug;
    private readonly array $tags;

    public function _init(EnginePlug $plug) : void
    {
        $this->plug = $plug;
        $plug->add_arg($this, ["project:create"], 'project_create', true);
    }

    public function _spin(): void
    {
        if(!$this->plug->project_mode)
            return;

        $this->tags = $this->plug->tags;

        $this->create();
    }

    public function create() : void
    {
        $cmd = $this->tags['project:create'][0] ?? null;

        if(!$cmd)
            return;

        $server = $this->plug->server;

        // copy env file if it doesn't exist
        if(!file_exists($server->root . ".env"))
            copy($server->root . ".env.example", $server->root . ".env");

        // copy core lay js file to project lay folder
        new LayCopyDir($server->lay_static . "omjs", $server->shared . "lay");

        // copy helper js file to project lay folder
        copy(
            $server->lay_static . "js" . $this->plug->s . "constants.js",
            $server->shared . "lay" . $this->plug->s . "constants.js"
        );

        copy(
            $server->lay_static . "js" . $this->plug->s . "constants.min.js",
            $server->shared . "lay" . $this->plug->s . "constants.min.js"
        );
    }
}