<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\AutoDeploy;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\Brick;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\Domain;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Make\JsConfig;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Libs\Dir\LayDir;


final class Make implements CmdLayout
{
    use Domain;
    use Brick;
    use AutoDeploy;
    use JsConfig;

    private EnginePlug $plug;
    private array $tags;
    private string $internal_dir;

    
    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;
        $this->internal_dir = $this->plug->server->framework . "__internal" . $this->plug->s;

        $plug->add_arg($this, ["make:domain"], 'make_domain', 0, 1);
        $plug->add_arg($this, ["make:brick"], 'make_brick', 0, 1);
        $plug->add_arg($this, ["make:auto_deploy"], 'make_auto_deploy', 0, 1);
        $plug->add_arg($this, ["make:jsconfig"], 'make_jsconfig', true);
        $plug->add_arg($this, ["make:config"], 'make_bob_config', true);
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
        $this->jsconfig();
        $this->bob_config();
    }

    public function bob_config() : void
    {
        if(!isset($this->tags['make_bob_config']))
            return;

        $config_file = $this->plug->server->root . "bob.config.json";

        $exists = file_exists($config_file);

        if (!$this->plug->force && $exists)
            $this->plug->write_fail(
                "Bob Config file *$config_file* exists already!\n"
                . "If you wish to force this action, pass the tag --force with the command\n"
                . "Note, using --force will delete the existing file and this process cannot be reversed!"
            );

        if ($exists) {
            $this->talk(
                "- Bob Config file *$config_file* exists but --force tag detected\n"
                . "- Deleting existing file: *$config_file*"
            );

            LayDir::unlink($config_file);
        }

        copy($this->plug->server->framework . "__internal" . DIRECTORY_SEPARATOR . "backup.bob.config.json", $config_file);
    }
}