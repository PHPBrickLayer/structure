<?php

namespace BrickLayer\Lay\Core\Api;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayFn;

abstract class ApiHooks extends ApiEngine
{
    /**
     * Alias for $engine
     * @see $engine
     * @var ApiEngine
     */
    public readonly ApiEngine $request;

    public readonly ApiEngine $engine;

    final public function __construct(
        protected bool $prefetch = true,
        protected bool $print_end_result = true,
        protected bool $pre_connect = true,
    ) {
        if(!isset($this->engine)) {
            $this->engine = $this->start($this::class);
            $this->request = $this->engine;

            if(LayConfig::$ENV_IS_DEV)
                self::set_debug_mode();
        }
    }

    final public function print_result(bool $option) : void
    {
        $this->print_end_result = $option;
    }


    protected static bool $is_mocking = false;

    final public function prefetch(bool $option) : void
    {
        $this->prefetch = $option;
    }

    final public function preconnect(bool $option) : void
    {
        $this->pre_connect = $option;
    }

    protected function pre_init() : void {}

    protected function post_init() : void {}

    final public function init() : void
    {
        $this->pre_init();

        if($this->pre_connect)
            LayConfig::connect();

        if($this->prefetch)
            self::fetch();

        $this->hooks();

        $this->post_init();

        self::end($this->print_end_result);
    }

    public function pre_hook() : void {}

    public function post_hook() : void {}

    public function hooks() : void
    {
        $this->load_brick_hooks();
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

                    $brick->pre_hook();
                    $brick->hooks();
                    $brick->post_hook();

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

    final public function load_brick_hooks(string ...$class_to_ignore) : void
    {
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

            $hook_class->pre_hook();
            $hook_class->hooks();
            $hook_class->post_hook();
        } catch (\Throwable $e) {
            if(is_object($hook_class))
                $hook_class = $hook_class::class;

            if(is_array($hook_class))
                $hook_class = $hook_class['hook'];

            LayException::throw("", $hook_class . "::HookError", $e);
        }
    }

    final public function invalidate_hooks() : void
    {
        self::$is_mocking = true;

        // Mock request
        self::fetch(mock: true);

        // Run same conditions as a regular Api plaster and invalidate cache
        $this->hooks();
    }

    final public function get_all_endpoints() : ?array
    {
        if(self::is_debug_dump_mode() || self::is_debug_override_active())
            return null;

        self::set_debug_dump_mode();
        self::fetch();
        $this->load_brick_hooks();

        return self::all_api_endpoints();
    }

    final public function dump_endpoints_as_json() : void
    {
        if($data = $this->get_all_endpoints())
            LayFn::vd_json($data);
    }


}
