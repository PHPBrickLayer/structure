<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink;

use BrickLayer\Lay\Libs\Symlink\LaySymlink;
use BrickLayer\Lay\Libs\Symlink\SymlinkWindowsType;
use BrickLayer\Lay\Libs\Symlink\SymlinkTrackType;

trait Shared
{
    private function shared(): void
    {
        $plug = $this->plug;
        $dest = $plug->tags['link_shared'][0] ?? null;

        if (!$dest)
            return;

        $domain = str_replace(["/", "shared"], [DIRECTORY_SEPARATOR, ""], $dest);
        $domain = rtrim($domain, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR;
        $dest = $plug->server->domains . $domain;

        if (!is_dir($dest))
            $plug->write_fail(
                "Domain *$dest* does not exist!\n"
                . "Create the Domain *$dest*. Creating a domain automatically links the shared folder"
            );

        $dest .= "shared";
        $src = $plug->server->web . "shared" . DIRECTORY_SEPARATOR;

        if(!is_dir($src)) {
            $plug->failed();
            $plug->write_warn(
                "*shared* directory exists already at: *$dest*\n"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note:: You will be deleting the former *shared directory/symlink* if you decide to pass the flag --force"
            );
        }

        if ((is_dir($dest) || is_link($dest)) && !$plug->force) {
            $plug->failed();
            $plug->write_warn(
                "*shared* directory exists already at: *$dest*\n"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note:: You will be deleting the former *shared directory/symlink* if you decide to pass the flag --force"
            );
        }

        LaySymlink::remove($dest);
        LaySymlink::make($src, $dest, SymlinkWindowsType::SOFT);

        $plug->write_success("*shared* directory successfully linked to: *$dest*");
    }
}