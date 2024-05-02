<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core;

use BrickLayer\Lay\BobDBuilder\Helper\Console\Console;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Foreground;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Style;
use BrickLayer\Lay\Core\Enums\LayMode;
use BrickLayer\Lay\Core\Traits\IsSingleton;

class CoreException
{
    use IsSingleton;

    public static function new(): self
    {
        return self::instance();
    }

    private static string $message;
    private static bool $already_caught = false;
    private bool $throw_500 = true;

    public function capture_errors(bool $turn_warning_to_errors = false) : void
    {
        set_error_handler(function (int $err_no, string $err_str, string $err_file, int $err_line) use($turn_warning_to_errors)
        {
            if(error_reporting() != E_ALL)
                return false;

            $eol = LayConfig::get_mode() == LayMode::HTTP ? "<br>" : "\n";

            if($err_no === E_WARNING || $err_no === E_USER_WARNING) {
                $this->use_exception(
                    "LayWarning",
                    $err_str . $eol
                    . "File: " . $err_file . ":$err_line" . $eol,
                    kill: $turn_warning_to_errors,
                    raw: ["err_code" => $err_no]
                );

                return true;
            }

            $this->use_exception(
                "LayError",
                $err_str . $eol
                . "File: " . $err_file . ":$err_line" . $eol,
                raw: ["err_code" => $err_no]
            );

            return true;
        }, E_ALL|E_STRICT);
    }

    public function get_env(): string
    {
        return LayConfig::$ENV_IS_DEV ? "DEVELOPMENT" : "PRODUCTION";
    }

    /**
     * @throws \Exception
     */
    public function use_exception(string $title, string $body, bool $kill = true, array $trace = [], array $raw = [], bool $use_lay_error = true, array $opts = [], $exception = null, bool $throw_500 = true): void
    {
        if($exception) {
            $file_all = $exception->getFile();
            $file = explode(DIRECTORY_SEPARATOR, $file_all);
            $file = end($file);
            $line = $exception->getLine();
            $body = $body ?: $exception->getMessage();

            $body = <<<BDY
            $body
            <div style="font-weight: bold; color: cyan">$file ($line)</div>
            <div style="color: lightcyan">$file_all:<b>$line</b></div>
            BDY;

            $trace = $exception->getTrace();
        }

        $this->throw_500 = $throw_500;

        $this->show_exception([
                "title" => $title,
                "body_includes" => $body,
                "kill" => $kill,
                "trace" => $trace,
                "raw" => $raw,
                "use_lay_error" => $use_lay_error,
                "exception_type" => $opts['type'] ?? 'error',
                "exception_object" => $exception
            ]
        );
    }

    private function container($title, $body, $other = []): string
    {
        $title_color = "#5656f5";
        $body_color = "#dea303";
        $cli_color = Foreground::light_cyan;

        switch ($other['core']){
            default: break;
            case "error":
                $title_color = "#ff0014";
                $body_color = "#ff5000";
                $cli_color = Foreground::red;
                break;
            case "success":
                $title_color = "#1cff03";
                $body_color = "#1b8b07";
                $cli_color = Foreground::green;
                break;
        }

        $env = $this->get_env();
        $display = $env == "DEVELOPMENT" || $other['core'] == "view";
        $cli_mode = LayConfig::get_mode() === LayMode::CLI;

        if (!empty($other['raw'])) {
            foreach ($other['raw'] as $k => $r) {
                $this->convertRaw($r, $k, $body);
            }
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? ($cli_mode ? "CLI MODE" : 'unknown');
        $ip = LayConfig::get_ip();

        $stack = "<div style='padding-left: 5px; color: #5656f5; margin: 5px 0'><b>Referrer:</b> <span style='color:#00ff80'>$referer</span> <br /> <b>IP:</b> <span style='color:#00ff80'>$ip</span></div><div style='padding-left: 10px'>";
        $stack_raw = <<<STACK
         REFERRER: $referer
         IP: $ip

        STACK;

        foreach ($other['stack'] as $k => $v) {
            if (!isset($v['file']) && !isset($v['line']))
                continue;

            $k++;
            $last_file = explode(DIRECTORY_SEPARATOR, $v['file']);
            $last_file = end($last_file);
            $stack .= <<<STACK
                <div style="color: #fff; padding-left: 20px">
                    <div>#$k: {$v['function']}(...)</div>
                    <div><b>$last_file ({$v['line']})</b></div>
                    <span style="white-space: nowrap; word-break: keep-all">{$v['file']}:<b>{$v['line']}</b></span>
                    <hr>
                </div>
            STACK;
            $stack_raw .= <<<STACK
              -#$k: {$v['function']} {$v['file']}:{$v['line']}

            STACK;
        }

        $stack .= "</div>";

        self::$message = $title . " \n" . strip_tags($body);

        if ($display) {
            $display = <<<DEBUG
            <div style="min-height: 300px; background:#1d2124;padding:10px;color:#fffffa;overflow:auto;">
                <h3 style='color: $title_color; margin: 2px 0'> $title </h3>
                <div style='color: $body_color; font-weight: bold; margin: 5px 0;'> $body </div><br>
                <div><b style="color: #dea303">$env ENVIRONMENT</b></div>
                <div>$stack</div>
            </div>
            DEBUG;

            if($cli_mode) {
                $body = strip_tags($body);
                Console::log(" $title ", Style::bold);
                print "---------------------\n";
                Console::log($body, $cli_color);
                print "---------------------\n";
                print $stack_raw;

                return $other['act'] ?? "kill";
            }

            echo $display;

            return $other['act'] ?? "kill";
        }

        else {
            $dir = LayConfig::server_data()->temp;
            $file_log = $dir . DIRECTORY_SEPARATOR . "exceptions.log";

            if (!is_dir($dir)) {
                umask(0);
                mkdir($dir, 0755, true);
            }

            $date = date("Y-m-d H:i:s e");
            $body = strip_tags($body);
            $body = <<<DEBUG
            [$date] $title: $body
            $stack_raw
            DEBUG;

            file_put_contents($file_log, $body, FILE_APPEND);

            echo "<b>Your attention is needed at the backend, check your Lay error logs for details</b>";
            return $other['act'] ?? "allow";
        }
    }

    private function convertRaw($print_val, $replace, &$body): void
    {
        ob_start();
        print_r($print_val);
        echo " <i>(" . gettype($print_val) . ")</i>";
        $x = ob_get_clean();
        $x = empty($x) ? "NO VALUE PASSED" : $x;
        $x = "<span style='margin: 10px 0 1px; color: #65fad8'>$x</span>";
        $body = str_replace($replace, $x, $body);
    }

    /**
     * @throws \Exception
     */
    private function show_exception($opt = []): void
    {
        if(self::$already_caught)
            return;

        if(@LayConfig::get_mode() === LayMode::HTTP && $this->throw_500)
            header("HTTP/1.1 500 Internal Server Error");

        $use_lay_error = $opt['use_lay_error'] ?? true;
        $trace = empty($opt['trace']) ? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) : $opt['trace'];

        $type = $opt['exception_type'];

        if (!$use_lay_error) {
            if($opt['kill'] ?? true) {
                $exception_class = str_replace(" ", "", ucwords($opt['title']));

                if(!class_exists($exception_class)) {
                    $anon_class = new class extends \Exception {};
                    class_alias(get_class($anon_class), $exception_class);
                }

                throw new $exception_class($opt['body_includes'], $type);
            }

            return;
        }

        $act = $this->container(
            $opt['title'],
            $opt['body_includes'],
            [
                "stack" => $trace,
                "core" => $type,
                "act" => @$opt['kill'] ? "kill" : "allow",
                "raw" => $opt['raw'],
            ]
        );


        if ($act == "kill") {
            self::$already_caught = true;
            error_reporting(0);
            ini_set('error_log', false);
            throw new \Exception(self::$message, 914);
        }
    }
}