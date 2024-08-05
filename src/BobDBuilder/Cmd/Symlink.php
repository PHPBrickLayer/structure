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
use BrickLayer\Lay\Core\Traits\IsSingleton;

class Symlink implements CmdLayout
{
    private EnginePlug $plug;
    private static string $link_db;

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
    }

    private function init_db() : void
    {
        self::$link_db = $this->plug->server->root . "symlinks.json";
    }

    private function track_link(string $src, string $dest, string $link_type) : void
    {
        $new_link = [
            "type" => $link_type,
            "src" => $src,
            "dest" => $dest,
        ];

        $links = [];

        if(file_exists(self::$link_db))
            $links = json_decode(file_get_contents(self::$link_db), true);

        foreach ($links as $link) {
            if($new_link['type'] == $link['type'] && $new_link['dest'] == $link['dest'])
                return;
        }

        $links[] = $new_link;

        file_put_contents(self::$link_db, json_encode($links));
    }

    public function refresh_link() : void
    {
        if(!file_exists(self::$link_db))
            return;

        $links = json_decode(file_get_contents(self::$link_db), true);

        foreach ($links as $link) {
            if(empty($link['src'])) {
                new BobExec("link:{$link['type']} {$link['dest']} --force --silent");
                continue;
            }

            new BobExec("link:{$link['type']} {$link['src']} {$link['dest']} --force --silent");
        }
    }

    public function prune_link() : void
    {
        if(!file_exists(self::$link_db))
            return;

        $links = json_decode(file_get_contents(self::$link_db), true);

        foreach ($links as $i => $link) {
            $src = $this->plug->server->root . $link['src'];
            $dest = $this->plug->server->root . $link['dest'];

            if($link['type'] == "htaccess")
                $dest = $this->plug->server->domains . $link['dest'] . ".htaccess";

            if(!is_link($dest))
                unset($links[$i]);

            if(!is_file($src) and !is_dir($src)) {
                unset($links[$i]);

                if (is_link($dest))
                    unlink($dest);
            }
        }

        file_put_contents(self::$link_db, json_encode($links));
    }

    use Htaccess;
    use Dir;
    use File;
    use Uploads;
    use Shared;
    use Api;

}