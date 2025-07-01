<?php

namespace BrickLayer\Lay\Core\Api;

use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayFn;

abstract class ApiHooks extends ApiEngine
{
    /**
     * Alias for $engine
     * @see $engine
     * @var ApiEngine
     * @deprecated call the class directly will be removed in 0.8
     */
    public readonly ApiEngine $request;

    /**
     * @deprecated Call the class directly; will be removed in 0.8
     * @var ApiEngine|ApiHooks
     */
    public readonly ApiEngine $engine;

    protected static bool $is_mocking = false;

    final public function __construct(
        protected bool $print_end_result = true,
        protected bool $security_in_dev = false,
    ) {
        if(!isset($this->engine)) {
            $this->engine = $this->start($this::class);
            $this->request = $this->engine;

            if(LayConfig::$ENV_IS_DEV)
                self::set_debug_mode();
        }
    }

    /**
     * Operations to run before initializing the apex Api Class
     * @return void
     */
    protected function pre_init() : void {}

    /**
     * Operations to run after initializing the apex Api Class
     * @return void
     */
    protected function post_init() : void {}

    protected function security() : bool
    {
        if(!$this->security_in_dev && LayConfig::$ENV_IS_DEV)
            return true;

        if(LayConfig::is_bot()) {
            self::set_response_header(ApiStatus::NOT_ACCEPTABLE);
            return false;
        }

        return true;
    }

    /**
     * Executed by the Domain class and only used by the Apex Api class
     * @return void
     * @throws \Exception
     */
    final public function init() : void
    {
        if(str_starts_with(static::class, "Bricks\\"))
            LayException::throw("You cannot use this method in this class. This method is reserved for the Apex Api Plaster only, not " . static::class);

        $this->pre_init();

        if(!$this->security()) {
            self::end($this->print_end_result);
            return;
        }

        LayConfig::connect();

        self::fetch();

        $this->pre_hook();
        $this->load_brick_hooks();
        $this->post_hook();

        $this->post_init();

        self::end($this->print_end_result);
    }

    /**
     * Only used by Brick classes, not used by Apex class
     * @return void
     */
    abstract protected function hooks() : void;

    /**
     * Operations to run before loading the hooks for both apex and bricks
     * @return void
     */
    protected function pre_hook() : void {}

    /**
     * Operations to run after loading the hooks for both apex and bricks
     * @return void
     */
    protected function post_hook() : void {}

    /**
     * This is public on purpose, so don't change the visibility
     * @return void
     * @throws \Exception
     */
    public final function exec_hooks() : void
    {
        if(!self::$is_mocking && !str_starts_with(static::class, "Bricks\\"))
            LayException::throw("You can only use this method in a Brick Hook class, not: " . static::class);

        $this->pre_hook();
        $this->hooks();
        $this->post_hook();
    }

    /**
     * @param string $route_uri
     * @param array{
     *    var: array{ hook: string },
     *    const: array{ hook: string },
     * } $endpoints
     * @return null|array{
     *     hook: string, // ApiHook Namespace\\ClassName
     * }
     */
    private function interpolate_endpoints(string $route_uri, array $endpoints) : ?array
    {
        $route_uri = trim($route_uri, "/");

        $class = $endpoints['const'][$route_uri] ?? null;

        if($class)
            return $class;

        $route_uri_arr = explode("/", $route_uri);
        $last_item_current_request = end($route_uri_arr);

        $uri_len = count($route_uri_arr);
        $last_index_route_uri = $uri_len - 1;

        $key = null;

        foreach ($endpoints['var'] as $var_route => $class_hook) {
            if(!empty($key)) break;

            $vr = explode("/", $var_route);

            if(count($vr) != $uri_len)
                continue;

            foreach ($vr as $i => $v) {
                if($key) break;

                if($v !== $route_uri_arr[$i] && !str_starts_with($v, "{")) break;

                if($v === $route_uri_arr[$i]) {
                    if($v == $last_item_current_request && $last_index_route_uri == $i) {
                        $key = $var_route;
                        break;
                    }

                    continue;
                }

                /**
                 * If request has a {placeholder}, then process it and store for future use
                 */
                if(str_starts_with($v, "{")) {
                    // If placeholder is the last item on the list, mark the route as found
                    if(!isset($route_uri_arr[$i + 1])) {
                        $key = $var_route;
                    }
                }
            }

        }

        if(!$key)
            return null;

        return $endpoints['var'][$key];
    }

    private static function cache_hooks(bool $invalidate = false, string ...$class_to_ignore) : array
    {
        $invalidate = self::$is_mocking ? true : $invalidate;
        return LayFn::var_cache("_LAY_BRICKS_", function () use ($class_to_ignore) {
            $bricks_root = LayConfig::server_data()->bricks;
            $hooks = [
                "var" => [],
                "const" => [],
            ];

            foreach (scandir($bricks_root) as $brick) {
                if (
                    $brick == "." || $brick == ".." ||
                    !file_exists($bricks_root . $brick . DIRECTORY_SEPARATOR . "Api" . DIRECTORY_SEPARATOR . "Hook.php")
                ) continue;

                $cmd_class = "Bricks\\$brick\\Api\\Hook";

                if (in_array($cmd_class, $class_to_ignore, true))
                    continue;

                try{
                    $brick = new \ReflectionClass($cmd_class);
                } catch (\ReflectionException $e) {
                    LayException::throw("", "ReflectionException", $e);
                }

                try {
                    $brick = $brick->newInstance();
                } catch (\Throwable $e) {
                    $brick = $brick::class ?? "ApiHooks";
                    LayException::throw("", "$brick::ApiError", $e);
                }

                try {
                    self::__indexing_routes();

                    /**
                     * @var self $brick
                     */
                    $brick->exec_hooks();

                    $d = $brick->__indexed_routes();

                    if(empty($d)) continue;

                    $hooks['var'] = array_merge($hooks['var'], $d['var']);
                    $hooks['const'] = array_merge($hooks['const'], $d['const']);
                } catch (\Throwable $e) {
                    LayException::throw("", $brick::class . "::RouteIndexingError", $e);
                }
            }

            self::__indexing_routes_done();
            return $hooks;
        }, $invalidate);
    }

    /**
     * This method is ONLY used by the Apex Api Plaster, and should not be called by Bricks Hooks, it will throw an error
     * @param string ...$class_to_ignore
     * @return void
     * @throws \Exception
     */
    private function load_brick_hooks(string ...$class_to_ignore) : void
    {
        if(str_starts_with(static::class, "Bricks\\"))
            LayException::throw("You cannot use this method in this class. This method is reserved for the Apex Api Plaster only");

        $hooks = $this->cache_hooks(false, ...$class_to_ignore);

        if(self::$is_mocking)
            return;

        $hook_class = $this->interpolate_endpoints($this->get_uri_as_str(), $hooks);

        if(!$hook_class) {
            self::end();
            return;
        }

        try {
            $hook_class = new $hook_class['hook']();

            /**
             * @var self $hook_class
             */
            $hook_class->exec_hooks();
        } catch (\Throwable $e) {
            if(is_object($hook_class))
                $hook_class = $hook_class::class;

            if(is_array($hook_class))
                $hook_class = $hook_class['hook'];

            LayException::throw("", $hook_class . "::HookError", $e);
        }
    }

    /**
     * This method is used by GitAutoDeploy for invalidating the cached hooks whenever there is a new deployment,
     * so that the app will have the latest hooks for consumption.
     *
     * It is a very important method that should never be deleted!
     *
     * The GitAutoDeploy calls this method from the primary Api Plaster class and ensures all measures set in the class
     * are fulfilled then caching the routes for later consumption
     * @return void
     */
    final public function invalidate_hooks() : void
    {
        self::$is_mocking = true;

        // Mock request
        self::fetch(mock: true);

        // Run same conditions as a regular Api plaster and invalidate cache
        $this->exec_hooks();
    }

    final public function get_all_endpoints() : ?array
    {
        if(self::is_debug_dump_mode() || self::is_debug_override_active())
            return null;

        self::set_debug_dump_mode();

        self::fetch();

        $bricks_root = LayConfig::server_data()->bricks;

        foreach (scandir($bricks_root) as $brick) {
            if (
                $brick == "." || $brick == ".." ||
                !file_exists($bricks_root . $brick . DIRECTORY_SEPARATOR . "Api" . DIRECTORY_SEPARATOR . "Hook.php")
            ) continue;

            $cmd_class = "Bricks\\$brick\\Api\\Hook";

            try{
                $brick = new \ReflectionClass($cmd_class);
            } catch (\ReflectionException $e) {
                LayException::throw("", "ReflectionException", $e);
            }

            try {
                $brick = $brick->newInstance();
            } catch (\Throwable $e) {
                $brick = $brick::class ?? "ApiHooks";
                LayException::throw("", "$brick::ApiError", $e);
            }

            /**
             * @var self $brick
             */
            try {
                $brick->exec_hooks();
            } catch (\Throwable $e) {
                LayException::throw("", $brick::class . "::RouteIndexingError", $e);
            }
        }

        return self::all_api_endpoints();
    }

    final public function dump_endpoints_as_json() : void
    {
        if($data = $this->get_all_endpoints())
            LayFn::vd_json($data);
    }


}
