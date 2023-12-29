<?php

namespace BrickLayer\Lay\BobDBuilder;

use BrickLayer\Lay\BobDBuilder\Enum\CmdOutType;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Console;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Background;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Foreground;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Style;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\Enums\CustomContinueBreak;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use Error;
use ReflectionClass;
use ReflectionException;
use TypeError;

class EnginePlug
{
    public bool $show_intro = true;
    public bool $show_help = false;
    public bool $force = false;
    public bool $silent = false;
    public bool $cmd_found = false;
    public bool $is_internal = false;
    public array $tags = [];
    public array $plugged_args = [];
    public string $typed_cmd = "";
    public string $active_cmd = "";
    public string $active_cmd_class;
    public array $cmd_classes;

    public readonly object $server;
    public readonly string $s;
    public readonly bool $project_mode;

    private string $current_arg;
    private int $current_index;

    public function __construct(
        private readonly array $args
    )
    {
        $this->server = LayConfig::server_data();
        $this->s = DIRECTORY_SEPARATOR;
        $this->project_mode = file_exists($this->server->root . "foundation.php");

        $this->load_cmd_classes();
    }

    public function fire(): void
    {
        $spun_correct_class = false;

        Exception::new()->capture_errors(true);

        // This property is active when the command sent matches any on the existing Cmd classes
        // If it's not set, there's no need to loop through the existing classes to spin their methods,
        // we simply need to break out of this method and save php the stress.
        if(!isset($this->active_cmd_class))
            return;

        foreach ($this->cmd_classes as $cmd_class) {
            if($spun_correct_class)
                break;

            if($cmd_class::class != $this->active_cmd_class)
                continue;

            try{
                $cmd_class->_spin();
            }
            catch (TypeError|Error|\Exception $e){
                Exception::throw_exception(
                    $e->getMessage() . "\n"
                    . $e->getFile() . ":" . $e->getLine()
                    , "BobError " . $cmd_class::class,
                    stack_track: $e->getTrace()
                );
            }
            if($cmd_class::class == $this->active_cmd_class)
                $spun_correct_class = true;
        }
    }

    private function load_cmd_classes() : void
    {
        $namespace = explode("\\", self::class);
        array_pop($namespace);
        $namespace = implode("\\", $namespace) . "\\Cmd";

        foreach (scandir(__DIR__ . $this->s . "Cmd") as $class) {
            if (
                $class == "." ||
                $class == ".." ||
                is_dir(__DIR__ . $this->s . "Cmd" . $this->s . $class)
            )
                continue;

            $cmd_class = $namespace . "\\" . explode(".php", $class)[0];

            try{
                $class = new ReflectionClass($cmd_class);
            } catch (ReflectionException $e){
                Exception::throw_exception($e->getMessage(), "ReflectionException");
            }

            try {
                $class = $class->newInstance();
            } catch (ReflectionException) {
                Exception::throw_exception(
                    " $cmd_class constructor class is private. \n"
                    . " All Cmd classes must expose their __construct function to clear this error",
                    "ConstructPrivate"
                );
            }

            $this->cmd_classes[] = $class;

            try {
                $class->_init($this);
            } catch (Error|\Exception $e) {
                Exception::throw_exception($e->getMessage(), "BobError");
            }
        }
    }

    public function run(int $index, string $arg): CustomContinueBreak
    {
        $this->current_arg = $arg;
        $this->current_index = $index;

        $this->show_intro = $index < 1;

        if ($index == 1)
            $this->typed_cmd = $arg;

        foreach ($this->plugged_args as $key => $arg) {
            if ($this->arg($arg['class'], [...$arg['cmd']], $this->tags[$key], ...$arg['value']))
                return CustomContinueBreak::BREAK;
        }

        return CustomContinueBreak::CONTINUE;
    }

    public function extract_tags(array $tags, ...$value_index) : mixed
    {
        $out = false;

        foreach ($tags as $tag) {
            if($out !== false)
                break;

            $out = array_search($tag, $this->args, true);
        }

        $value = [];

        foreach ($value_index as $index) {
            if ($out === false)
                break;

            if(is_bool($index)) {
                $value[] = $index;
                continue;
            }

            $index++;

            $value[] = $this->args[($out + $index)] ?? null;
        }

        return $value;
    }

    /**
     * Use this function to add the arguments required by each Cmd Class
     * @param CmdLayout $cmd_layout
     * @param array $cmd
     * @param string $tag_key
     * @param int|bool ...$value_or_value_index
     * @return void
     * @example $this->add_arg(["link:dir"], 'link_dir', 0, 1);
     */
    public function add_arg(CmdLayout $cmd_layout, array $cmd, string $tag_key, int|bool ...$value_or_value_index): void
    {
        $this->plugged_args[$tag_key] = ["cmd" => $cmd, "value" => $value_or_value_index, "class" => $cmd_layout::class];
    }

    private function arg(string $cmd_class, array $cmd, &$pipe, int|bool ...$value_index): bool
    {
        if ($this->cmd_found)
            return true;

        $cmd_index = array_search($this->current_arg, $cmd, true);

        if ($cmd_index === false)
            return false;

        $this->active_cmd = $cmd[$cmd_index];
        $this->active_cmd_class = $cmd_class;

        $this->cmd_found = true;

        if (is_bool($value_index[0])) {
            $pipe = $value_index;
            return true;
        }

        foreach ($value_index as $index) {
            $pipe[] = $this->args[($this->current_index + $index + 1)] ?? null;
        }

        return true;
    }

    public function write_info(string $message, array $opts = []) : void {
        $this->write($message, CmdOutType::INFO, $opts);
    }

    public function write_success(string $message, array $opts = []) : void {
        $opts['hide_current_cmd'] = true;
        $this->write($message, CmdOutType::SUCCESS, $opts);
    }

    public function write_fail(string $message, array $opts = []) : void {
        $opts['close_talk'] = true;
        $opts['kill'] = true;

        $this->write($message, CmdOutType::FAIL, $opts);
    }

    public function write_talk(string $message, array $opts = []) : void {
        $this->write($message, CmdOutType::TALK, $opts);
    }

    public function write_warn(string $message, array $opts = []) : void {
        $opts['close_talk'] = true;
        $opts['kill'] = true;

        $this->write($message, CmdOutType::WARN, $opts);
    }

    public function write(string $message, ?CmdOutType $type = null, array $opts = []): void
    {
        $kill = $opts['kill'] ?? false;
        $open_talk =  $opts['open_talk'] ?? false;
        $close_talk = $opts['close_talk'] ?? false;
        $current_cmd = $this->active_cmd ?: ($opts['current_cmd'] ?? "");
        $hide_cur_cmd = $opts['hide_current_cmd'] ?? false;
        $silent = $opts['silent'] ?? $this->silent;
        $maintain_line = $opts['maintain_line'] ?? false;

        $color = match ($type) {
            default => Style::normal,
            CmdOutType::SUCCESS => Foreground::green,
            CmdOutType::INFO => Foreground::light_cyan,
            CmdOutType::WARN => Foreground::yellow,
            CmdOutType::FAIL => Foreground::red,
            CmdOutType::TALK => Foreground::light_purple,
        };

        $color = $opts['color'] ?? $color;

        if(gettype($color) !== "object" || get_class($color) != Foreground::class)
            Exception::throw_exception(
                "Invalid Color Type received. Color must be of " . Foreground::class,
                "InvalidConsoleColor"
            );

        if ($open_talk && !$silent)
            Console::log("(^_^) Bob is Building --::--", Foreground::light_gray);

        if (!$hide_cur_cmd && !$silent && !empty($current_cmd)) {
            print "   CURRENT COMMAND ";
            Console::log(
                " $current_cmd ",
                bg_color: Background::cyan
            );
        }

        $list = false;

        if($type == CmdOutType::TALK && str_starts_with($message, "-")) {
            Console::log("   o", Foreground::light_blue, newline: false);
            $message = ltrim($message, "-");
            $list = true;
        }

        foreach (explode("\n", $message) as $k => $m) {
            if(empty($m))
                continue;

            if($list && $k > 0)
                $m = "     " . $m;

            if(!$list)
                $m = "   " . $m;

            if(str_contains($m, "*")) {
                $m = preg_replace("/\*+/", "*", $m);

                foreach (explode("*", $m) as $i => $s) {
                    if($i % 2 == 0)
                        Console::log($s, $color, newline: false);
                    else
                        Console::log($s, Foreground::cyan, style: Style::bold , newline: false);
                }

                Console::log("", maintain_line: $maintain_line);

                continue;
            }

            Console::log($m, $color, maintain_line: $maintain_line);
        }

        if ($close_talk && !$silent) {
            Console::log("(-_-) Bob is Done -----", Foreground::light_gray);
            Console::bell();
        }

        if($kill)
            die;
    }

}