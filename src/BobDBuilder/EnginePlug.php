<?php

namespace BrickLayer\Lay\BobDBuilder;

use BrickLayer\Lay\BobDBuilder\Enum\CmdOutType;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Console;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Background;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Foreground;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\Enums\CustomContinueBreak;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use Error;
use ReflectionClass;

class EnginePlug
{
    /**
     * @var bool
     */
    public bool $show_intro = true;
    public bool $cmd_found = false;
    public bool $force = false;
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
        $this->force = $this->tags['force_action'] ?? $this->force;
        $spun_correct_class = false;

        foreach ($this->cmd_classes as $cmd_class) {
            if($spun_correct_class)
                break;

            if($cmd_class::class != $this->active_cmd_class)
                continue;

            try{
                $cmd_class->_spin();
            }
            catch (\TypeError|\Error|\Exception $e){
                Exception::throw_exception(
                    $e->getMessage(),
                    "BobError " . $cmd_class::class,
                    stack_track: $e->getTrace()
                );
            }
            if($cmd_class::class == $this->active_cmd_class)
                $spun_correct_class = true;
        }
    }

    public function run(int $index, string $arg): CustomContinueBreak
    {
        $this->current_arg = $arg;
        $this->current_index = $index;

        $this->show_intro = $index < 1;

        if ($index == 1)
            $this->typed_cmd = $arg;

        if ($this->arg(self::class, ["--help", "-h", "help"], $this->tags['show_help'], true))
            return CustomContinueBreak::BREAK;

        if ($this->arg(self::class, ["--force", "-f"], $this->tags['force_action'], true))
            return CustomContinueBreak::BREAK;

        foreach ($this->plugged_args as $key => $arg) {
            if ($this->arg($arg['class'], [...$arg['cmd']], $this->tags[$key], ...$arg['value']))
                return CustomContinueBreak::BREAK;
        }

        return CustomContinueBreak::CONTINUE;
    }

    private function load_cmd_classes() : void
    {
        $namespace = explode("\\", self::class);
        array_pop($namespace);
        $namespace = implode("\\", $namespace) . "\\Cmd";

        foreach (scandir(__DIR__ . $this->s . "Cmd") as $class) {
            if ($class == "." || $class == ".." || is_dir($class))
                continue;

            $cmd_class = $namespace . "\\" . explode(".php", $class)[0];

            $class = new ReflectionClass($cmd_class);

            try {
                $class = $class->getMethod('new');
            } catch (\ReflectionException) {
                Exception::throw_exception(
                    " $cmd_class is not a singleton. \n"
                    . " All Cmd classes must be singletons, use the trait `IsSingleton` to clear this error",
                    "IsNotSingleton"
                );
            }

            $class = $class->invoke(null);
            $this->cmd_classes[] = $class;

            try {
                $class->_init($this);
            } catch (Error|\Exception $e) {
                Exception::throw_exception($e->getMessage(), "BobError");
            }
        }
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
        $this->write($message, CmdOutType::SUCCESS, $opts);
    }

    public function write_fail(string $message, array $opts = []) : void {
        $this->write($message, CmdOutType::FAIL, $opts);
    }

    public function write_warn(string $message, array $opts = []) : void {
        $this->write($message, CmdOutType::WARN, $opts);
    }

    public function write(string $message, CmdOutType $type, array $opts = []): void
    {
        $kill = $opts['kill'] ?? true;
        $close_talk = $opts['close_talk'] ?? true;
        $open_talk = $opts['open_talk'] ?? true;
        $current_cmd = $this->active_cmd ?: ($opts['current_cmd'] ?? "");
        $hide_cur_cmd = $opts['hide_current_cmd'] ?? false;

        $color = match ($type) {
            default => Foreground::normal,
            CmdOutType::SUCCESS => Foreground::green,
            CmdOutType::INFO => Foreground::light_cyan,
            CmdOutType::FAIL => Foreground::red,
        };

        if ($open_talk)
            Console::log("##>>> BobTheBuilder SAYS (::--__--::)", Foreground::light_gray);

        if (!$hide_cur_cmd && !empty($current_cmd)) {
            print "   CURRENT COMMAND ";
            Console::log(
                " $current_cmd ",
                bg_color: Background::cyan
            );
        }

        foreach (explode("\n", $message) as $m) {
            Console::log("   " . $m, $color);
        }

        if ($close_talk)
            Console::log("####> BobTheBuilder DONE TALKING...(-_-)", Foreground::light_gray);

        Console::bell();

        if ($kill)
            die;
    }

}