<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core;

use BrickLayer\Lay\BobDBuilder\Helper\Console\Console;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Foreground;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Style;
use BrickLayer\Lay\Core\Enums\LayMode;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Orm\SQL;

class CoreException
{
    use IsSingleton;

    public static function new(): self
    {
        return self::instance();
    }

    private static string $message;
    private static bool $already_caught = false;
    private static bool $show_internal_trace = true;
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
                "exception_object" => $exception,
                "show_internal_trace" => $opts['show_internal_trace'] ?? null,
            ]
        );
    }

    public function hide_internal_trace() : void
    {
        self::$show_internal_trace = false;
    }

    /**
     * @throws \Exception
     */
    public function kill_with_trace() : void
    {
        if($this->get_env() !== "DEVELOPMENT")
            $this->use_exception(
                "WrongEnvInvoke",
                "You are throwing an exception with the wrong method on a production environment.\n"
                . "*kill_and_trace()* method is meant to be used in a development environment only. \n"
                . "If you are in a development environment, please use *\BrickLayer\Lay\Core\LayConfig::\$ENV_IS_DEV = true;* before calling this method"
            );

        $this->show_exception([
                "kill" => true,
                "use_lay_error" => true,
                "exception_type" => 'error',
                "show_exception_trace" => true,
                "show_internal_trace" => true,
            ]
        );
    }

    private function container(?string $title, ?string $body, array $other = []): string
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
        $show_internal_trace = $other['show_internal_trace'] ?? self::$show_internal_trace;

        if (!empty(@$other['raw'])) {
            foreach ($other['raw'] as $k => $r) {
                $this->convertRaw($r, $k, $body);
            }
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? ($cli_mode ? "CLI MODE" : 'unknown');
        $ip = LayConfig::get_ip();
        $os = LayConfig::get_os();
        $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_HOST'] ?? $referer;
        $cors_active = LayConfig::cors_active() ? "ACTIVE" : "INACTIVE";
        $headers_str = "";
        $headers_html = "";

        foreach (LayConfig::get_header("*") as $k => $v) {
            if(in_array($k, ["Cookie", "Accept-Language", "Accept-Encoding"], true))
                continue;

            $headers_str .= "\n  [$k] $v";
            $headers_html .= "<b style='color:#dea303'>[$k]</b> <span>$v</span> <br>";
        }

        $stack = "";
        $stack_raw = "";

        $app_index = 0;
        $internal_index = 0;

        $internal_traces = "";
        $internal_traces_raw = "";

        foreach ($other['stack'] as $v) {
            if (!isset($v['file']) && !isset($v['line']))
                continue;

            $is_internal = str_contains($v['file'], "bricklayer/structure/src/") || str_contains($v['file'], "bricklayer/structure/src/");

            if(!$show_internal_trace && $is_internal)
                continue;

            $k = $is_internal ? ++$internal_index : ++$app_index;

            $last_file = explode(DIRECTORY_SEPARATOR, $v['file']);
            $last_file = end($last_file);
            $sx = <<<STACK
                <div style="color: #fff; padding-left: 20px">
                    <div>#$k: {$v['function']}(...)</div>
                    <div><b>$last_file ({$v['line']})</b></div>
                    <span style="white-space: nowrap; word-break: keep-all">{$v['file']}:<b>{$v['line']}</b></span>
                    <hr>
                </div>
            STACK;
            $sx_raw = <<<STACK
              -#$k: {$v['function']} {$v['file']}:{$v['line']}

            STACK;

            if($is_internal) {
                $internal_traces .= $sx;
                $internal_traces_raw .= $sx_raw;
                continue;
            }

            $stack .= $sx;
            $stack_raw .= $sx_raw;
        }

        if($show_internal_trace && $internal_traces) {
            $internal_traces = "<details><summary style='margin-bottom: 10px'><span style='font-size: 20px; font-weight: bold; cursor: pointer;'>Internal Trace [$internal_index]</span></summary>$internal_traces</details>";
            $stack_raw .= <<<RAW
             ___INTERNAL___
            $internal_traces_raw
            RAW;
        }

        $stack_raw = <<<STACK
         HOST: $origin
         REF: $referer
         CORS: $cors_active
         IP: $ip
         OS: $os
         HEADERS: $headers_str
         ___APP___
        $stack_raw
        STACK;


        if($title)
            self::$message = $title . " \n" . strip_tags($body);

        if ($display) {
            $ERROR_BODY = <<<DEBUG
                <details style='padding-left: 5px; margin: 5px 0 10px'>
                    <summary style="margin-bottom: 10px"><span style="font-size: 20px; font-weight: bold; cursor: pointer;">X-INFO</span></summary>
                    <b>ENV:</b> <span style="color: #dea303">$env</span> <br>
                    <b>HOST:</b> <span style='color:#00ff80'>$origin</span> <br> 
                    <b>REFERRER:</b> <span style='color:#00ff80'>$referer</span> <br> 
                    <b>CORS:</b> <span style='color:#00ff80'>$cors_active</span> <br> 
                    <b>IP:</b> <span style='color:#00ff80'>$ip</span> <br>  
                    <b>OS:</b> <span style='color:#00ff80'>$os</span> <br>
                    <b>HEADERS:</b> <div style='color:#00ff80; font-size: 16px; padding: 0 10px'>$headers_html</div>
                </details>
                <details open>
                    <summary style="margin-bottom: 10px"><span style="font-size: 20px; font-weight: bold; cursor: pointer;">App Trace [$app_index]</span></summary>
                    $stack
                </details>
                $internal_traces
                DEBUG;

            if(!$title) {
                $display = '<div style="min-height: 300px; background:#1d2124;padding:10px;color:#fffffa;overflow:auto;">' . $ERROR_BODY .'</div>';

                if($cli_mode)
                    print $stack_raw;
                else
                    echo $display;

                return "kill";
            }

            $display = <<<DEBUG
            <div style="min-height: 300px; background:#1d2124;padding:10px;color:#fffffa;overflow:auto; margin: 0 0 15px">
                <h3 style='color: $title_color; margin: 2px 0'> $title </h3>
                <div style='color: $body_color; font-weight: bold; margin: 5px 0;'> $body </div><br>
                $ERROR_BODY
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

            echo "Your attention is needed at the backend, check your Lay error logs for details";
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

        if(isset($opt['title']))
            $act = $this->container(
                $opt['title'],
                $opt['body_includes'],
                [
                    "stack" => $trace,
                    "core" => $type,
                    "act" => @$opt['kill'] ? "kill" : "allow",
                    "raw" => $opt['raw'],
                    "show_internal_trace" => $opt['show_internal_trace'],
                ]
            );

        if(!isset($opt['title'])) {
            $act = "kill";
            $this->container(
                null,
                null,
                [
                    "stack" => $trace,
                    "core" => $type,
                    "act" => $act,
                    "show_exception_trace" => $opt['show_exception_trace'],
                    "show_internal_trace" => $opt['show_internal_trace'],
                ]
            );
        }

        if ($act == "kill") {
            self::$already_caught = true;
            error_reporting(0);
            ini_set('error_log', false);
            throw new \Exception(self::$message, 914);
        }
    }
}