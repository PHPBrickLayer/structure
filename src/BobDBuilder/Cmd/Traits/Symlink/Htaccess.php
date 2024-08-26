<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink;

use BrickLayer\Lay\Libs\Symlink\LaySymlink;
use BrickLayer\Lay\Libs\Symlink\SymlinkTrackType;

trait Htaccess
{
    private function htaccess(): void
    {
        $plug = $this->plug;
        $dest = $plug->tags['link_htaccess'][0] ?? null;

        if (!$dest)
            return;

        $domain = str_replace(["/", ".htaccess"], [DIRECTORY_SEPARATOR, ""], $dest);
        $domain = rtrim($domain, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $dest = $plug->server->domains . $domain;

        if (!is_dir($dest))
            $plug->write_fail("Domain *$dest* does not exist! Please create domain before linking htaccess");

        $dest .= ".htaccess";

        if (file_exists($dest) && !$plug->force) {
            $plug->failed();
            $plug->write_warn(
                "htaccess exists already at: *$dest*\n"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note:: You will be deleting the former htaccess if you decide to pass the flag --force"
            );
        }

        $src = $plug->server->web . ".htaccess";

        LaySymlink::remove($dest);
        LaySymlink::make($src, $dest);

        $this->track_link("", $domain, SymlinkTrackType::HTACCESS);

        $plug->write_success("htaccess successfully linked to: *$dest*");
    }
}