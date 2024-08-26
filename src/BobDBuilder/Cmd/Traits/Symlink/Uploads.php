<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink;

use BrickLayer\Lay\Libs\Symlink\LaySymlink;
use BrickLayer\Lay\Libs\Symlink\SymlinkWindowsType;
use BrickLayer\Lay\Libs\Symlink\SymlinkTrackType;

trait Uploads
{
    private function uploads(): void
    {
        $plug = $this->plug;
        $dest = $plug->tags['link_uploads'][0] ?? null;

        if (!$dest)
            return;

        $source = $plug->server->web . "uploads";
        $dest = str_replace(["/","\\"], DIRECTORY_SEPARATOR, $dest);
        $domain = rtrim(str_replace("uploads", "", $dest), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $dest = $plug->server->domains . $domain;

        if (!is_dir($source)) {
            if (!$plug->force)
                $plug->write_fail(
                    "Uploads directory *$source* does not exist! ensure your application has created this directory before linking.\n"
                    . "Alternatively, you can pass the *--force* flag to create the folder and link it."
                );

            umask(0);
            mkdir($source, 0777, true);
        }

        $dest .= "uploads";

        if (file_exists($dest) && !$plug->force){
            $plug->failed();
            $plug->write_warn(
                "*$dest* exists already at: \n*$dest*\n"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "Take Note:: You will be replacing the former *$dest* if you decide to pass the flag --force"
            );
        }

        LaySymlink::remove($dest);
        LaySymlink::make($source, $dest, SymlinkWindowsType::SOFT);

        $this->track_link("", $domain, SymlinkTrackType::UPLOADS);

        $plug->write_success("Uploads folder successfully linked to: *$dest*");
    }
}