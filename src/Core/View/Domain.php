<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\View;

use BrickLayer\Lay\Core\Annotate\CurrentRouteData;
use BrickLayer\Lay\Core\Api\ApiEngine;
use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\Enums\LayMode;
use BrickLayer\Lay\Core\Enums\LayServerType;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\Enums\DomainCacheKeys;
use BrickLayer\Lay\Core\View\Enums\DomainType;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;
use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;
use JetBrains\PhpStorm\ExpectedValues;
use ReflectionClass;
use ReflectionException;

/**
 * @phpstan-type DomainRouteData DomainType|string|array<int>|array{
 *    route: string,
 *    route_as_array: array<int>,
 *    route_has_end_slash: bool,
 *    domain_name: string,
 *    domain_type: DomainType,
 *    domain_id: string,
 *    domain_root: string,
 *    domain_referrer: string,
 *    domain_is_api?: string,
 *    domain_uri: string,
 *    domain_base: string,
 *    pattern: string,
 *    plaster: string,
 *    layout: string,
 *  }
 */
final class Domain {
    use IsSingleton;

    private static bool $included_once = false;

    private static string $current_route;
    private static array $current_route_details;
    private static string $indexed_domain;
    private static bool $current_route_has_end_slash = false;

    private static bool $list_domain_only = false;
    private static bool $cli_mode = false;
    private static bool $mocking_domain = false;
    private static bool $thrown_exception = false;

    private static bool $lay_init = false;
    private static LayConfig $layConfig;
    private static object $site_data;
    private static bool $cache_domains = true;
    private static bool $cache_domain_set = false;
    private static bool $domain_found = false;
    private static string $domain_list_key = "__LAY_DOMAINS__";
    private static array $domain_ram;
    private static DomainType $domain_type;

    private static function init_lay() : void {
        if(self::$lay_init || self::$list_domain_only)
            return;

        LayConfig::is_init();
        self::$layConfig = LayConfig::new();
        self::$site_data = self::$layConfig::site_data();

        self::$lay_init = true;
    }

    private static function init_cache_domain() : void {
        if(self::$cache_domain_set || self::$list_domain_only)
            return;

        self::$cache_domain_set = true;
        self::$cache_domains = self::$layConfig::$ENV_IS_PROD && self::$site_data->cache_domains;
    }

    private function cache_domain_ram() : void {
        if (self::$cache_domains)
            $_SESSION[self::$domain_list_key] = self::$domain_ram;
    }

    private function read_cached_domain_ram() : void {
        if(self::$cache_domains && isset($_SESSION[self::$domain_list_key]))
            self::$domain_ram = $_SESSION[self::$domain_list_key];
    }

    /**
     * @param mixed $value
     * @param null|string|int $key
     *
     * @psalm-param array{pattern: string, id: string}|null|true $key
     */
    private function domain_cache_key(DomainCacheKeys $key_type, string|null|int $key = null, mixed $value = null, bool $cache = true) : mixed
    {
        $cache = $cache && self::$cache_domains;
        $this->read_cached_domain_ram();

        if($value) {
            if($key) {
                if($cache && isset(self::$domain_ram[$key_type->value][$key]))
                    return null;

                self::$domain_ram[$key_type->value][$key] = $value;
                $this->cache_domain_ram();
                return null;
            }

            if($cache && isset(self::$domain_ram[$key_type->value]))
                return null;

            self::$domain_ram[$key_type->value] = $value;
            $this->cache_domain_ram();
            return null;
        }

        if($key)
            return self::$domain_ram[$key_type->value][$key] ?? null;

        return self::$domain_ram[$key_type->value] ?? null;
    }

    private function cache_domain_details(array $domain) : void {
        if($this->domain_cache_key(DomainCacheKeys::List, $domain['id']))
            return;

        $this->domain_cache_key(DomainCacheKeys::List, $domain['id'], $domain);
    }

    private function get_cached_domain_details(string $id) : ?array {
        return $this->domain_cache_key(DomainCacheKeys::List, $id);
    }

    private function cache_active_domain(string $id, string $domain_pattern) : void {
        $data = $this->get_active_domain();

        $this->domain_cache_key(DomainCacheKeys::CURRENT, value: ["pattern" => $domain_pattern, "id" => $id], cache: $data && $data['pattern'] == $domain_pattern);
    }

    private function get_active_domain() : ?array {
        return $this->domain_cache_key(DomainCacheKeys::CURRENT);
    }

    private function cache_all_domain_ids(string $id, string $domain_pattern) : void {
        $this->domain_cache_key(DomainCacheKeys::ID, $domain_pattern, $id);
    }

    private function get_all_domain_ids() : ?array {
        return $this->domain_cache_key(DomainCacheKeys::ID);
    }

    private function all_domain_is_cached() : void {
        $this->domain_cache_key(DomainCacheKeys::CACHED, value: true);
    }

    private function is_all_domain_cached() : ?bool {
        return $this->domain_cache_key(DomainCacheKeys::CACHED);
    }

    private function activate_domain(string $id, string $pattern, string $builder) : void {
        $route = $this->get_current_route();
        $route = LayFn::ltrim_word($route, $pattern);
        $route = ltrim($route, "/");
        $route_as_array = explode("/", $route);

        if(self::$domain_type == DomainType::LOCAL && $route_as_array[0] === "api") {
            $dom = $this->get_domain_by_id("api-endpoint");

            $id = $dom['id'];
            $pattern = $dom['patterns'][0];
            $builder = $dom['builder'];
        }

        self::$domain_found = true;
        $this->cache_active_domain($id, $pattern);

        $builder_class = $builder;
        $file = explode("\\", $builder);
        $domain_name = $file[1];
        $domain_root = "web" . DIRECTORY_SEPARATOR . "domains" . DIRECTORY_SEPARATOR . "$domain_name" . DIRECTORY_SEPARATOR;

        $data = LayConfig::site_data();
        $domain_base = $data->use_domain_file ? "domains/$domain_name/" : "";
        $domain_base = str_replace("/Api/", "/" . ($_SERVER['HTTP_LAY_DOMAIN'] ?? $domain_name) . "/", $domain_base, $is_api);

        $uri = ($pattern != '*' ? $pattern . '/' : '');

        if(isset(self::$indexed_domain))
            $uri = "";

        $host = self::$cli_mode ? ($_ENV['LAY_CUSTOM_HOST'] ?? "CLI") : ($_SERVER['HTTP_HOST'] ?? $_ENV['LAY_CUSTOM_HOST']);

        self::$current_route_details['host'] = $host;
        self::$current_route_details['route'] = $route ?: "index";
        self::$current_route_details['route_as_array'] = $route_as_array;
        self::$current_route_details['route_has_end_slash'] = self::$current_route_has_end_slash;
        self::$current_route_details['pattern'] = $pattern;
        self::$current_route_details['domain_name'] = $domain_name;
        self::$current_route_details['domain_referrer'] = $_SERVER['HTTP_LAY_DOMAIN'] ?? $domain_name;
        self::$current_route_details['domain_type'] = self::$domain_type;
        self::$current_route_details['domain_id'] = $id;
        self::$current_route_details['domain_uri'] = str_replace("/web/", "/", $data->domain) . $uri;
        self::$current_route_details['domain_base'] = $data->domain . $domain_base;
        self::$current_route_details['domain_root'] = LayConfig::server_data()->root . $domain_root;
        self::$current_route_details['plaster'] = self::$current_route_details['domain_root'] . "plaster" . DIRECTORY_SEPARATOR;
        self::$current_route_details['layout'] = self::$current_route_details['domain_root'] . "layout" . DIRECTORY_SEPARATOR;

        if($is_api > 0)
            self::$current_route_details['domain_is_api'] = true;

        // Init domain resources before including the domain-level foundation file so the data can be manipulated
        DomainResource::init();

        // Include domain-level foundation file
        $web_root = LayConfig::server_data()->web;

        if(file_exists($web_root . "foundation.php"))
            include_once $web_root . "foundation.php";

        if(file_exists(self::$current_route_details['domain_root'] . "foundation.php"))
            include_once self::$current_route_details['domain_root'] . "foundation.php";

        if(self::$cli_mode)
            return;

        // Make lazy CORS configuration become active after loading all foundation files incase there was an overwrite
        LayConfig::call_lazy_cors();

        if(self::$mocking_domain)
            return;

        $this->include_static_assets($route);

        try{
            $builder = new ReflectionClass($builder);
        } catch (ReflectionException $e){
            Exception::throw_exception($e->getMessage(), "DomainException", exception: $e);
        }

        try {
            $builder = $builder->newInstance();
        } catch (ReflectionException $e) {
            Exception::throw_exception(
                " $builder_class constructor class is private. \n"
                . " All builder classes must expose their __construct function to clear this error",
                "ConstructPrivate",
                exception: $e
            );
        }

        DomainResource::set_plaster_instance($builder);

        $builder->init();
    }

    /**
     * If route its static file (jpg, json, etc.) this means the webserver (apache) was unable to locate the file,
     * hence a 404 error should be returned.
     * But if it's not a static file, the route should be returned instead
     * @param string $view
     * @return string
     */
    private function check_route_is_static_file(string $view) : string {
        $ext_array = [
            // programming files
            "js","css","map",

            // images
            "jpeg","jpg","png","gif","jiff","webp","svg","ico",

            // config files
            "json","xml","yaml",

            // fonts
            "ttf","woff2","woff",

            // text files
            "csv","txt","db","sqlite","log"
        ];

        $x = explode(".",$view);
        $ext = explode("?", strtolower((string) end($x)))[0];

        if(count($x) > 1 && in_array($ext,$ext_array,true)) {
            if(in_array($ext, self::$site_data->ext_ignore_list,true))
                return $view;

            LayFn::header("Content-Type: application/json");
            LayFn::http_response_code(ApiStatus::NOT_FOUND, true);

            exit('{"error": 404, "response": "resource not found"}');
        }

        return $view;
    }

    private function include_static_assets(string $route) : void
    {
        $referer = LayConfig::get_header("Referer");
        $from_js_module = $referer && str_contains($referer, ".js");

        if(!$from_js_module)
            return;

        $js = DomainResource::include_file(
            $route . ".js",
            "domain_root",
            [
                "as_string" => true,
                "use_get_content" => true,
                "error_file_not_found" => false,
                "get_last_mod" => true,
            ]
        );

        if($js) {
            LayFn::header("Content-Type: text/javascript");
            LayFn::http_response_code(ApiStatus::OK, true);

            ApiEngine::add_cache_header(
                $js['last_mod'],
                [
                    "max_age" => LayDate::in_seconds("1 year"),
                    "public" => true
                ]
            );

            echo $js;
            die;
        }

        LayFn::header("Content-Type: application/json");
        LayFn::http_response_code(ApiStatus::NOT_FOUND, true);
        exit('{"error": 404, "response": "resource not found"}');
    }

    /**
     * Gets the request url from the webserver through the `brick` query.
     * It will process it and return the sanitized url, stripping all unnecessary values.
     *
     * If a static asset's uri like (jpg, json) is received,
     * it means the server could not locate the files,
     * hence throw error 404
     *
     * @return string
     */
    private function get_current_route() : string {
        if(self::$list_domain_only)
            return "__LIST_ONLY__";

        self::init_lay();

        if(LayConfig::get_mode() == LayMode::CLI) {
            self::$cli_mode = true;
            self::$current_route_has_end_slash = false;
            return self::$current_route = "index";
        }

        if(isset(self::$current_route))
            return self::$current_route;

        //--START PARSE URI
        $root = "/";
        $get_name = "brick";
        $request_uri = $_SERVER['REQUEST_URI'];

        if(LayConfig::get_server_type() == LayServerType::APACHE)
            $request_uri = $_GET[$get_name] ?? '';

        // Strip all search query, it's not needed
        $request_uri = explode("?", $request_uri, 2)[0];

        $root_url = self::$site_data->base_no_proto;
        $root_file_system = rtrim(explode("index.php", $_SERVER['SCRIPT_NAME'])[0], "/");

        $view = str_replace(["/index.php", "/index.html"], "", $request_uri);

        if($view !== $root_file_system)
            $view = str_replace([$root_url, $root_file_system], "", $view);

        if($root != "/")
            $view = str_replace(["/$root/","/$root","$root/"],"", $view);

        //--END PARSE URI

        self::$current_route_has_end_slash = str_ends_with($view,"/");
        self::$current_route = $this->check_route_is_static_file(trim($view,"/")) ?: 'index';

        return self::$current_route;
    }

    /**
     * @return (bool|string)[][]
     *
     * @psalm-return array{ngrok: array{value: string, found: bool}, sub: array{value: string, found: bool}, local: array{value: string, found: bool}}
     */
    private function active_pattern() : array {
        $base = self::$site_data->base_no_proto;
        $sub_domain = explode(".", $base, 3);
        $local_dir = explode("/", self::$current_route, 2);
        $is_ngrok = @$_SERVER['REMOTE_ADDR'] == "127.0.0.1";

        return [
            "ngrok" => [
                "value" => $local_dir[0],
                "found" => $is_ngrok,
            ],
            "sub" => [
                "value" => $sub_domain[0],
                "found" => !($sub_domain[0] == "www")
                    && !(is_numeric($sub_domain[0]) && is_numeric($sub_domain[1]))
                    && count($sub_domain) > 2,
            ],
            "local" => [
                "value" => $local_dir[0],
                "found" => count($local_dir) > 0,
            ],
        ];
    }

    private function test_pattern(string $id, string $pattern) : LayLoop {
        if(self::$domain_found)
            return LayLoop::BREAK;

        if(!$this->is_all_domain_cached())
            return LayLoop::CONTINUE;

        $domain = $this->active_pattern();

        // This condition handles subdomains.
        // If the dev decides to create subdomains,
        // Lay can automatically map the views to the various subdomains as directed by the developer.
        //
        // Example:
        //  https://admin.example.com;
        //  https://clients.example.com;
        //  https://vendors.example.com;
        //
        // This condition is looking out for "admin" || "clients" || "vendors" in the `patterns` argument.
        $is_subdomain = $domain['sub']['found'];

        // Determines if a request is from ngrok and treats it like a local domain
        $is_ngrok = $domain['ngrok']['found'];

        // This conditions handles virtual folder.
        // This is a situation were the developer wants to separate various sections of the application into folders.
        // The dev doesn't necessarily have to create folders, hence "virtual folder".
        // All the dev needs to do is map the pattern to a view builder
        //
        // Example:
        //  localhost/example.com/admin/;
        //  localhost/example.com/clients/;
        //  localhost/example.com/vendors/;
        //
        // This condition is looking out for "/admin" || "/clients" || "/vendors" in the `patterns` argument.
        $is_local_domain = $domain['local']['found'] ?: $is_ngrok;

        if(($is_subdomain && $is_local_domain) && !$is_ngrok)
            $is_local_domain = false;

        self::$domain_type = $is_local_domain ? DomainType::LOCAL : DomainType::SUB;


        if($is_subdomain && $domain['sub']['value'] == $pattern) {
            $builder = $this->get_cached_domain_details($id)['builder'];
            $this->activate_domain($id, $pattern, $builder);
            return LayLoop::BREAK;
        }

        if($is_local_domain && $domain['local']['value'] == $pattern) {
            $builder = $this->get_cached_domain_details($id)['builder'];
            $this->activate_domain($id, $pattern, $builder);
            return LayLoop::BREAK;
        }

        return LayLoop::FLOW;
    }
    private function match_cached_domains() : bool {
        if(self::$list_domain_only)
            return false;

        if(!$this->is_all_domain_cached())
            return false;

        if(self::$domain_found)
            return true;

        if(isset(self::$indexed_domain)) {
            $current_domain_pattern = @$this->get_domain_by_id(self::$indexed_domain)['patterns'];

            if(empty($current_domain_pattern))
                Exception::throw_exception("Domain id: [" . self::$indexed_domain . "] is invalid", "DomainException");

            foreach ($current_domain_pattern as $pattern) {
                $this->test_pattern(self::$indexed_domain, $pattern);

                $this->activate_domain(
                    self::$indexed_domain,
                    $pattern,
                    $this->get_cached_domain_details(self::$indexed_domain)['builder']
                );
            }

            return true;
        }

        $patterns = $this->get_all_domain_ids();

        foreach ($patterns as $pattern => $id) {
            $rtn = $this->test_pattern($id, $pattern);

            if($rtn == LayLoop::BREAK)
                return true;

            if($id == "default" || $pattern == "*") {
                $builder = $this->get_cached_domain_details($id)['builder'];
                $this->activate_domain($id, $pattern, $builder);
            }
        }

        return false;
    }

    private function cache_patterns(string $id, array $patterns) : void {
        foreach ($patterns as $pattern) {
            $this->cache_all_domain_ids($id, $pattern);

            $this->test_pattern($id, $pattern);

            if($id == "default" || $pattern == "*") {
                $this->all_domain_is_cached();
                $this->match_cached_domains();
            }
        }
    }

    public function create(string $id, string $builder, array $patterns = ["*"], bool $cli_mode = false) : void {
        self::init_lay();
        self::init_cache_domain();

        if($cli_mode) {
            self::$indexed_domain = $id;
            self::$cli_mode = true;
            $_SERVER['REQUEST_URI'] ??= $patterns[0];

            $this->cache_domain_details([
                "id" => $id,
                "patterns" => $patterns,
                "builder" => $builder
            ]);

            $this->all_domain_is_cached();
        }

        $this->get_current_route();

        if($this->match_cached_domains())
            return;

        $this->cache_domain_details([
            "id" => $id,
            "patterns" => $patterns,
            "builder" => $builder
        ]);

        $this->cache_patterns($id, $patterns);
    }

    public function mock(string $domain_id, ?string $host = null, bool $use_https = true) : void
    {
        self::$mocking_domain = true;
        $_SERVER['REQUEST_URI'] = "";
        $this->index($domain_id);

        if($host)
            LayConfig::mock_server($host, $use_https);

        include_once LayConfig::server_data()->web . "index.php";
    }

    public function list() : array
    {
        self::$list_domain_only = true;

        include_once LayConfig::server_data()->web . "index.php";

        return self::$domain_ram[DomainCacheKeys::List->value];
    }

    public function index(string $id) : void
    {
        self::$indexed_domain = $id;
    }

    /**
     * @param string $key
     * @return DomainRouteData
     */
    public static function current_route_data(#[ExpectedValues(CurrentRouteData::ANNOTATE)] string $key) : string|DomainType|array
    {
        if($key == "*")
            return self::$current_route_details;

        return self::$current_route_details[$key];
    }

    public function get_domain_by_id(string $id) : ?array {
        return $this->get_cached_domain_details($id);
    }

    /**
     * Clear the session key of a domain list.
     * This is especially useful in the production server where Lay caches
     * the list of domains to avoid the number of times it has to loop to get
     * the correct domain
     *
     * @return void
     */
    public function clear_domain_cache() : void
    {
        if(isset($_SESSION[self::$domain_list_key]))
            unset($_SESSION[self::$domain_list_key]);
    }

    public static function is_in_use() : bool
    {
        return isset(self::$current_route_details);
    }

    /**
     * Instruct Domain to include the Domain entries from the /web/index.php file if it hasn't been included already.
     *
     * When mock is true, the domain's Plaster is not called, but other important things like CORS are called
     * @param bool $mock
     * @return void
     */
    public static function set_entries_from_file(bool $mock = true) : void
    {
        if(self::is_in_use() || self::$included_once) return;

        self::$mocking_domain = $mock;

        $domain_entries = LayConfig::server_data()->web . "index.php";

        //TODO: Find a way to fix the infinite loop when there is an exception before the view is displayed
//        $is_domain_entry_file = $_SERVER['SCRIPT_FILENAME'] == $domain_entries;
//
//        if($is_domain_entry_file) {
//            if(self::$thrown_exception)
//                return;
//
//            self::$thrown_exception = true;
//            LayException::throw_exception(
//                "Cannot call this method inside $domain_entries file"
//            );
//        }

        include_once $domain_entries;
        self::$included_once = true;
    }

    public static function included_once() : bool
    {
        return self::$included_once;
    }

}
