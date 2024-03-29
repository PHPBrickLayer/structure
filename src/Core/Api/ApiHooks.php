<?php

namespace BrickLayer\Lay\Core\Api;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;

abstract class ApiHooks
{
    public readonly ApiEngine $request;

    public function __construct(
        private readonly bool $prefetch = true,
        private readonly bool $print_end_result = true,
    ) {
        if(!isset($this->request))
            $this->request = ApiEngine::new();
    }

    public final function init() : void
    {
        LayConfig::connect();

        if($this->prefetch)
            $this->request::fetch();

        $this->hooks();
        $this->request::end($this->print_end_result);
    }

    public function hooks() : void
    {
        $this->request::fetch();
        $this->load_brick_hooks();
        $this->request->print_as();
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
}
