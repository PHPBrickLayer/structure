<?php
declare(strict_types=1);

namespace BrickLayer\Lay\BobDBuilder;

use BrickLayer\Lay\BobDBuilder\Enum\CmdOutType;
use BrickLayer\Lay\Core\Enums\CustomContinueBreak;

class Engine
{
    public EnginePlug $plug;
    private static float $process_duration;

    public function __construct(
        private array $args,
        private readonly bool $is_internal = false,
        bool $die_on_error = true,
        int &$response_code = 0
    )
    {
        $start_time = microtime(true);

        $force = $this->extract_global_tag("--force", "-f");
        $show_help = $this->extract_global_tag("--help", "-h");
        $silent = $this->extract_global_tag("--silent", "-s");

        $this->plug = new EnginePlug($this->args, $die_on_error);
        $this->plug->is_internal = $this->is_internal;

        $this->plug->force = $force;
        $this->plug->show_help = $show_help;
        $this->plug->silent = $silent;

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
            ]
        );

        if ($this->plug->show_intro) {
            $this->plug->show_help = true;
            $this->help();
        }

        $this->help();

        $this->plug->fire();

        $response_code = (int) $this->plug->operation_successful;

        // End Bob execution
        $end_time = microtime(true);

        self::$process_duration = ceil($end_time - $start_time);

        if(empty($this->plug->active_cmd)) {
            $this->plug->write_warn(
                "-- Bob has determined that the current command is invalid\n"
                . "-- Please use --help to see the list of available commands"
                , ["current_cmd" => $this->plug->typed_cmd, "hide_current_cmd" => false]
            );
        }

        if(!$this->plug->silent) {
            if($response_code)
                $this->plug->write_success(
                    "\n" . (
                    isset($this->plug->active_cmd_class) ?
                        "-- Operation completed!" :
                        ""
                    ),
                    ['close_talk' => true, "process_duration" => self::$process_duration]
                );
            else
                $this->plug->write_fail("-- Operation ended with error!", [
                    "process_duration" => self::$process_duration
                ]);
        }
    }

    public function extract_global_tag(string ...$tags) : bool
    {
        $out = false;

        foreach ($tags as $tag) {
            if($out !== false)
                break;

            $out = array_search($tag, $this->args, true);

        }

        if ($out !== false) {
            unset($this->args[$out]);
            $out = true;
        }

        return $out;
    }

    public function help(): void
    {
        if (!$this->plug->show_help)
            return;

        $this->plug->write_info(
            "----------------------------------------------------------\n"
            . "-- Name:     \t  Bob The Builder                          \n"
            . "-- Author:   \t  Osahenrumwen Aigbogun                    \n"
            . "-- Created:  \t  14/12/2023;                              \n"
            . "----------------------------------------------------------",
            [ "kill" => false, "close_talk" => false, "hide_current_cmd" => true ]
        );

        $this->plug->show_help = true;

        $ava_cmd = "";

        $this->plug->write_info(
            "-- Bob is meant to help in building your application\n"
            . "-- Usage: php bob CMD --FLAGS\n"
            . "$ava_cmd"
            , [ "open_talk" => false]
        );

        print "----------- These are the current available commands ------------- \n";

        foreach ($this->plug->plugged_args as $arg) {
            foreach ($arg['cmd'] as $c){
                $this->plug->write_talk(" [x] $c");
            }
        }

        print "----------- END ------------- \n";

        $this->plug->write_talk("-- Usage: php bob CMD --FLAGS");
        die;
    }
}