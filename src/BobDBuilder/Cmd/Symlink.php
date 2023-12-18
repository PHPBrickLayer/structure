<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\Traits\IsSingleton;

class Symlink implements CmdLayout
{
    use IsSingleton;

    private readonly EnginePlug $plug;

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;

        $plug->add_arg($this, ["link:htaccess"], 'link_htaccess', 0);
        $plug->add_arg($this, ["link:dir"], 'link_dir', 0, 1);
        $plug->add_arg($this, ["link:file"], 'link_file', 0, 1);
    }

    public function _spin(): void
    {
        $this->htaccess();
        $this->dir();
        $this->file();
    }

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

        if (file_exists($dest)) {
            if (!$plug->force)
                $plug->write_warn(
                    "htaccess exists already at: $dest"
                    . "If you want to REPLACE!! it, pass the flag --force\n"
                    . "***### Take Note:: You will be deleting the former htaccess if you decide to pass the flag --force"
                );

            unlink($dest);
        }

        symlink($plug->server->web . ".htaccess", $dest);

        $plug->write_success("htaccess successfully linked to: $dest");
    }

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
            $this->plug->write_fail("Source file $src does not exist! You cannot link a file that doesn't exist");

        if (file_exists($dest)) {
            if (!$this->plug->force)
                $this->plug->write_warn(
                    "Destination file: $dest exists already!\n"
                    . "If you want to REPLACE!! it, pass the flag --force\n"
                    . "***### Take Note::  You will be deleting the former file if you decide to pass the flag --force"
                );

            unlink($dest);
        }

        symlink($src, $dest);

        $this->plug->write_success(
            "Directory link created successfully!\n"
            . "Source Directory: $src\n"
            . "Destination Directory: $dest"
        );
    }

}