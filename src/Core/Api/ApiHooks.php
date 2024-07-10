<?php

namespace BrickLayer\Lay\Core\Api;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;

abstract class ApiHooks
{
    public readonly ApiEngine $request;

    public function __construct(
        private bool $prefetch = true,
        private bool $print_end_result = true,
        private bool $pre_connect = true,
    ) {
        if(!isset($this->request))
            $this->request = ApiEngine::new();
    }

    public function prefetch(bool $option) : void
    {
        $this->prefetch = $option;
    }

    public function print_result(bool $option) : void
    {
        $this->print_end_result = $option;
    }

    public function preconnect(bool $option) : void
    {
        $this->pre_connect = $option;
    }

    public function pre_init() : void {}

    public function post_init() : void {}

    public final function init() : void
    {
        $this->pre_init();

        if($this->pre_connect)
            LayConfig::connect();

        if($this->prefetch)
            $this->request::fetch();

        $this->hooks();

        $this->post_init();
        $this->request::end($this->print_end_result);
    }

    public function hooks() : void
    {
        $this->load_brick_hooks();
    }

    public final function load_brick_hooks(string ...$class_to_ignore) : void
    {
        $bricks_root = LayConfig::server_data()->bricks;

        foreach (scandir($bricks_root) as $brick) {
            if (
                $brick == "." ||
                $brick == ".." ||
                !file_exists($bricks_root . $brick . DIRECTORY_SEPARATOR . "Api" . DIRECTORY_SEPARATOR . "Hook.php")
            )
                continue;

            $cmd_class = "bricks\\$brick\\Api\\Hook";

            if(in_array($cmd_class, $class_to_ignore, true))
                continue;

            try{
                $brick = new \ReflectionClass($cmd_class);
            } catch (\ReflectionException $e){
                Exception::throw_exception($e->getMessage(), "ReflectionException", exception: $e);
            }

            try {
                $brick = $brick->newInstance();
            } catch (\ReflectionException $e) {
                $brick = $brick::class ?? "ApiHooks";
                Exception::throw_exception($e->getMessage(), "$brick::ApiError", exception: $e);
            }

            try {
                $brick->hooks();
            } catch (\Error|\Exception $e) {
                $brick = $brick::class;
                Exception::throw_exception($e->getMessage(), "$brick::HookError", exception: $e);
            }
        }
    }

    public final function dump_all_endpoints() : array
    {
        $this->request::$DEBUG_MODE = true;

        LayConfig::connect();
        $this->request::fetch();
        $this->load_brick_hooks();

        return $this->request->get_registered_uris();
    }
}
