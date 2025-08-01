<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\Api;

use BrickLayer\Lay\Core\Api\Enums\ApiReturnType;
use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\CoreException;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\Domain;
use BrickLayer\Lay\Core\View\DomainResource;
use BrickLayer\Lay\Libs\ID\Gen;
use BrickLayer\Lay\Libs\LayCache;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use Closure;
use BrickLayer\Lay\Core\Api\Enums\ApiRequestMethod;
use BrickLayer\Lay\Core\Exception;
use JetBrains\PhpStorm\ArrayShape;

//TODO: Cache api list. Find a way to cache the entire api list of the application,
// so that on production, it doesn't have to loop on every request.
// Developer should be able to create a cache for a particular domain, so the framework
// doesn't have to loop through the entire api list to get maybe two apis for a particular domain
// Cache could be a physical file created called api list or something of sorts, but something not so large though.
abstract class ApiEngine {
    private static self $engine;
    private static bool $ALL_DEBUG_OVERRIDE = false;
    private static bool $DEBUG_MODE = false;
    private static bool $DEBUG_DUMP_MODE = false;
    private static bool $ERRORS_AS_JSON = true;

    private const RATE_LIMIT_CACHE_FILE = 'rate_limiter/';

    /**
     * Endpoints can be named, and this is responsible for storing it
     * @var string|null
     */
    private static ?string $route_uri_name;

    /**
     * This is the uri as sent by the application; meaning something like: api/user/1
     * @var string
     */
    private static string $route_uri_raw;

    /**
     * This is only set when a route is found
     * @var string
     */
    private static string $active_route;

    /**
     * This holds details of the current route being processed by the engine. This is always set
     * @var array
     */
    private static array $current_request_uri = [];

    /**
     * This holds details of the current route being processed by the engine. This is always set
     * @var string
     */
    private static string $current_uri_string;

    /**
     * The stores each uris processed by the engine for a selected request method like: get, post, etc.
     * It is only active in debug mode
     * @var array
     */
    private static array $registered_uris = [];

    /**
     * The stores each uris processed by the engine for all request methods.
     * It is only active in debug mode
     * @var array
     */
    private static array $all_uris = [
        "__DEBUG_MODE__" =>
            "Ensure you turn off THIS MODE in production environment, because it will expose " .
            "all your endpoints and heavily impact the performance of your application!",
        "class" => []
    ];

    /**
     * Original request uri from the client. It is broken into an array
     * @example user/profile/1 = [user, profile, 1]
     * @var array
     */
    private static array $route_uri = [];

    /**
     * Headers sent with the request by the client
     * @var array
     */
    private static array $request_header;

    /**
     * The extrapolated values from the active request uri
     * @example /user/profile/{id}/blog/edit/{blog_id}; == [{id}, {blog_id}]
     * @var array
     */
    private static array $uri_variables;

    /**
     * Return value of the `->bind` method for the active route.
     * @var mixed
     */
    private static mixed $bind_return_value;

    /**
     * Return type of the `->bind` method for the active route.
     * @var ApiReturnType
     */
    private static ApiReturnType $method_return_type = ApiReturnType::JSON;

    /**
     * Instruct the engine to use Lay's inbuilt exceptions or PHP exceptions
     * @var bool
     */
    private static bool $use_lay_exception = true;
    private static bool $allow_index_access = true;

    private static bool $indexing_routes = false;
    private array $indexed_routes = [];

    /**
     * Becomes true if a route is found
     * @var bool
     */
    private static bool $route_found = false;

    /**
     * Used to determine if the Engine has fetch the URI already from the request.
     * The engine should only fetch once, so this will prevent multi fetch
     * @var bool
     */
    protected static bool $fetched = false;

    /**
     * Becomes true when the Engine is done running
     * @var bool
     */
    private static bool $request_complete = false;

    private static ?string $version;
    private static ?string $prefix;
    private static ?string $group;
    private static bool $anonymous_group = false;

    /** [ENGINE SCOPE] **/
    private const GLOBAL_API_CLASS = "Web\\Api\\Plaster";
    /**
     * The current API class Engine is processing
     * @var string
     */
    private static string $current_api_class;

    /**
     * Objects that should persist across all Api classes
     * @var array
     */
    private static array $global_scope_props;

    /**
     * Objects that should persist in the class even after being overwritten by a group
     * @var array
     */
    private static array $class_scope_props;

    /**
     * POST, GET, etc.
     * This variable holds the request method of the route being processed by the Engine
     * @var string
     */
    private static string $request_method;
    private static string $active_request_method;

    private static array $middleware_list = [];
    private static ?Closure $current_middleware = null;
    private static bool $using_group_middleware = false;
    private static bool $using_route_middleware = false;

    private static bool $using_route_rate_limiter = false;
    private static array $limiter_group = [];
    private static array $limiter_global = [];
    private static array $limiter_route = [];

    private static function exception(
        string $title,
        string $message,
        \Error|\Exception|null $exception = null,
        array $header = ["code" => 500, "msg" => "Internal Server Error", "throw_header" => true]
    ) : void {
        $active_route = self::$active_route ?? null;

        if(!isset($header['json_error']))
            self::set_response_header($header['code'], ApiReturnType::HTML, $header['msg']);

        $stack_trace = $exception ? $exception->getTrace() : [];
        $active_route = $active_route ? "<div><b>Active Route:</b> [<span style='color: #F3F9FA'>$active_route</span>]</div><br>" : "";
        $message = $active_route . PHP_EOL . $message;

        Exception::throw_exception(
            $message,
            $title,
            true,
            self::$use_lay_exception,
            $stack_trace,
            exception: $exception,
            throw_500: $header['throw_header'],
            error_as_json: self::$ERRORS_AS_JSON,
            json: [
                "code" => $header['code'],
                "message" => $header['msg'],
                "others" => [
                    "active_route" => self::$active_route ?? "",
                    "info" => $header['json_error'] ?? ""
                ],
            ]
        );
    }

    /**
     * Restore global scoped props in case a class has modified it
     * @return void
     */
    private static function restore_global_props() : void
    {
        self::reset_engine();

        if(!isset(self::$global_scope_props))
            return;

        foreach (self::$global_scope_props as $key => $global_scope_prop) {
            self::${$key} = $global_scope_prop;
        }
    }

    /**
     * @param mixed $value
     */
    private static function update_global_props(string $key, mixed $value) : void
    {
        self::update_class_props($key, $value);

        if(self::$current_api_class != self::GLOBAL_API_CLASS)
            return;

        self::$global_scope_props[$key] = $value;
    }

    private static function update_class_props(string $key, mixed $value) : void
    {
        if(self::$current_api_class == self::GLOBAL_API_CLASS)
            return;

        if(isset(self::$group) || self::$anonymous_group)
            return;

        self::$class_scope_props['__CURRENT_API_CLASS__'] = self::$current_api_class;
        self::$class_scope_props[$key] = $value;
    }

    private static function restore_class_props() : void
    {
        self::restore_global_props();

        if(!isset(self::$class_scope_props))
            return;

        if(
            isset(self::$class_scope_props['__CURRENT_API_CLASS__'])
            && self::$class_scope_props['__CURRENT_API_CLASS__'] !== self::$current_api_class
        ) {
            self::$class_scope_props = [];
            return;
        }

        foreach (self::$class_scope_props as $key => $class_scope_prop) {
            if($key == "__CURRENT_API_CLASS__")
                continue;

            self::${$key} = $class_scope_prop;
        }
    }

    private static function clear_group_scope() : void
    {
        if(!isset(self::$group))
            return;

        self::$limiter_group = [];

        if(self::$using_group_middleware) {
            self::$current_middleware = null;
            self::$using_group_middleware = false;
        }

        self::$group = null;
        self::$anonymous_group = false;

        self::restore_class_props();
    }

    private function correct_request_method(bool $throw_exception = true) : bool {
        if(self::$DEBUG_DUMP_MODE) {
            self::$active_request_method = self::$request_method;
            return true;
        }

        if(!isset($_SERVER['REQUEST_METHOD']))
            self::exception("RequestMethodNotFound", "No request method found. You are probably accessing this page illegally!");

        $match = strtoupper($_SERVER['REQUEST_METHOD']) === self::$request_method;

        if($match) {
            self::$active_request_method = self::$request_method;
            return true;
        }

        if($throw_exception)
            self::exception(
                "UnmatchedRequestMethod",
                "Request method for api request [". self::$route_uri_raw ."]; don't match. 
                Check if you have bound you route to a method, it could be using the method of the previous route"
            );

        return false;
    }

    /**
     * Accepts `/` separated URI as arguments.
     * @param string $route_uri
     * @param ApiReturnType $return_type
     * @return $this
     * @example `get/user/list`; is interpreted as => `'get/user/list'`
     * @example `post/user/index/15`; is interpreted as => `'post/user/index/{id}'`
     */
    private function map_request(string $route_uri, ApiReturnType $return_type) : self
    {
        // reset route specific items
        self::$route_uri_name = null;
        self::$limiter_route = [];

        if(!self::$indexing_routes && (self::$route_found || self::$request_complete || !$this->correct_request_method(false)))
            return $this;

        if(!self::$indexing_routes && (!self::$allow_index_access && self::$route_uri[0] == ""))
            return $this;

        self::$uri_variables = [];
        self::$method_return_type = $return_type;

        $route_uri = trim($route_uri, "/");
        self::$current_request_uri = explode("/", $route_uri);
        $last_item_current_request = end(self::$current_request_uri);

        if(isset(self::$group)) {
            $group = explode("/", self::$group);
            self::$current_request_uri = [...$group, ...self::$current_request_uri];
        }

        if(isset(self::$prefix)) {
            $prefix = explode("/", self::$prefix);
            self::$current_request_uri = [...$prefix, ...self::$current_request_uri];
        }

        if(isset(self::$version))
            self::$current_request_uri = [...[self::$version], ...self::$current_request_uri];

        // Make it possible to access /api/ and just prefixes or just versions, like /api/v1/
        if(self::$allow_index_access && $last_item_current_request == "") {
            array_pop(self::$current_request_uri);
            $last_item_current_request = end(self::$route_uri);

            if(count(self::$route_uri) == 1 && empty(self::$current_request_uri))
                self::$current_request_uri = self::$route_uri;
        }

        if(self::$indexing_routes) {
            $uri = self::stringify_request();

            $is_var  = str_contains($uri, "{");

            if(empty($this->indexed_routes)) {
                $this->indexed_routes = [
                    "var" => [],
                    "const" => [],
                ];
            }

            $d = [
                $uri => [
                    "hook" => static::class,
                ]
            ];

            $this->indexed_routes = [
                "var" => $is_var ? array_merge($this->indexed_routes['var'], $d) : $this->indexed_routes['var'],
                "const" => !$is_var ? array_merge($this->indexed_routes['const'], $d) : $this->indexed_routes['const'],
            ];

            return $this;
        }

        if(!self::$DEBUG_DUMP_MODE && (count(self::$route_uri) !== count(self::$current_request_uri)))
            return $this;

        $last_index_route_uri = count(self::$current_request_uri) - 1;

        foreach (self::$current_request_uri as $i => $curren_req_entry) {
            if (self::$route_uri[$i] !== $curren_req_entry && !str_starts_with($curren_req_entry, "{"))
                break;

            if(self::$route_uri[$i] === $curren_req_entry) {
                if($curren_req_entry == $last_item_current_request && $last_index_route_uri == $i)
                    self::$route_found = true;

                continue;
            }

            /**
             * If request has a {placeholder}, then process it and store for future use
             */
            if(str_starts_with($curren_req_entry, "{")) {
                self::$uri_variables['args'][] = self::$route_uri[$i];

                // If placeholder is the last item on the list, mark the route as found
                if(!isset(self::$current_request_uri[$i + 1]))
                    self::$route_found = true;
            }
        }

        self::save_request_for_debug();

        if(self::$route_found) {
            self::$active_route = self::stringify_request(false);
        }

        return $this;
    }

    private static function stringify_request(bool $always_stringify = true) : string
    {
        $stringify = fn(): string => implode("/", self::$current_request_uri);

        if($always_stringify)
            self::$current_uri_string = $stringify();
        else
            self::$current_uri_string ??= $stringify();

        return self::$current_uri_string;
    }

    private static function save_request_for_debug() : void
    {
        if(!self::$DEBUG_DUMP_MODE && !self::$DEBUG_MODE)
            return;

        self::stringify_request();

        $uri_obj = self::matched_uri_obj();

        if (self::$DEBUG_DUMP_MODE)
            self::$all_uris['class'][self::$current_api_class][] = $uri_obj;

        if (self::$DEBUG_MODE && !self::$DEBUG_DUMP_MODE)
            self::$registered_uris[] = $uri_obj;
    }

    /**
     * Entry method to initialize the engine
     * @param string $class_scope
     * @return self
     */
    public function start(string $class_scope) : self
    {
        self::$engine = $this;

        self::$current_api_class = $class_scope;

        self::restore_global_props();

        return $this;
    }

    public static function set_response_header(int|ApiStatus $code, ?ApiReturnType $return_type = null, ?string $message = null, bool $end_request = true, bool $kill_process = false) : void
    {
        LayFn::header($_SERVER['SERVER_PROTOCOL'] . " " . ApiStatus::extract_status($code, $message));

        switch ($return_type) {
            case ApiReturnType::JSON:
                LayFn::header("Content-Type: application/json");
                break;
            case ApiReturnType::HTML:
                LayFn::header("Content-Type: text/html");
                break;
            case ApiReturnType::XML:
                LayFn::header("Content-Type: text/xml");
                break;
            case ApiReturnType::STREAM:
                LayFn::header("Content-Type: text/event-stream");
                break;
            default:
                LayFn::header("Content-Type: text/plain");
                break;
        }

        if($end_request)
            self::$engine->set_return_value();

        if($kill_process)
            exit(is_int($code) ? $code : $code->value);
    }

    /**
     * Add a header to direct the client to cache a response
     * @param int|null $last_mod
     * @param array $cache_control
     * @return void
     */
    public static function add_cache_header(
        ?int $last_mod = null,
        #[ArrayShape([
            'max_age' => 'int|string',
            'public' => 'bool'
        ])] array $cache_control = []
    ) : void
    {
        if(headers_sent() || CoreException::$HAS_500)
            return;

        header_remove("Pragma");
        header_remove("Expires");

        LayFn::header("Accept-Ranges: bytes");

        if(isset($cache_control['max_age']) && is_string($cache_control['max_age']))
            $cache_control['max_age'] = LayDate::in_seconds($cache_control['max_age']);

        $cache_control = [
            "max_age" => $cache_control['max_age'] ?? LayDate::in_seconds("1 day"),
            "public" => @!$cache_control['public'] ? 'private' : 'public'
        ];

        LayFn::header("Cache-Control: max-age={$cache_control['max_age']}, {$cache_control['public']}");
        LayFn::header("Etag: \"" . Gen::uuid() . "\"");

        if($last_mod)
            LayFn::header("Last-Modified: " . LayDate::date($last_mod, format_index: 3));
    }

    /**
     * @param string $prefix The prefix of the uri request. This is especially useful for multiple requests with same prefix
     * @example
     *  - /admin/profile
     *  - /admin/list
     *  - /admin/store
     *  - /admin/retire/25
     * One can represent this as:
     * LayRequestHandler::fetch()->prefix("admin")->get("profile")->get("list")->post("store")->delete("retire","{id}")
     * @return $this
     */
    public function prefix(string $prefix) : self {
        self::$prefix = trim($prefix, "/");
        self::update_global_props("prefix", self::$prefix);
        return $this;
    }

    public function clear_prefix() : void {
        self::$prefix = null;
        self::update_global_props("prefix", self::$prefix);
    }

    /**
     * Add version to your api
     * @param string $version example: v1
     * @return void
     */
    public function set_version(string $version) : void
    {
        self::$version = str_replace("/","", $version);
        self::update_global_props("version", self::$version);
    }

    public function clear_version() : void {
        self::$version = null;
        self::update_global_props("version", self::$version);
    }

    /**
     * @param string $name Group name
     * @param Closure $grouped_requests a closure filled with a list of requests that depend on the group name
     * @return $this
     * @example
     * This group will serve the following routes:
     * `user/register`
     * `user/login`
     * `$req->group("user", function(LayRequestHandler $req) {
    $req->post("register")->bind(fn() => SystemUsers::new()->register())
    ->post("login")->bind(fn() => SystemUsers::new()->login());
    })`
     */
    public function group(string $name, Closure $grouped_requests) : self {
        if(self::$request_complete)
            return $this;

        self::$group = trim($name, "/");
        $grouped_requests($this);

        self::clear_group_scope();

        return $this;
    }

    /**
     * @see group()
     * @param Closure ...$grouped_requests A series of grouped requests that don't have group names
     * @return $this
     */
    public function groups(Closure ...$grouped_requests) : self {
        if(self::$request_complete)
            return $this;

        self::$anonymous_group = true;

        foreach ($grouped_requests as $request) {
            if(self::$request_complete)
                return $this;

            $request($this);
        }

        self::clear_group_scope();

        return $this;
    }

    public function group_limit(int $requests, string $interval, ?string $key = null) : self
    {
        if(self::$request_complete)
            return $this;

        $use_limiter = true;
        $is_grouped = false;
        $uri_beginning = [];
        $args = [$requests, $interval, $key];

        if(isset(self::$version))
            $uri_beginning[0] = self::$version;

        if(isset(self::$prefix))
            $uri_beginning = array_merge($uri_beginning, explode("/", self::$prefix));

        if(isset(self::$group)) {
            $uri_beginning = array_merge($uri_beginning, explode("/", self::$group));
            $is_grouped = true;
        }

        if(self::$anonymous_group)
            $is_grouped = true;

        if($is_grouped) {
            foreach ($uri_beginning as $i => $begin) {
                $use_limiter = $begin == @self::$route_uri[$i];
            }
        }

        if(!self::$DEBUG_DUMP_MODE && !$use_limiter)
            return $this;

        if($is_grouped)
            self::$limiter_group = $args;
        else
            self::$limiter_global = $args;

        self::update_global_props("limiter_global", $args);

        return $this;
    }

    public function limit(int $requests, string $interval, ?string $key = null, string $__INTERNAL_TYPE__ = "ROUTE") : self
    {
        if(LayConfig::$ENV_IS_DEV || !self::$DEBUG_DUMP_MODE && (!self::$route_found || self::$request_complete || self::$using_route_rate_limiter))
            return $this;

        if($__INTERNAL_TYPE__ == "ROUTE")
            self::$limiter_route = [$requests, $interval, $key];

        self::$using_route_rate_limiter = true;

        if(self::$DEBUG_DUMP_MODE)
            return $this;

        $cache = LayCache::new()->cache_file(self::RATE_LIMIT_CACHE_FILE . DomainResource::get()->domain->domain_referrer . ".json");

        $key = $key ?? LayConfig::get_ip();
        $key = Escape::clean(
            $key . (self::$route_uri_name ?? self::$route_uri_raw),
            EscapeType::P_URL, [
                'strict' => false,
                'p_url_replace' => "_"
            ]
        );

        $limit = $cache->read($key, true);

        $request_count = (int) ($limit['request_count'] ?? 1);

        $expire = $limit['expire'] ?? null;
        $requests_allowed = $limit['requests_allowed'] ?? $requests;

        if($expire == null || LayDate::expired($expire)) {
            $cache->store($key, [
                "request_count" => 1,
                "expire" => LayDate::date($interval, figure: true),
                "interval" => $interval,
                "requests_allowed" => $requests,
            ]);

            return $this;
        }

        $cache->update([$key, "request_count"], $request_count + 1);

        if($request_count > $requests_allowed) {
            $retry_after = LayDate::in_seconds($interval);

            LayFn::header("Retry-After: $retry_after");
            self::set_response_header(ApiStatus::TOO_MANY_REQUESTS, ApiReturnType::JSON);

            self::set_return_value([
                "code" => ApiStatus::TOO_MANY_REQUESTS->value,
                "msg" => "TOO MANY REQUESTS",
                "message" => "TOO MANY REQUESTS",
                "expire" => $expire,
                "retry_after" => $retry_after,
            ]);
        }

        return $this;
    }

    public function name(string $uri_name) : self
    {
        self::$route_uri_name = $uri_name;
        return $this;
    }

    public function post(string $route_uri, ApiReturnType $return_type = ApiReturnType::JSON) : self {
        self::$request_method = ApiRequestMethod::POST->value;

        return $this->map_request($route_uri, $return_type);
    }

    public function get(string $route_uri, ApiReturnType $return_type = ApiReturnType::JSON) : self {
        self::$request_method = ApiRequestMethod::GET->value;

        return $this->map_request($route_uri, $return_type);
    }

    public function put(string $route_uri, ApiReturnType $return_type = ApiReturnType::JSON) : self {
        self::$request_method = ApiRequestMethod::PUT->value;

        return $this->map_request($route_uri, $return_type);
    }

    public function head(string $route_uri, ApiReturnType $return_type = ApiReturnType::JSON) : self {
        self::$request_method = ApiRequestMethod::HEAD->value;

        return $this->map_request($route_uri, $return_type);
    }

    public function delete(string $route_uri, ApiReturnType $return_type = ApiReturnType::JSON) : self {
        self::$request_method = ApiRequestMethod::DELETE->value;

        return $this->map_request($route_uri, $return_type);
    }

    private static function reset_engine() : void
    {
        self::$version = null;
        self::$prefix = null;
        self::$group = null;
        self::$anonymous_group = false;

        self::$current_middleware = null;
        self::$using_group_middleware = false;
        self::$using_route_middleware = false;

        self:: $using_route_rate_limiter = false;
        self:: $limiter_group = [];
        self:: $limiter_global = [];
        self:: $limiter_route = [];
    }

    /**
     * @param mixed $return_array
     *
     */
    private static function set_return_value(mixed $return_array = null) : void
    {
        self::$bind_return_value = $return_array ?? self::$bind_return_value ?? null;
        self::$route_found = true;
        self::$request_complete = true;
    }

    /**
     * When used, this method runs when a single route is hit, before getting to the bound method.
     * The callback should return an array.
     * The array should be "code" => 200;
     * If it doesn't return 200, the bound method will not run.
     *
     * @param callable $callback
     * @return self
     * @example
     * `ApiEngine::new()->request->post('client/transactions/buy')
     * ->middleware(fn() => validate_session())
     * ->bind(fn() => Transactions::new()->buy());
     * `
     */
    public function middleware(callable $callback) : self
    {
        if(self::$request_complete || !self::$route_found || self::$DEBUG_DUMP_MODE || self::$indexing_routes)
            return $this;

        $return = $callback();

        if(!isset($return['code']))
            self::exception(
                "MiddlewareError",
                "You middleware must return an array with a key called \"code\", and its value should be 200 if the middleware's condition is met"
            );

        if(isset(self::$middleware_list['__SCOPE__']['fn'])) {
            foreach (self::$middleware_list['__SCOPE__']['fn'] as $fn) {
                $grp_fn = $fn();

                if(!isset($grp_fn['code']))
                    self::exception(
                        "MiddlewareError",
                        "One of your group middlewares for class: " . static::class . " does not return an array with the key 'code' which should return 200 if conditions are met"
                    );

                if(!ApiStatus::is_ok($return['code'])) {
                    $return = $grp_fn;
                    break;
                }
            }
        }

        // This means the request can go to the server
        if(ApiStatus::is_ok($return['code'])) {
            if(isset(self::$current_middleware))
                self::$current_middleware = null;

            self::update_global_props("current_middleware", self::$current_middleware);

            return $this;
        }

        // If it gets here, it means the middleware has deemed the request invalid
        self::set_return_value($return);

        return $this;
    }

    /**
     * Perform middleware operations on a scope.
     * If performed outside a group, the middleware will affect the routes below where it was defined.
     * If performed in the apex Api class, it will affect all the routes in the project.
     *
     * @param callable $middleware_callback
     * @return self
     * @example
    ```$this->group('client/transactions', function () {
    $this->group_middleware(fn() => validate_session())

    $this->post('buy')->bind(fn() => Transactions::new()->buy());
    $this->post('sell')->bind(fn() => Transactions::new()->sell());
    $this->get('history')->bind(fn() => Transactions::new()->history()
    });```
     * @see middleware
     */
    public function group_middleware(callable $middleware_callback) : self
    {
        if(self::$request_complete || self::$indexing_routes)
            return $this;

        self::$using_group_middleware = false;
        $use_middleware = false;
        $uri_beginning = [];

        if(isset(self::$version))
            $uri_beginning[0] = self::$version;

        if(isset(self::$prefix)) {
            $prefix = explode("/", self::$prefix);
            $uri_beginning = array_merge($uri_beginning, $prefix);
        }

        if(isset(self::$group)) {
            $group = explode("/", self::$group);
            $uri_beginning = array_merge($uri_beginning, $group);
        }

        foreach ($uri_beginning as $i => $begin) {
            $use_middleware = $begin == @self::$route_uri[$i];
        }

        if(!self::$DEBUG_DUMP_MODE && !$use_middleware)
            return $this;

        self::$using_group_middleware = true;
        self::$current_middleware = $middleware_callback;

        self::update_global_props("using_group_middleware", self::$using_group_middleware);
        self::update_global_props("current_middleware", self::$current_middleware);

        if(self::$DEBUG_DUMP_MODE)
            return $this;

        if(static::class == self::GLOBAL_API_CLASS) {
            self::$middleware_list["__GLOBAL__"][] = $middleware_callback;
            return $this;
        }

        // Reset middleware when moving to a new class
        if(
            isset(self::$middleware_list["__SCOPE__"]['class'])
            && self::$middleware_list["__SCOPE__"]['class'] != static::class
        )
            self::$middleware_list["__SCOPE__"]['fn'] = [];

        self::$middleware_list["__SCOPE__"]['class'] = static::class;
        self::$middleware_list["__SCOPE__"]['fn'][] = $middleware_callback;

        return $this;
    }

    /**
     * This object is used in debug mode to store routes in a predictable data object
     *
     * @return (((bool|mixed|null)[]|bool)[]|ApiReturnType|string)[]
     *
     * @psalm-return array{route: string, route_name: string, method: string, return_type: ApiReturnType, using_middleware: array{route: bool, group: bool}, rate_limiter: array{route: array{used: bool, reqs: mixed|null, duration: mixed|null, key: mixed|null}, group: array{used: bool, reqs: mixed|null, duration: mixed|null, key: mixed|null}, global: array{used: bool, reqs: mixed|null, duration: mixed|null, key: mixed|null}}}
     */
    private static function matched_uri_obj() : array
    {
        $route_limiter = self::$limiter_route;
        $group_limiter = self::$limiter_group;
        $global_limiter = self::$limiter_global;

        $route_limiter_used = isset($route_limiter[0]);
        $group_limiter_used = !$route_limiter_used && isset($group_limiter[0]);
        $global_limiter_used = !$route_limiter_used && !$group_limiter_used && isset($global_limiter[0]);

        return [
            'route' => self::$current_uri_string,
            'route_name' => self::$route_uri_name ?? "",
            'method' => self::$request_method,
            'return_type' => self::$method_return_type->name,
            'using_middleware' => [
                'route' => self::$using_route_middleware,
                'group' => self::$using_group_middleware
            ],
            'rate_limiter' => [
                'route' => [
                    "used" => $route_limiter_used,
                    "reqs" => $route_limiter[0] ?? null,
                    "duration" => $route_limiter[1] ?? null,
                    "key" => $route_limiter[2] ?? null,
                ],
                "group" => [
                    "used" => $group_limiter_used,
                    "reqs" => $group_limiter[0] ?? null,
                    "duration" => $group_limiter[1] ?? null,
                    "key" => $group_limiter[2] ?? null,
                ],
                "global" => [
                    "used" => $global_limiter_used,
                    "reqs" => $global_limiter[0] ?? null,
                    "duration" => $global_limiter[1] ?? null,
                    "key" => $global_limiter[2] ?? null,
                ],
            ],
        ];
    }

    /**
     * @param Closure $callback_of_controller_method method name of the set controller.
     * @param null|array{
     *     last_mod?: int,
     *     max_age?: int|string,
     *     public?: bool,
     * } $cache add caching features to your route
     * If you wish to retrieve the value of the method, ensure to return it;
     */
    public function bind( Closure $callback_of_controller_method, ?array $cache = null) : self {
        if(!self::$route_found || self::$request_complete)
            return $this;

        $this->correct_request_method();

        if(empty(self::$limiter_route) && (!empty(self::$limiter_group) || !empty(self::$limiter_global))) {
            $next = true;

            if(!empty(self::$limiter_group)) {
                $next = false;
                $this->limit(...self::$limiter_group, __INTERNAL_TYPE__: "GROUP");
            }

            if($next)
                $this->limit(...self::$limiter_global, __INTERNAL_TYPE__: "GLOBAL");

            if(self::$request_complete)
                return $this;
        }

        if(self::$current_middleware) {

            $this->middleware(self::$current_middleware);

            if(isset(self::$bind_return_value))
                return $this;
        }

        try {
            if(!self::$DEBUG_DUMP_MODE) {
                $arguments = self::get_mapped_args();
                self::set_return_value($callback_of_controller_method(...$arguments));

                if($cache)
                    self::add_cache_header(
                        $cache['last_mod'] ?? null,
                        [
                            "max_age" => $cache['max_age'] ?? null,
                            "public" => $cache['public'] ?? true,
                        ]
                    );
            }
        }
        catch (\TypeError $e){
            self::exception("ApiEngineMethodError", "Check the bind function of your route: [" . self::$route_uri_raw . "]", $e);
        }
        catch (\Error|\Exception $e){
            self::exception("ApiEngineError", "Error encountered while handling api entries <br>", $e);
        }

        return $this;
    }

    /**
     * @return (bool|mixed|string)[]
     *
     * @psalm-return array{found: bool, request: string,...}
     */
    public function found() : array
    {
        $x = self::matched_uri_obj();
        $x['found'] =  self::$route_found;
        $x["request"] = self::$route_uri_raw;

        return $x;
    }

    /**
     * This method returns the currently registered URIS based on the request METHOD received.
     *
     * This method can only be used in the `LayConfig::$ENV_IS_DEV` mode. If you wish to run this method in a production
     * environment, you will have to explicitly call the `ApiEngine::set_debug_mode()` before calling this method.
     * @return array
     * @see self::matched_uri_obj(); for the array shape
     */
    public function get_registered_uris() : array
    {
        if(LayConfig::$ENV_IS_PROD && !self::$DEBUG_MODE)
            self::exception(
                "WrongModeAccess",
                "You cannot get registered uris in production mode.\n<br>"
                . "You must call [<span style='color: #fff'>ApiEngine::set_debug_mode()</span>].\n <br>"
                . "You can do this in [Api/Plaster.php] file or any other ApiEngine class"
            );

        return self::$registered_uris;
    }

    /**
     * This method returns all the api endpoints registered in the system.
     *
     * This method runs only if the `ApiEngine::set_debug_dump_mode()` is called before it.
     * @return array
     * @see self::matched_uri_obj(); for the array shape
     */
    public static function all_api_endpoints() : array
    {
        if(!self::$DEBUG_DUMP_MODE)
            self::exception(
                "WrongModeAccess",
                "You cannot use this method in production mode.\n<br>"
                . "You must call [<span style='color: #fff'>ApiEngine::set_debug_dump_mode()</span>].\n <br>"
                . "You can do this in [Api/Plaster.php] file or any other ApiEngine class"
            );

        return self::$all_uris;
    }

    public function get_result() : mixed
    {
        self::reset_engine();

        try {
            return self::$bind_return_value;
        } catch (\Error $e) {
            self::exception("PrematureGetResult", "You simply called get result and no specified route was hit, so there's nothing to 'get'", $e);
        }

        return null;
    }

    /**
     * @param ApiReturnType|null $return_type
     * @param bool $print
     * @param bool $force_return_type When true, this will force every request to be returned by the specified return type
     * @return string|bool|null Returns `null` when no api was hit; Returns `false` on error; Returns json encoded string or html on success,
     * depending on what was selected as `$return_type`
     */
    public function print_as(?ApiReturnType $return_type = null, bool $print = true, bool $force_return_type = false) : string|bool|null {
        if(!isset(self::$bind_return_value) || self::$DEBUG_DUMP_MODE)
            return null;

        // Clear the prefix, because this method marks the end of a set of api routes
        self::$prefix = null;

        if($force_return_type)
            $return_type ??= self::$method_return_type;
        else
            $return_type = self::$method_return_type ?? $return_type;

        $x = self::$bind_return_value;

        if($return_type == ApiReturnType::JSON)
            $x = json_encode(self::$bind_return_value);

        if(($return_type == ApiReturnType::HTML || $return_type == ApiReturnType::XML) && is_array(self::$bind_return_value)) {
            $y = "<h1>Server Response</h1>";

            foreach (self::$bind_return_value as $k => $value) {
                if(is_array($value))
                    $value = "Array Object []";

                $y .= "<div><strong>$k:</strong> $value</div>";
            }

            $x = $y;
        }

        if($print) {
            $code = 0;

            if(is_array(self::$bind_return_value))
                $code = self::$bind_return_value['code'] ?? 0;

            $code = LayFn::http_response_code($code, true);

            if($code !== false)
                self::set_response_header($code, $return_type, "Ok");

            print_r($x);
            die;
        }

        return $x;
    }

    /**
     * Get the mapped-out arguments of the current request uri
     * @return array
     */
    public function get_mapped_args() : array {
        return self::$uri_variables['args'] ?? [];
    }

    public function get_uri() : array {
        return self::$route_uri;
    }

    public function get_uri_as_str() : string {
        return self::$route_uri_raw;
    }

    public function get_headers() : array {
        return self::$request_header;
    }

    /**
     * Let this class use php's Exception Class, rather than the Exception class in lay that is formatted with HTMl
     * @return void
     */
    public static function use_php_exception() : void
    {
        self::$use_lay_exception = false;
        self::update_global_props("use_lay_exception", self::$use_lay_exception);
    }

    public function __indexed_routes() : array
    {
        return $this->indexed_routes;
    }

    protected static function __indexing_routes() : void
    {
        self::$indexing_routes = true;
    }
    protected static function __indexing_routes_done() : void
    {
        self::$indexing_routes = false;
    }

    public static function set_debug_dump_mode() : void
    {
        if(self::$ALL_DEBUG_OVERRIDE)
            return;

        self::$DEBUG_DUMP_MODE = true;
        self::update_global_props("DEBUG_DUMP_MODE", self::$DEBUG_DUMP_MODE);
    }

    public static function debug_mode_override() : void
    {
        self::$ALL_DEBUG_OVERRIDE = true;
    }

    public static function is_debug_override_active() : bool
    {
        return self::$ALL_DEBUG_OVERRIDE;
    }

    public static function is_debug_dump_mode() : bool
    {
        return self::$DEBUG_DUMP_MODE;
    }

    public static function set_debug_mode() : void
    {
        if(self::$ALL_DEBUG_OVERRIDE)
            return;

        self::$DEBUG_MODE = true;
        self::update_global_props("DEBUG_MODE", self::$DEBUG_MODE);
    }

    public static function disable_index_access() : void
    {
        self::$allow_index_access = false;
    }

    /**
     * Capture the URI of requests sent to the api router then store it for further processing
     * @param string $local_endpoint The expected endpoint prefix
     * @param bool $mock Used by ApiPlaster for cache invalidation
     * @return self
     */
    public static function fetch(string $local_endpoint = "api", bool $mock = false) : self {
        if (self::$fetched) return self::$engine;

        $req = $mock ? [
            "route" => "index",
            "route_as_array" => ["index"],
        ] : Domain::current_route_data("*");

        $endpoint = $req['route'];

        if(empty($endpoint))
            self::exception("InvalidAPIRequest", "Invalid api request sent. Malformed URI received. You can't access this script like this!");

        self::$route_found = false;
        self::$request_complete = false;
        self::$request_header = LayConfig::get_header("*");
        self::$route_uri_raw = $endpoint;
        self::$route_uri = $req['route_as_array'];
        self::$fetched = true;

        if(self::$route_uri[0] == $local_endpoint) {
            array_shift(self::$route_uri);
            self::$route_uri_raw = implode("/", self::$route_uri);
        }

        if(self::$DEBUG_MODE === false && empty(self::$route_uri[0]))
            self::exception("InvalidAPIRequest", "Invalid api request sent. Malformed URI received. You can't access this script like this!");

        return self::$engine;
    }

    public static function end(bool $print_existing_result = true) : void
    {
        $uri = self::$route_uri_raw ?? "";

        if(self::$route_found) {
            self::$engine->print_as(self::$method_return_type ?? ApiReturnType::JSON, $print_existing_result);
            return;
        }

        $version_active = isset(self::$version) ? "<div>Version: <span style='color: #fff'>" . self::$version . "</span></div>" : null;
        $prefix_active = isset(self::$prefix) ? "<div>Active Prefix: <span style='color: #fff'>" . self::$prefix . "</span></div>" : null;
        $uris = "";
        $method = self::$active_request_method ?? self::$request_header['Access-Control-Request-Method'] ?? "GET";
        $mode = self::$DEBUG_MODE ? "true" : "false";
        $send_json_error = !isset(LayConfig::user_agent()['browser']);
        $json_error = [];

        foreach(self::$registered_uris as $reg_uri) {
            if($send_json_error)
                $json_error[] = [
                    "route" => "/" . ltrim($reg_uri['route'], "/"),
                    "route_name" => $reg_uri['route_name'],
                    "response_type" => $reg_uri['return_type'],
                ];

            $uris = "<div>" . PHP_EOL;
            $uris .= "<span style='color: #0dcaf0'>URI:</span> " . $reg_uri['route'] . "<br>" . PHP_EOL;
            $uris .= "<span style='color: #0dcaf0'>URI_NAME:</span> " . ($reg_uri['route_name'] ?: '-') . "<br>" . PHP_EOL;
            $uris .= "<span style='color: #0dcaf0'>RESPONSE_TYPE:</span> " . $reg_uri['return_type'] . "<br>" . PHP_EOL;
            $uris .= "</div>" . PHP_EOL;
        }

        $message = self::$DEBUG_MODE && !empty($uris) ? "<h3>Here are some similar [$method] routes: </h3>
                <div style='color: #F3F9FA'>$uris</div>" : "";

        if($send_json_error)
            $json_error = [
                "debug_mode" => $mode,
                "method" => $method,
                "version" => self::$version ?? "",
                "active_prefix" => self::$prefix ?? "",
                "similar_routes" => $json_error
            ];

        self::exception(
            "NoRequestExecuted",
            "No valid handler for route [<span style='color: #F3F9FA'>$uri</span>]<br><br>  
                <div>Code: <span style='color: #fff'>404</span></div> 
                <div>Message: <span style='color: #fff'>Route not found</span></div> 
                <div>Method: <span style='color: #fff'>$method</span></div> 
                <div>Debug Mode: <span style='color: #fff'>$mode</span></div>
                $version_active $prefix_active
                $message",
            header: [
                "code" => 404,
                "msg" => "API Route not found",
                "message" => "API Route not found",
                "throw_header" => false,
                "json_error" => $json_error
            ]
        );
    }
}
