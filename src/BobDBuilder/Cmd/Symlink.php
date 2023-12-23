<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Dir;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\File;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Htaccess;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\Traits\IsSingleton;

class Symlink implements CmdLayout
{
    use IsSingleton;

    private EnginePlug $plug;

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;

        $plug->add_arg($this, ["link:htaccess"], 'link_htaccess', 0);
        $plug->add_arg($this, ["link:dir"], 'link_dir', 0, 1);
        $plug->add_arg($this, ["link:file"], 'link_file', 0, 1);
    }

    public function _spin(): void
    {
        $this->htaccess();
        $this->dir();
        $this->file();
    }


    use Htaccess;
    use Dir;
    use File;

}