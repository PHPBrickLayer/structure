<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Libs\LayCopyDir;

class Project implements CmdLayout
{
    use IsSingleton;

    private EnginePlug $plug;
    private array $tags;

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;
        $plug->add_arg($this, ["project:create"], 'project_create', true, 0);
    }

    public function _spin(): void
    {
        if (!$this->plug->project_mode)
            return;

        $this->tags = $this->plug->tags;

        $this->create();
    }

    public function create(): void
    {
        $cmd = $this->tags['project_create'][0] ?? null;
        $tag = $this->tags['project_create'][1] ?? "";

        if (!$cmd)
            return;

        $tag = trim($tag);

        $server = $this->plug->server;

        // copy env file if it doesn't exist
        if (!file_exists($server->root . ".env"))
            copy($server->root . ".env.example", $server->root . ".env");

        // copy core lay js file to project lay folder
        new LayCopyDir($server->lay_static . "omjs", $server->shared . "lay");

        // copy helper js file to project lay folder
        new LayCopyDir($server->lay_static . "js", $server->shared . "lay");

        if($tag == "--fresh-project")
            $this->plug->write_info("Fresh project detected!");

        // create a default domain folder if not exists
        if(!is_dir($this->plug->server->domains . "Default"))
            new BobExec("make:domain Default * --silent");

        // link lay folder to default folder
        $s = DIRECTORY_SEPARATOR;
        new BobExec("link:dir web{$s}shared{$s}lay web{$s}domains{$s}Default{$s}lay --silent");
    }
}