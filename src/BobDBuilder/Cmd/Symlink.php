<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Dir;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\File;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Htaccess;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Shared;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Uploads;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Libs\Dir\LayDir;


final class Symlink implements CmdLayout
{
    private EnginePlug $plug;

    public function __construct(?EnginePlug $plug = null)
    {
        if(!$plug)
            return;

        $this->plug = $plug;
    }

    
    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;

        $plug->add_arg($this, ["link:htaccess"], 'link_htaccess', 0);
        $plug->add_arg($this, ["link:shared"], 'link_shared', 0);
        $plug->add_arg($this, ["link:uploads"], 'link_uploads', 0);
        $plug->add_arg($this, ["link:dir"], 'link_dir', 0, 1);
        $plug->add_arg($this, ["link:file"], 'link_file', 0, 1);
        $plug->add_arg($this, ["link:rm"], 'link_remove', 0, 1, 2, 3);
    }

    
    public function _spin(): void
    {
        $this->htaccess();
        $this->uploads();
        $this->dir();
        $this->file();
        $this->shared();

        if($this->plug->tags['link_remove'])
            $this->unlink();
    }

    public function unlink() : void
    {
        $plug = $this->plug;
        $dest = $plug->tags['link_remove'] ?? null;

        if (!$dest)
            return;

        $symlinks = "";
        $link_found = false;

        foreach ($dest as $link) {
            if(empty($link) || !is_link($link))
                continue;

            $link_found = true;
            $symlinks .= "\n* - $link*";
            LayDir::unlink($plug->server->root . $link);
        }

        if(!$link_found) {
            $plug->write_info("Links have already been removed!");
            return;
        }

        new BobExec("link:prune --silent");

        $plug->write_success("Links removed successfully: *$symlinks*");
    }

    use Htaccess;
    use Dir;
    use File;
    use Uploads;
    use Shared;

}