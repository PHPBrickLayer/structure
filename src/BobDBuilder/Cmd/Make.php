<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\AutoDeploy;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\Brick;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\Domain;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\JsConfig;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use Override;

final class Make implements CmdLayout
{
    use Domain;
    use Brick;
    use AutoDeploy;
    use JsConfig;

    private EnginePlug $plug;
    private array $tags;
    private string $internal_dir;

    #[Override]
    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;
        $this->internal_dir = $this->plug->server->framework . "__internal" . $this->plug->s;

        $plug->add_arg($this, ["make:domain"], 'make_domain', 0, 1);
        $plug->add_arg($this, ["make:brick"], 'make_brick', 0, 1);
        $plug->add_arg($this, ["make:auto_deploy"], 'make_auto_deploy', 0, 1);
        $plug->add_arg($this, ["make:jsconfig"], 'make_jsconfig', true);
    }

    private function talk(string $msg) : void
    {
        $this->plug->write_talk($msg, ['silent' => true]);
    }

    #[Override]
    public function _spin(): void
    {
        $this->tags = $this->plug->tags;

        $this->brick();
        $this->domain();
        $this->auto_deploy();
        $this->jsconfig();
    }

}