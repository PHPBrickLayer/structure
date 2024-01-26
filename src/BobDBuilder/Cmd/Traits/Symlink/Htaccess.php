<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink;

trait Htaccess
{
    private function htaccess(): void
    {
        $plug = $this->plug;
        $dest = $plug->tags['link_htaccess'][0] ?? null;

        if (!$dest)
            return;

        $domain = rtrim(str_replace(".htaccess", "", $dest), "/") . $plug->s;
        $dest = $plug->server->domains . $domain;

        if (!is_dir($dest))
            $plug->write_fail("Domain *$dest* does not exist! Please create domain before linking htaccess");

        $dest .= ".htaccess";

        if (file_exists($dest) && !$plug->force)
            $plug->write_warn(
                "htaccess exists already at: *$dest*\n"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note:: You will be deleting the former htaccess if you decide to pass the flag --force"
            );

        @unlink($dest);
        symlink($plug->server->web . ".htaccess", $dest);
        $this->track_link("", $domain, "htaccess");

        $plug->write_success("htaccess successfully linked to: *$dest*");
    }
}