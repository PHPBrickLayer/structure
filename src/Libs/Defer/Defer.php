<?php

namespace BrickLayer\Lay\Libs\Defer;

//TODO: Continue your research on this, and ensure you handle properly
// Use this link: https://chatgpt.com/c/684106fe-d7a8-800f-af71-f45efce7e68a
class Defer
{
    public function __construct(
        protected int $timeout = 30,
    )
    {

    }

    public function run(callable $exec, ?callable $pre_exec = null, ?int $timeout = null) : void
    {

        return;

        if($pre_exec)
            $pre_exec();

        if(function_exists('fastcgi_finish_request'))
            fastcgi_finish_request();
        else {
            ignore_user_abort(true);
            flush();
        }

        $out = $exec();
    }
}