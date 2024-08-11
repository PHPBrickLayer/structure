<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\Traits;

use BrickLayer\Lay\Core\Enums\LayMode;
use BrickLayer\Lay\Core\Enums\LayServerType;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\LayMail;
use BrickLayer\Lay\Orm\SQL;
use Closure;
use JetBrains\PhpStorm\ArrayShape;
use mysqli;
use TypeError;

trait Config
{
    private static SQL $SQL_INSTANCE;
    private static array $CONNECTION_ARRAY;
    private static array $SMTP_ARRAY;
    private static array $CACHED_CORS;
    private static bool $CORS_ACTIVE = false;
    private static array $layConfigOptions;
    private static bool $DEFAULT_ROUTE_SET = false;
    private static bool $USE_DEFAULT_ROUTE = true;
    private static bool $COMPRESS_HTML;
    private static string $SESSION_KEY = "__LAY_VARS__";
    private static string $GLOBAL_API;

    public static function session_start(#[ArrayShape([
        "expose_php" => "bool",
        "timezone" => "string",
        "only_cookies" => "int",
        "http_only" => "bool",
        "secure" => "bool",
        "samesite" => "string",
        "domain" => "string",
        "path" => "string",
        "lifetime" => "int",
    ])] array $flags = []): void
    {
        $cookie_opt = [];
        $flags['expose_php'] ??= false;
        $flags['timezone'] ??= 'Africa/Lagos';

        date_default_timezone_set($flags['timezone']);

        if (!$flags['expose_php']) header_remove('X-Powered-By');

        if (isset($flags['only_cookies'])) ini_set("session.use_only_cookies", ((int)$flags['only_cookies']) . "");

        if (self::$ENV_IS_PROD && (isset($flags['http_only']) || isset($flags['httponly']))) $cookie_opt['httponly'] = filter_var($flags['httponly'] ?? $flags['http_only'], FILTER_VALIDATE_BOOL);

        if (self::$ENV_IS_PROD && isset($flags['secure'])) $cookie_opt['secure'] = filter_var($flags['secure'], FILTER_VALIDATE_BOOL);

        if (self::$ENV_IS_PROD && isset($flags['samesite'])) $cookie_opt['samesite'] = ucfirst($flags['samesite']);

        if (isset($flags['domain'])) $cookie_opt['domain'] = "." . $flags['domain'];

        if (isset($flags['path'])) $cookie_opt['path'] = $flags['path'];

        if (isset($flags['lifetime'])) $cookie_opt['lifetime'] = $flags['lifetime'];

        if (!empty($cookie_opt)) session_set_cookie_params($cookie_opt);

        if (isset($_SESSION)) return;

        session_start();
        $_SESSION[self::$SESSION_KEY] ??= [];
    }

    public static function call_lazy_cors(): void
    {
        if (isset(self::$CACHED_CORS)) self::set_cors(...self::$CACHED_CORS, lazy_cors: false);
    }

    /**
     * @param array $allowed_origins String[] of allowed origins like "http://example.com"
     * @param bool $allow_all
     * @param Closure|null $fun example `function(){ header("Access-Control-Allow-Origin: Origin, X-Requested-With, Content-Type, Accept"); }`
     * @param bool $lazy_cors
     * @return bool
     */
    public static function set_cors(array $allowed_origins = [], bool $allow_all = false, ?Closure $fun = null, bool $lazy_cors = true): bool
    {
        if ($lazy_cors) {
            self::$CACHED_CORS = [$allowed_origins, $allow_all, $fun];
            return true;
        }

        self::$CORS_ACTIVE = true;

        $http_origin = rtrim($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['HTTP_REFERER'] ?? "", "/");

        if ($allow_all) {
            $http_origin = "*";
        } else {
            if (!in_array($http_origin, $allowed_origins, true)) return false;
        }

        // in an ideal word, this variable will only be empty if it's the same origin
        if (empty($http_origin)) return true;

        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Origin: $http_origin");
        header('Access-Control-Max-Age: 86400');    // cache for 1 day

        // Access-Control headers are received during OPTIONS requests
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) header("Access-Control-Allow-Headers:{$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

            exit(0);
        }

        if ($fun !== null) $fun("");

        return true;

    }

    public static function cors_active(): bool
    {
        return self::$CORS_ACTIVE;
    }

    public static function set_smtp(): void
    {
        LayMail::set_credentials();
    }

    public static function get_orm(bool $connect_db = false): SQL
    {
        self::is_init();

        if (!isset(self::$SQL_INSTANCE) && !$connect_db)
            Exception::throw_exception(
                "Trying to access the database without connecting; use`\$this->builder->connect_db()` in a `Plaster.php` file or `LayConfig::connect()` to connect db.",
                "OrmNotDetected"
            );
        self::connect();

        return self::$SQL_INSTANCE;
    }

    public static function is_page_compressed(): bool
    {
        self::is_init();
        return self::$COMPRESS_HTML;
    }

    public static function close_orm(?mysqli $link = null): void
    {
        self::is_init();
        if (!isset(self::$SQL_INSTANCE)) return;

        $orm = self::$SQL_INSTANCE;

        if (!isset($orm->query_info)) return;

        $orm?->close($orm->get_link() ?? $link, true);
    }

    public static function validate_lay(): void
    {
        self::init_first_class();

        if (!defined("SAFE_TO_INIT_LAY") || !SAFE_TO_INIT_LAY) Exception::throw_exception("This script cannot be accessed this way, please return home", "BadRequest");

        Exception::new()->capture_errors();
    }

    public static function is_bot(): bool
    {
        return (bool) preg_match('~(bot|crawl)~i', self::get_header("User-Agent"), flags: PREG_UNMATCHED_AS_NULL);
    }

    /**
     * Get a header received by this application from an HTTP request using a key.
     * You can pass "*" to retrieve every header received.
     * @param string $key
     * @return mixed
     */
    public static function get_header(string $key): mixed
    {
        $all = getallheaders();

        if ($key === "*") return $all;

        if($key == "Bearer")
            return $all['Authorization'] ? LayFn::ltrim_word($all['Authorization'], "Bearer ") : null;

        return $all[$key] ?? $all[strtolower($key)] ?? null;
    }

    #[ArrayShape([
        "agent" => "string",
        "product" => "string",
        "platform" => "string",
        "engine" => "string",
        "browser" => "string",
    ])]
    public static function user_agent() : ?array
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        if(!$agent)
            return null;

        // Define the regex pattern
        $pattern = '/^(?<product>.*?)\s\((?<platform>.*?)\)\s(?<engine>.*?)\s\((?<engine2>.*?)\)\s(?<browser>.*?)$/';
        preg_match($pattern, $agent, $matches);

        if(empty($matches))
            return null;

        return [
            "agent" => $matches[0],
            "product" => $matches['product'],
            "platform" => $matches['platform'],
            "engine" => $matches['engine'] . " (" . $matches['engine2']  .")",
            "browser" => $matches['browser'],
        ];
    }

    public static function get_os(): string
    {
        $OS = self::user_agent()['platform'] ?? null;
        $OS ??= PHP_OS;
        $OS = strtoupper($OS);

        if (str_starts_with($OS, "DAR") || str_starts_with($OS, "MAC")) return "MAC";

        if (str_starts_with($OS, "WIN")) return "WINDOWS";

        return $OS;
    }

    public static function geo_data(): bool|object
    {
        $data = false;
        try {
            $data = @json_decode(file_get_contents('https://ipinfo.io/' . self::get_ip()));
        } catch (TypeError) {
        }

        if (!$data) return false;

        return $data;
    }

    public static function get_ip(): string
    {
        if (self::get_mode() === LayMode::CLI) return "LAY_CLI_MODE";

        $IP_KEY = "PUBLIC_IP";
        $public_ip = $_SESSION[self::$SESSION_KEY][$IP_KEY] ?? null;
        $now = strtotime("now");

        if ($public_ip && $now < $public_ip['exp']) return $public_ip['ip'];

        if (self::$ENV_IS_DEV && self::new()->has_internet()) {
            $_SESSION[self::$SESSION_KEY][$IP_KEY] = ["ip" => $_SESSION[self::$SESSION_KEY][$IP_KEY] = file_get_contents("https://api.ipify.io"), "exp" => strtotime('3 hours')];

            return $_SESSION[self::$SESSION_KEY][$IP_KEY]['ip'];
        }

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'] as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip_address) {
                    $ip_address = trim($ip_address);

                    if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) return $ip_address;
                }
            }

        }

        $_SESSION[self::$SESSION_KEY][$IP_KEY] = ["ip" => $ip_address, "exp" => strtotime('1 hour')];

        return $_SESSION[self::$SESSION_KEY][$IP_KEY]['ip'];
    }

    public static function has_internet(): bool|array
    {
        return @fsockopen("google.com", 443, timeout: 1) !== false;
    }

    /**
     * Request to files that don't exist on the server is handled by `Lay`.
     * If the file is a static asset like `css`, a `404` response code and a
     * json response body is returned.
     * Hence, this method instructs `Lay` to ignore the specified files.
     *
     * You can instruct `Lay` to ignore files like: `xml`, `json`; and treat them
     * like a regular html page, maybe because you want to further process it in
     * the `Plaster` class.
     *
     * Please don't add dot (.), simply use the file extension directly.
     *
     * @param string ...$extensions
     * @return LayConfig|Config
     */
    public function ignore_file_extensions(string ...$extensions): self
    {
        self::$layConfigOptions['ext_ignore_list'] = $extensions;
        return self::$instance;
    }

    public function init_others(array $other_data): self
    {
        self::$layConfigOptions['others'] = $other_data;
        return self::$instance;
    }

    /**
     * Instruct `Lay` to not compress the `HTML` output of a page on production server
     * @return LayConfig|Config
     */
    public function dont_compress_html(): self
    {
        return $this->switch("compress_html", false);
    }

    private function switch(string $key, mixed $value): self
    {
        self::$layConfigOptions['switch'][$key] = $value;
        return self::$instance;
    }

    /**
     * [NOT RECOMMENDED]
     * If you have no intention of minifying your static assets with the built-in `php bob deploy` command, this means
     * you don't want your application requesting for assets in the `prod` folder of the respective asset file,
     * then use this method.
     *
     * @return LayConfig|Config
     */
    public function dont_use_prod_folder(): self
    {
        return $this->switch("use_prod", false);
    }

    public function use_domain_as_sub(): self
    {
        return $this->switch("use_domain_as_sub", true);
    }

    /**
     * Prevents the data sent through the ViewHandler of a specific domain from being cached.
     * This only takes effect in development environment, if Lay detects the server is in production, it'll cache by default
     * @return Config|LayConfig
     */
    public function dont_cache_domains(): self
    {
        return $this->switch("cache_domains", false);
    }

    public function set_global_api(string $uri): self
    {
        self::$GLOBAL_API = $uri;
        return $this;
    }

    public function get_global_api(): ?string
    {
        return self::$GLOBAL_API ?? null;
    }

    public function get_server_type(): LayServerType
    {
        return self::$SERVER_TYPE;
    }

    private function metadata(string $key, mixed $value): self
    {
        self::$layConfigOptions['meta'][$key] = $value;
        return self::$instance;
    }

    public function init_name(string $short, string $full): self
    {
        return $this->metadata("name", ["short" => $short, "full" => $full]);
    }

    public function init_color(string $pry, string $sec): self
    {
        return $this->metadata("color", ["pry" => $pry, "sec" => $sec]);
    }

    public function init_mail(string ...$emails): self
    {
        return $this->metadata("mail", $emails);
    }

    public function init_tel(string ...$tel): self
    {
        return $this->metadata("tel", $tel);
    }

    public function init_author(string $author): self
    {
        return $this->metadata("author", $author);
    }

    public function init_copyright(string $copyright): self
    {
        return $this->metadata("copy", $copyright);
    }

    public function init_end(): void
    {
        self::initialize();
    }

    public function is_mobile(): bool
    {
        return !empty($_SERVER['HTTP_USER_AGENT']) && preg_match('~(Mobile)~i', $_SERVER['HTTP_USER_AGENT'], flags: PREG_UNMATCHED_AS_NULL);
    }

    public function init_orm(bool $connect_by_default = true): self
    {
        if ($connect_by_default)
            self::connect();

        return $this;
    }

    public static function connect(?array $connection_params = null): SQL
    {
        self::is_init();

        return self::$SQL_INSTANCE = SQL::init($connection_params);
    }

}
