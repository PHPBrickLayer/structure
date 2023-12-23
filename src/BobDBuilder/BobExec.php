<?php

namespace BrickLayer\Lay\BobDBuilder;

class BobExec
{
    public function __construct(string $command)
    {
        $command = "php bob $command";

        new Engine(
            explode(" ", $command),
            true
        );
    }
}