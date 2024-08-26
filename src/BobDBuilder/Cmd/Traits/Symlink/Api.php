<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink;

use BrickLayer\Lay\Libs\Symlink\LaySymlink;
use BrickLayer\Lay\Libs\Symlink\SymlinkWindowsType;
use BrickLayer\Lay\Libs\Symlink\SymlinkTrackType;

trait Api
{
    private function api(): void
    {
        $plug = $this->plug;
        $dest = $plug->tags['link_api'][0] ?? null;

        if (!$dest)
            return;

        $dest = str_replace(["/","\\"], DIRECTORY_SEPARATOR, $dest);
        $domain = rtrim(str_replace(["api", "Api"], "", $dest), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $dest = $plug->server->domains . $domain;

        if (!is_dir($dest))
            $plug->write_fail(
                "Domain *$dest* does not exist!\n"
                . "Create the Domain *$dest*. Creating a domain automatically links the api to the domain"
            );

        $dest .= "api";
        $src = $plug->server->domains . "Api" . DIRECTORY_SEPARATOR;

        if(!is_dir($src)) {
            $plug->failed();
            $plug->write_warn(
                "*api* directory exists already at: *$dest*\n"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note:: You will be deleting the former *api directory/symlink* if you decide to pass the flag --force"
            );
        }

        if ((is_dir($dest) || is_link($dest)) && !$plug->force) {
            $plug->failed();
            $plug->write_warn(
                "*api* directory exists already at: *$dest*\n"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note:: You will be deleting the former *api directory/symlink* if you decide to pass the flag --force"
            );
        }

        LaySymlink::remove($dest);
        LaySymlink::make($src, $dest, SymlinkWindowsType::SOFT);

        $this->track_link("", $domain, SymlinkTrackType::API);

        $plug->write_success("*api* directory successfully linked to: *$dest*");
    }
}