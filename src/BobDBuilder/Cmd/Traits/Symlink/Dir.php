<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink;

use BrickLayer\Lay\Libs\Symlink\LaySymlink;
use BrickLayer\Lay\Libs\Symlink\SymlinkWindowsType;
use BrickLayer\Lay\Libs\Symlink\SymlinkTrackType;

trait Dir
{
    private function dir(): void
    {
        $link = $this->plug->tags['link_dir'] ?? null;

        if (!$link)
            return;

        if (!isset($link[0]))
            $this->plug->write_fail("Source directory not specified!");

        if (!isset($link[1]))
            $this->plug->write_fail("Destination directory not specified!");

        $src = $this->plug->server->root . str_replace(["/","\\"], DIRECTORY_SEPARATOR, $link[0]);
        $dest = $this->plug->server->root . str_replace(["/","\\"], DIRECTORY_SEPARATOR, $link[1]);

        if (!is_dir($src))
            $this->plug->write_fail(
                "Source directory *$src* does not exist!\n"
                . "You cannot link a directory that doesn't exist"
            );

        if (is_dir($dest) && !$this->plug->force) {
            $this->plug->write_warn(
                "Destination directory: *$dest* exists already!\n"
                . "If you want to REPLACE!! it, pass the flag *--force*\n"
                . "***### Take Note:: You will be deleting the former directory if you decide to pass the flag --force",
                ["close_talk" => false]
            );
            return;
        }

        LaySymlink::remove($dest);
        LaySymlink::make($src, $dest, SymlinkWindowsType::SOFT);

        $this->track_link($link[0], $link[1], SymlinkTrackType::DIRECTORY);

        $this->plug->write_success(
            "DIRECTORY symlinked!\n"
            . "SRC: *$src*\n"
            . "DEST: *$dest*"
        );
    }
}