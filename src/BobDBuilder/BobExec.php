<?php

namespace BrickLayer\Lay\BobDBuilder;

final class BobExec
{
    public int $response_code = 0;

    public function __construct(string $command, bool $die_on_failure = true)
    {
        $command = "php bob $command";

        new Engine(
            explode(" ", $command),
            true,
            $die_on_failure,
            $this->response_code
        );
    }
}