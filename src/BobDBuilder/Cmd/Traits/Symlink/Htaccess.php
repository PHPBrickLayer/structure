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

        $dest = $plug->server->domains . rtrim(str_replace(".htaccess", "", $dest), "/") . $plug->s;

        if (!is_dir($dest)) {
            if (!$plug->force)
                $plug->write_fail(
                    "Directory $dest does not exist! if you want the directory to be created automatically; "
                    . "pass the flag --force",
                );

            umask(0);
            mkdir($dest, 0777, true);
        }

        $dest .= ".htaccess";

        if (file_exists($dest) && !$plug->force)
            $plug->write_warn(
                "htaccess exists already at: $dest"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note:: You will be deleting the former htaccess if you decide to pass the flag --force"
            );

        @unlink($dest);
        symlink($plug->server->web . ".htaccess", $dest);

        $plug->write_success("htaccess successfully linked to: $dest");
    }
}