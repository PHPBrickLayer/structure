<?php

namespace BrickLayer\Lay\BobDBuilder\Interface;

use BrickLayer\Lay\BobDBuilder\EnginePlug;

interface CmdLayout
{
    /**
     * This is where all the functionalities of the Cmd class reside.
     * @return void
     */
    public function _spin() : void;

    /**
     * This is where you store the `$plug` in the example below.
     * @example private readonly EnginePlug $plug;
     *
     * This is also where you add_args to the EnginePlug, like the example below.
     * @example $plug->add_arg(["link:htaccess"], 'link_htaccess', 0);
     *
     * @param EnginePlug $plug
     * @return void
     */
    public function _init(EnginePlug $plug) : void;
}