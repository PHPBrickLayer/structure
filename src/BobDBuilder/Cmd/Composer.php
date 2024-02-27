<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Libs\Cron\LayCron;

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

        exec("export HOME=$root && cd $root && composer $command --no-dev --optimize-autoloader 2>&1", $out);

        file_put_contents($temp . "deploy_composer_output.txt", implode("\n", $out));

        // unset cron job after updating composer packages
        LayCron::new()->unset("update-composer-pkgs");
    }

}