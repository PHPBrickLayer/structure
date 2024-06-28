<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\View;

use BrickLayer\Lay\Core\Exception;
use JetBrains\PhpStorm\ExpectedValues;
use BrickLayer\Lay\Core\Enums\CustomContinueBreak;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Core\View\Enums\DomainCacheKeys;
use BrickLayer\Lay\Core\View\Enums\DomainType;
use ReflectionClass;
use ReflectionException;

class Domain {
    use IsSingleton;

    private static string $current_route;
    private static array $current_route_details;
    private static string $indexed_domain;
    private static bool $current_route_has_end_slash = false;

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
        if(self::$lay_init)
            return;

        LayConfig::is_init();
        self::$layConfig = LayConfig::new();
        self::$site_data = self::$layConfig::site_data();

        self::$lay_init = true;
    }

    private static function init_cache_domain() : void {
        if(self::$cache_domain_set)
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

    private function domain_cache_key(DomainCacheKeys $key_type, string|null|int $key = null, mixed $value = null, bool $cache = true) : mixed {
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
        $route = explode($pattern, $route, 2);
        $route = ltrim(end($route), "/");
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

        array_pop($file);
        $domain_file = $file;
        array_shift($domain_file);
        $domain_name = $domain_file[1];

        $data = LayConfig::site_data();
        $domain_base = $data->use_domain_file ? implode("/", $domain_file) . "/" : "";
        $domain_base = str_replace("/Api/", "/" . ($_SERVER['HTTP_LAY_DOMAIN'] ?? $domain_name) . "/", $domain_base, $is_api);

        $uri = ($pattern != '*' ? $pattern . '/' : '');

        if(isset(self::$indexed_domain))
            $uri = "";

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
        self::$current_route_details['domain_root'] = LayConfig::server_data()->root . implode(DIRECTORY_SEPARATOR, $file) . DIRECTORY_SEPARATOR;

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

        // Make lazy CORS configuration become active after loading all foundation files incase there was an overwrite
        LayConfig::call_lazy_cors();

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
            "jpeg","jpg","png","gif","jiff","webp","svg",

            // config files
            "json","xml","yaml",

            // fonts
            "ttf","woff2","woff",

            // text files
            "csv","txt",
        ];

        $x = explode(".",$view);
        $ext = explode("?", strtolower((string) end($x)))[0];

        if(count($x) > 1 && in_array($ext,$ext_array,true)) {
            if(in_array($ext, self::$site_data->ext_ignore_list,true))
                return $view;

            header("Content-Type: application/json");
            http_response_code(404);

            exit('{"error": 404, "response": "resource not found"}');
        }

        return $view;
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
        self::init_lay();

        if(isset(self::$current_route))
            return self::$current_route;

        //--START PARSE URI
        $root = "/";
        $get_name = "brick";

        $root_url = self::$site_data->base_no_proto;
        $root_file_system = rtrim(explode("index.php", $_SERVER['SCRIPT_NAME'])[0], "/");

        $view = str_replace("/index.php","",$_GET[$get_name] ?? "");
        $view = str_replace([$root_url,$root_file_system],"",$view);

        if($root != "/")
            $view = str_replace(["/$root/","/$root","$root/"],"", $view);

        //--END PARSE URI

        self::$current_route_has_end_slash = str_ends_with($view,"/");
        self::$current_route = $this->check_route_is_static_file(trim($view,"/")) ?: 'index';

        return self::$current_route;
    }

    private function active_pattern() : array {
        $base = self::$site_data->base_no_proto;
        $sub_domain = explode(".", $base, 3);
        $local_dir = explode("/", self::$current_route, 2);

        return [
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

    private function test_pattern(string $id, string $pattern) : CustomContinueBreak {
        if(self::$domain_found)
            return CustomContinueBreak::BREAK;

        if(!$this->is_all_domain_cached())
            return CustomContinueBreak::CONTINUE;

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
        $is_local_domain = $domain['local']['found'];

        if($is_subdomain && $is_local_domain)
            $is_local_domain = false;

        self::$domain_type = $is_local_domain ? DomainType::LOCAL : DomainType::SUB;

        if($is_subdomain && $domain['sub']['value'] == $pattern) {
            $builder = $this->get_cached_domain_details($id)['builder'];
            $this->activate_domain($id, $pattern, $builder);
            return CustomContinueBreak::BREAK;
        }

        if($is_local_domain && $domain['local']['value'] == $pattern) {
            $builder = $this->get_cached_domain_details($id)['builder'];
            $this->activate_domain($id, $pattern, $builder);
            return CustomContinueBreak::BREAK;
        }

        return CustomContinueBreak::FLOW;
    }
    private function match_cached_domains() : bool {
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

            if($rtn == CustomContinueBreak::BREAK)
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

    public function create(string $id, string $builder, array $patterns = ["*"]) : void {
        self::init_lay();
        self::init_cache_domain();

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

    public function index(string $id) : void
    {
        self::$indexed_domain = $id;
    }

    public static function current_route_data(
        #[ExpectedValues([
            'route',
            'route_as_array',
            'route_has_end_slash',
            'domain_name',
            'domain_type',
            'domain_id',
            'domain_uri',
            'domain_base',
            'domain_root',
            'pattern',
            '*',
        ])]
        string $key
    ) : string|DomainType|array
    {
        if($key == "*")
            return self::$current_route_details;

        return self::$current_route_details[$key];
    }

    public function get_domain_by_id(string $id) : ?array {
        return $this->get_cached_domain_details($id);
    }
}
