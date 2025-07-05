<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\Dir\LayDir;


final class Project implements CmdLayout
{
    private EnginePlug $plug;
    private array $tags;

    
    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;
        $plug->add_arg($this, ["project:create"], 'project_create', 0);
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
        $tag = $this->tags['project_create'][0] ?? null;

        if (!$tag)
            return;

        $tag = trim($tag);

        $server = $this->plug->server;

        // copy env file if it doesn't exist
        if (!file_exists($server->root . ".env"))
            copy($server->root . ".env.example", $server->root . ".env");

        // copy core lay js file to project lay folder
        LayDir::copy($server->lay_static . "omjs", $server->shared . "lay");

        // copy helper js file to project lay folder
        LayDir::copy($server->lay_static . "js", $server->shared . "lay");

        copy(
            $server->framework . ".gitignore",
            $server->lay . ".gitignore",
        );

        // Create Lay dependent directories if they don't exist
        LayDir::make($server->temp, 0755, true);
        LayDir::make($server->exceptions, 0755, true);
        LayDir::make($server->cron_outputs, 0755, true);

        if($tag == "--refresh-links") {
            LayConfig::generate_project_identity();

            //TODO: Really look at it and determine if we still need to track symlinks
            // since they are all relative now rather than absolute
            $this->plug->write_info("Pruning and refreshing symlinks!");
            (new Symlink($this->plug))->prune_link();
            (new Symlink($this->plug))->refresh_link();
        }

        if($tag == "--force-refresh") {
            $this->plug->write_info("Default domain forcefully refreshed");
            LayConfig::generate_project_identity(true);

            new BobExec("make:domain Default '*' --silent --force");
            return;
        }

        if($tag == "--fresh-project") {
            $this->plug->write_info("Fresh project detected!");
            LayConfig::generate_project_identity(true);

            // Replace default domain folder on a fresh project
            new BobExec("make:domain Default '*' --silent --force");
            return;
        }

        // create a default domain folder if not exists
        if(!is_dir($this->plug->server->domains . "Default"))
            new BobExec("make:domain Default '*' --silent");
    }
}