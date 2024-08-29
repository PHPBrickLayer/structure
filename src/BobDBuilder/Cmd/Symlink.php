<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Api;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Dir;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\File;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Htaccess;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Shared;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Uploads;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Libs\LayDir;
use BrickLayer\Lay\Libs\Symlink\LaySymlink;
use BrickLayer\Lay\Libs\Symlink\SymlinkTrackType;

class Symlink implements CmdLayout
{
    private EnginePlug $plug;
    private static string $link_db;
    private static LaySymlink $lay_symlink;

    public function __construct(?EnginePlug $plug = null)
    {
        if(!$plug)
            return;

        $this->plug = $plug;
        $this->init_db();
    }

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;

        $plug->add_arg($this, ["link:htaccess"], 'link_htaccess', 0);
        $plug->add_arg($this, ["link:api"], 'link_api', 0);
        $plug->add_arg($this, ["link:shared"], 'link_shared', 0);
        $plug->add_arg($this, ["link:uploads"], 'link_uploads', 0);
        $plug->add_arg($this, ["link:dir"], 'link_dir', 0, 1);
        $plug->add_arg($this, ["link:file"], 'link_file', 0, 1);
        $plug->add_arg($this, ["link:refresh"], 'link_refresh', true);
        $plug->add_arg($this, ["link:prune"], 'link_prune', true);
        $plug->add_arg($this, ["link:rm"], 'link_remove', 0, 1, 2, 3);
    }

    public function _spin(): void
    {
        $this->init_db();

        $this->htaccess();
        $this->uploads();
        $this->dir();
        $this->file();
        $this->shared();
        $this->api();

        if($this->plug->tags['link_refresh'])
            $this->refresh_link();

        if($this->plug->tags['link_prune'])
            $this->prune_link();

        if($this->plug->tags['link_remove'])
            $this->unlink();
    }

    private function init_db() : void
    {
        self::$lay_symlink = new LaySymlink("project_symlinks.json");
        self::$link_db = self::$lay_symlink->current_db();

        //TODO: Delete this section after legacy projects have been updated
        $old_links = $this->plug->server->root . "symlinks.json";

        if(file_exists($old_links))
            rename($old_links, self::$link_db);
        //TODO: END Deletion
    }

    private function track_link(string $src, string $dest, SymlinkTrackType $link_type) : void
    {
        self::$lay_symlink->track_link($src, $dest, $link_type);
    }

    public function refresh_link() : void
    {
        self::$lay_symlink->refresh_link(true);
    }

    public function prune_link() : void
    {
        self::$lay_symlink->prune_link(true);
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
    use Api;

}