<?php

namespace BrickLayer\Lay\Core\Api;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\Domain;

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
        $this->request->print_as_json();
    }

    public final function load_brick_hooks(string ...$namespaces) : void
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

            if(in_array($cmd_class, $namespaces, true))
                continue;

            $cmd_class = "\\$cmd_class";

            try{
                $brick = new \ReflectionClass($cmd_class);
            } catch (\ReflectionException $e){
                Exception::throw_exception($e->getMessage(), "ReflectionException");
            }

            try {
                $brick = $brick->newInstance();
            } catch (\ReflectionException $e) {
                Exception::throw_exception($e->getMessage(), "$brick::ApiError");
            }

            try {
                $brick->hooks();
            } catch (\Error|\Exception $e) {
                Exception::throw_exception($e->getMessage(), "$brick::HookError");
            }
        }
    }
}
