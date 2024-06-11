<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd\Traits\Purge;

use BrickLayer\Lay\Libs\LayDir;
use BrickLayer\Lay\Libs\String\Pluralize;

trait Brick
{
    public function brick() : void
    {
        if(!isset($this->tags['purge_brick']))
            return;

        $brick = $this->tags['purge_brick'][0] ?? null;

        $talk = fn($msg) => $this->plug->write_talk($msg, ['silent' => true]);

        if (!$brick)
            $this->plug->write_fail("No brick specified");

        $brick_dir = $this->plug->server->bricks . $brick;
        $exists = is_dir($brick_dir);

        if (!$exists)
            $this->plug->write_fail(
                "Brick directory *$brick_dir* does not exists!\n"
                . "Brick may have been deleted already.\n"
            );

        LayDir::unlink($brick_dir);
    }
}