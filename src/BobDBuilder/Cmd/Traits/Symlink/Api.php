<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink;

use BrickLayer\Lay\Libs\LayDir;
use BrickLayer\Lay\Libs\Symlink\LaySymlink;
use BrickLayer\Lay\Libs\Symlink\SymlinkTypes;

trait Api
{
    private function api(): void
    {
        $plug = $this->plug;
        $dest = $plug->tags['link_api'][0] ?? null;

        if (!$dest)
            return;

        $domain = rtrim(str_replace(["api", "Api"], "", $dest), "/") . $plug->s;
        $dest = $plug->server->domains . $domain;

        if (!is_dir($dest))
            $plug->write_fail(
                "Domain *$dest* does not exist!\n"
                . "Create the Domain *$dest*. Creating a domain automatically links the api to the domain"
            );

        $dest .= "api";
        $src = $plug->server->domains . "Api/";

        if(!is_dir($src))
            $plug->write_warn(
                "*api* directory exists already at: *$dest*\n"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note:: You will be deleting the former *api directory/symlink* if you decide to pass the flag --force"
            );

        if ((is_dir($dest) || is_link($dest)) && !$plug->force)
            $plug->write_warn(
                "*api* directory exists already at: *$dest*\n"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note:: You will be deleting the former *api directory/symlink* if you decide to pass the flag --force"
            );

        $src = str_replace("/", DIRECTORY_SEPARATOR, $src);
        $dest = str_replace("/", DIRECTORY_SEPARATOR, $dest);

        LayDir::unlink($dest);

        LaySymlink::make($src, $dest, SymlinkTypes::HARD);

        $this->track_link("", $domain, "api");

        $plug->write_success("*api* directory successfully linked to: *$dest*");
    }
}