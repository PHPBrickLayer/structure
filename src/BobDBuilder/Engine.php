<?php
declare(strict_types=1);

namespace BrickLayer\Lay\BobDBuilder;

use BrickLayer\Lay\BobDBuilder\Enum\CmdOutType;
use BrickLayer\Lay\Core\Enums\CustomContinueBreak;

class Engine
{
    public EnginePlug $plug;

    public function __construct(
        private array $args
    )
    {
        $show_help = array_search("--help", $this->args, true);
        $show_help = $show_help === false ? array_search("--h", $this->args, true) : $show_help;

        if ($show_help !== false) {
            unset($this->args[$show_help]);
            $show_help = true;
        }

        $force_action = array_search("--force", $this->args, true);
        $force_action = $force_action === false ? array_search("--f", $this->args, true) : $force_action;

        if ($force_action !== false) {
            unset($this->args[$force_action]);
            $force_action = true;
        }

        $this->plug = new EnginePlug($this->args);

        $this->plug->force = $force_action ?? false;
        $this->plug->show_help = $show_help ?? false;

        foreach ($this->args as $i => $arg) {
            if($this->plug->run($i, $arg) == CustomContinueBreak::BREAK)
                break;
        }

        // start BOB Execution
        $this->plug->write(
            "",
            CmdOutType::TALK,
            [
                'open_talk' => true,
                'hide_current_cmd' => false,
            ]
        );

        if ($this->plug->show_intro) {
            $this->intro();
            die;
        }

        $this->help();

        $this->plug->fire();

        // End Bob execution
        if(empty($this->plug->active_cmd))
            $this->plug->write_info(
                "-- Bob has determined that the current command is invalid\n"
                . "-- Please use --help to see the list of available commands"
                , ["current_cmd" => $this->plug->typed_cmd]
            );

        $this->plug->write_success(
            "\n" . (
            isset($this->plug->active_cmd_class) ?
                "-- Operation completed!" :
                ""
            ),
            ['close_talk' => true]
        );
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
        if (!$this->plug->show_help)
            return;

        $this->intro(false);

        $this->plug->write_info(
            "-- Bob is meant to help in building your application\n"
            . "-- There are various commands you can you can use here"
            , [ "open_talk" => false, "hide_current_cmd" => true ]
        );
    }

}