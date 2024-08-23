<?php

namespace BrickLayer\Lay\Core\Api;

use BrickLayer\Lay\Core\Api\Enums\ApiReturnType;
use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use JetBrains\PhpStorm\NoReturn;

abstract class ApiHooks
{
    /**
     * Alias for $engine
     * @see $engine
     * @var ApiEngine
     */
    public readonly ApiEngine $request;

    public readonly ApiEngine $engine;

    public function __construct(
        private bool $prefetch = true,
        private bool $print_end_result = true,
        private bool $pre_connect = true,
    ) {
        if(!isset($this->engine)) {
            $this->engine = ApiEngine::start($this::class);
            $this->request = $this->engine;

            if(LayConfig::$ENV_IS_DEV)
                $this->engine::set_debug_mode();
        }
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
            $this->engine::fetch();

        $this->hooks();

        $this->post_init();
        $this->engine::end($this->print_end_result);
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

    public final function get_all_endpoints() : array
    {
        $this->engine::set_debug_dump_mode();
        $this->engine::fetch();
        $this->load_brick_hooks();
        return $this->engine::all_api_endpoints();
    }

    #[NoReturn]
    public final function dump_endpoints_as_json() : void
    {
        $this->engine::set_response_header(ApiStatus::OK, ApiReturnType::JSON);
        echo json_encode($this->get_all_endpoints());
        die;
    }
}
