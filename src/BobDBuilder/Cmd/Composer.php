<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\LayConfig;

class Composer implements CmdLayout
{
    private EnginePlug $plug;

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;

        $plug->add_arg($this, ["up_composer"], 'update_composer', true);
    }

    public function _spin(): void
    {
        if (!isset($this->plug->tags['update_composer']))
            return;

        $this->deploy();
    }

    public function deploy() : void
    {
        $root = $this->plug->server->root;
        $temp = $this->plug->server->temp;

        $command = file_exists($root . "composer.lock") ? "update" : "install";

        if(LayConfig::new()->get_os() == "WINDOWS")
            $composer = "composer";
        else {
            $composer = trim(shell_exec("which composer"));

            if(str_contains($composer, "not found"))
                $composer = "/usr/local/bin/composer";
        }

        $cmd = "export HOME=$root && cd $root && $composer $command --no-dev --optimize-autoloader";

        exec("$cmd 2>&1 &", $out);

        file_put_contents(
            $temp . "deploy_composer_output.txt",
            "CMD: $cmd\n" . implode("\n", $out)
        );
    }

}