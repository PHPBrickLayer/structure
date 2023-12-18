<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink;

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

        $src = $this->plug->server->root . $link[0];
        $dest = $this->plug->server->root . $link[1];

        if (!is_dir($src))
            $this->plug->write_fail(
                "Source directory *$src* does not exist!\n"
                . "You cannot link a directory that doesn't exist"
            );

        if (is_dir($dest)) {
            if (!$this->plug->force)
                $this->plug->write_warn(
                    "Destination directory: *$dest* exists already!\n"
                    . "If you want to REPLACE!! it, pass the flag *--force*\n"
                    . "***### Take Note:: You will be deleting the former directory if you decide to pass the flag --force"
                );

            unlink($dest);
        }

        symlink($src, $dest);

        $this->plug->write_success(
            "Directory link created successfully!\n"
            . "Source Directory: *$src*\n"
            . "Destination Directory: *$dest*"
        );
    }
}