<?php

namespace BrickLayer\Lay\Libs\Deploy;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\Cron\LayCron;
use Closure;

class Action
{
    private bool $log_ddos = false;

    private Closure $pre_hook_action;
    private Closure $post_hook_action;

    private string $action_id;
    private string $hook_file;

    public function __construct(private readonly string $branch)
    {
        $this->hook_file = LayConfig::server_data()->temp . "git_webhook.txt";

        file_put_contents($this->hook_file, "[" . date("Y-m-d H:i:s e") . "]\n");
    }

    public static function branch(string $name) : self
    {
        return new self($name);
    }

    public function id(string $uid) : self
    {
        $this->action_id = $uid;
        return $this;
    }

    public function log(string $text) : void
    {
        file_put_contents($this->hook_file, "$text\n", FILE_APPEND);
    }

    public function log_ddos() : self
    {
        $this->log_ddos = true;
        return $this;
    }

    public function pre_invalidate(callable $action) : self
    {
        $this->pre_hook_action = $action;
        return $this;
    }

    public function post_invalidate(callable $action) : self
    {
        $this->post_hook_action = $action;
        return $this;
    }

    /**
     * @param array<string>|null $origins
     * @param callable|null $headers
     * @return $this
     */
    public function cors(?array $origins = null, ?callable $headers = null) : self
    {
        if(
            !LayConfig::set_cors(
                allowed_origins: $origins ?? [ "https://github.com" ],
                fun: $headers ?? function () {
                    header("Access-Control-Allow-Credentials: true");
                    header("Access-Control-Allow-Headers: *");
                    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
                }
            )
        ) die;

        return $this;
    }

    public function run() : void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? null;

        if($method !== 'POST') {
            if($this->log_ddos)
                LayException::throw("Wrong mode of contact", "GitADMismatched");

            return;
        }

        $action_id = $_GET['brick'] ?? null;

        if($action_id !== $this->action_id) {
            if($this->log_ddos)
                LayException::throw("Invalid endpoint met! please check your uuid and try again", "GitADMismatched");

            return;
        }

        $post = json_decode($_POST['payload'] ?? null);

        if(!isset($post->pull_request)) {
            $this->log($post?->action?->zen);
            return;
        }

        if($post->pull_request->state != "closed") {
            $this->log("Pull Request: " . $post->pull_request->state);
            return;
        }

        $main_branch = $this->branch;

        $log = "-- Stash Old Changes: " . shell_exec("git stash 2>&1 &") . " \n";
        $log .= "-- Git Checkout: " . shell_exec("git checkout $main_branch 2>&1 &") . "\n";

        $log .= "-- Submodule Init: " . shell_exec("git submodule init 2>&1 &") . " \n";
        $log .= "-- Submodule Reset: " . shell_exec('git submodule foreach --recursive  "git fetch origin && git reset --hard" 2>&1 &') . " \n";
        $log .= "-- Submodule Pull: " . shell_exec('git pull --recurse-submodules 2>&1 &') . "\n";

        $log .= "-- Git Fetch: " . shell_exec('git fetch --all 2>&1 &') . "\n";
        $log .= "-- Git Reset: " . shell_exec("git reset --hard origin/$main_branch 2>&1 &") . "\n";

        if(isset($this->pre_hook_action)) {
            $log .= "\n";
            $log .= "--++ Pre Invalidate Hooks Actions\n";
            $log .= ($this->pre_hook_action)($this);
        }

        // Invalidate cached hooks using the Apex APi Class File
        $log .= "\n";
        $log .= "-- Invalidating Hooks\n";
        (new \Web\Api\Plaster())->invalidate_hooks();

        if(isset($this->post_hook_action)) {
            $log .= "\n";
            $log .= "--++ Post Invalidate Hooks Actions\n";
            $log .= ($this->post_hook_action)($this);
        }

        $log .= "\n";
        $log .= "-- Symlinks are being refreshed\n";
        $bob = LayConfig::server_data()->root . "bob";
        $log .= "-- Link Refresh: " . shell_exec("php $bob link:refresh 2>&1 &") . "\n";


        // push composer deployment for later execution to avoid 504 (timeout error)
        $log .= "-- Cronjob: " . LayCron::new()
            ->job_id("update-composer-pkgs")
            ->every_minute()->just_once()
            ->new_job("bob up_composer")['msg'];

        $this->log($log);

        print "Complete! Pending Post Hook Actions";
    }
}