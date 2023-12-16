<?php
declare(strict_types=1);

namespace BrickLayer\Lay\BobDBuilder;

use BrickLayer\Lay\BobDBuilder\Enum\CmdOutType;
use BrickLayer\Lay\Core\Enums\CustomContinueBreak;

class Engine
{
    public EnginePlug $plug;

    public function __construct(
        private readonly array $args
    )
    {
        $this->plug = new EnginePlug($this->args);

        foreach ($this->args as $i => $arg) {
            if($this->plug->run($i, $arg) == CustomContinueBreak::BREAK)
                break;
        }

        if ($this->plug->show_intro) {
            $this->intro();
            die;
        }

        $this->help();

        $this->plug->fire();

        if(empty($this->plug->active_cmd))
            $this->end();
    }

    public function intro(bool $close_talk = true): void
    {
        $this->plug->write_info(
            "----------------------------------------------------------\n"
            . "-- Name:     \t  Bob The Builder                          \n"
            . "-- Author:   \t  Osahenrumwen Aigbogun                    \n"
            . "-- Created:  \t  14/12/2023;                              \n"
            . "----------------------------------------------------------",
            [ "kill" => false, "close_talk" => $close_talk, "hide_current_cmd" => true ]
        );
    }

    public function help(): void
    {
        if (!$this->plug->tags['show_help'])
            return;

        $this->intro(false);

        $this->plug->write_info(
            "-- Bob is meant to help in building your application\n"
            . "-- There are various commands you can you can use here"
            , [ "open_talk" => false, "hide_current_cmd" => true ]
        );
    }

    public function end(): void
    {
        $this->plug->write_info(
            "-- Bob has determined that the current command is invalid\n"
            . "-- Please use --help to see the list of commands available"
            , ["current_cmd" => $this->plug->typed_cmd]
        );
    }


}