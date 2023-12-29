<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Dir;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\File;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Htaccess;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\Traits\IsSingleton;

class Symlink implements CmdLayout
{
    private EnginePlug $plug;
    private static string $link_db;

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;

        $plug->add_arg($this, ["link:htaccess"], 'link_htaccess', 0);
        $plug->add_arg($this, ["link:dir"], 'link_dir', 0, 1);
        $plug->add_arg($this, ["link:file"], 'link_file', 0, 1);
    }

    public function _spin(): void
    {
        self::$link_db = $this->plug->server->root . "symlinks.json";

        $this->htaccess();
        $this->dir();
        $this->file();
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
            if($link['type'] == "htaccess") {
                new BobExec("link:{$link['type']} {$link['dest']} --force --silent");
                continue;
            }

            new BobExec("link:{$link['type']} {$link['src']} {$link['dest']} --force --silent");
        }
    }

    use Htaccess;
    use Dir;
    use File;

}