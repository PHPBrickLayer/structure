<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink;

use BrickLayer\Lay\Libs\Symlink\LaySymlink;

trait File
{
    private function file(): void
    {
        $link = $this->plug->tags['link_file'] ?? null;

        if (!$link)
            return;

        if (!isset($link[0]))
            $this->plug->write_fail("Source file not specified!");

        if (!isset($link[1]))
            $this->plug->write_fail("Destination file not specified!");

        $src = $this->plug->server->root . $link[0];
        $dest = $this->plug->server->root . $link[1];

        if (!file_exists($src))
            $this->plug->write_fail("Source file *$src* does not exist! You cannot link a file that doesn't exist");

        if (file_exists($dest) && !$this->plug->force) {
            $this->plug->failed();
            $this->plug->write_warn(
                "Destination file: *$dest* exists already!\n"
                . "If you want to REPLACE!! it, pass the flag *--force*\n"
                . "***### Take Note::  You will be deleting the former file if you decide to pass the flag --force"
            );
        }

        LaySymlink::remove($dest);
        LaySymlink::make($src, $dest);

        $this->track_link($link[0], $link[1], "file");

        $this->plug->write_success(
            "Directory link created successfully!\n"
            . "Source Directory: *$src*\n"
            . "Destination Directory: *$dest*"
        );
    }
}