<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\Traits;

use BrickLayer\Lay\Core\Enums\LayMode;
use BrickLayer\Lay\Core\Enums\LayServerType;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\Domain;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\Mail\Mailer;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\SQL;
use Closure;
use JetBrains\PhpStorm\ArrayShape;
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
    private static bool $DELETE_SENT_MAILS = true;
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
        if(self::get_mode() == LayMode::CLI)
            return;

        $cookie_opt = [];
        $flags['expose_php'] ??= false;
        $flags['timezone'] ??= 'Africa/Lagos';

        date_default_timezone_set($flags['timezone']);

        if (!$flags['expose_php']) {
            header_remove('X-Powered-By');
            header_remove('Server');
        }

        if (isset($flags['only_cookies']))
            ini_set("session.use_only_cookies", ((int)$flags['only_cookies']) . "");

        if (self::$ENV_IS_PROD && (isset($flags['http_only']) || isset($flags['httponly'])))
            $cookie_opt['httponly'] = filter_var($flags['httponly'] ?? $flags['http_only'], FILTER_VALIDATE_BOOL);

        if (self::$ENV_IS_PROD && isset($flags['secure']))
            $cookie_opt['secure'] = filter_var($flags['secure'], FILTER_VALIDATE_BOOL);

        if (self::$ENV_IS_PROD && isset($flags['samesite']))
            $cookie_opt['samesite'] = ucfirst($flags['samesite']);

        if (isset($flags['domain']))
            $cookie_opt['domain'] = "." . $flags['domain'];

        if (isset($flags['path']))
            $cookie_opt['path'] = $flags['path'];

        if (isset($flags['lifetime']))
            $cookie_opt['lifetime'] = $flags['lifetime'];

        if (!empty($cookie_opt))
            session_set_cookie_params($cookie_opt);

        if (isset($_SESSION)) return;

        session_start();
        $_SESSION[self::$SESSION_KEY] ??= [];
    }

    public static function call_lazy_cors(bool $about_to_die = false): void
    {
        if (isset(self::$CACHED_CORS)) {
            self::set_cors(...self::$CACHED_CORS, lazy_cors: false);
            return;
        }

        if($about_to_die) {
            Domain::current_route_data("*");
        }



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
        if(self::get_mode() == LayMode::CLI)
            return true;

        if ($lazy_cors) {
            self::$CACHED_CORS = [$allowed_origins, $allow_all, $fun];
            return true;
        }

        self::$CORS_ACTIVE = true;

        $http_origin = rtrim($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['HTTP_REFERER'] ?? "", "/");

        if ($allow_all) {
            $http_origin = "*";
        } else {
            if (
                !LayArray::any(
                    $allowed_origins,
                    fn($v) => rtrim($v, "/") === $http_origin
                )
            ) return false;
        }

        // in an ideal word, this variable will only be empty if it's the same origin
        if (empty($http_origin)) return true;

        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Origin: $http_origin");
        header('Access-Control-Max-Age: 86400');    // cache for 1 day

        // Access-Control headers are received during OPTIONS requests
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
                header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
                header("Access-Control-Allow-Headers:{$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

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
        Mailer::set_credentials();
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

    /**
     * @return false|null|string
     */
    public static function app_id(): string|false|null
    {
        self::is_init();
        $id = self::server_data()->lay . "identity";

        if(file_exists($id))
            return file_get_contents($id);

        return null;
    }

    public static function close_orm(mixed $link = null): void
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

        if (!defined("SAFE_TO_INIT_LAY") || !SAFE_TO_INIT_LAY)
            Exception::throw_exception("This script cannot be accessed this way, please return home", "BadRequest");

        Exception::new()->capture_errors();
    }

    public static function is_bot(): bool
    {
        $from = self::get_header("From");
        $user_agent = self::get_header("User-Agent");

        $exists = preg_match('~(bot|crawl)~i', $from ?? '', flags: PREG_UNMATCHED_AS_NULL);

        if($exists) return true;

        $exists = preg_match('~(bot|crawl)~i', $user_agent ?? '', flags: PREG_UNMATCHED_AS_NULL);

        return (bool) $exists;
    }

    /**
     * Get a header received by this application from an HTTP request using a key.
     * You can pass "*" to retrieve every header received.
     * @param string $key
     * @return mixed
     */
    public static function get_header(string $key): mixed
    {
        if(function_exists("getallheaders"))
            $all = getallheaders();
        else {
            $all = [];

            foreach ($_SERVER as $k => $server) {
                if(!str_starts_with($k, "HTTP_"))
                    continue;

                $k = str_replace(["http_", "_"], ["", " "], strtolower($k));
                $k = str_replace(" ", "-", ucwords($k));
                $k_lower = strtolower($k);

                if($k_lower == "dnt")
                    $k = strtoupper($k);

                $all[$k_lower] = $server;
            }
        }

        $authorization_header = /**
         * @return (null|string)[]|null
         *
         * @psalm-return array{scheme: string, data: null|string}|null
         */
        function ($all): array|null {
            $auth = $all['Authorization'] ?? $all['authorization'] ?? $all['AUTHORIZATION'] ?? null;

            if(!$auth)
                return null;

            $auth = explode(" ", $auth, 2);

            return [
                "scheme" => $auth[0],
                "data" => $auth[1] ?? null,
            ];
        };

        if ($key === "*") return $all;

        $key_lower = strtolower($key);

        if($key_lower == "authorization")
            return $authorization_header($all);

        if($key_lower == "bearer" || $key_lower == "basic" || $key_lower == "digest") {
            $auth = $authorization_header($all);

            if(!$auth)
                return null;

            return $auth['data'];
        }


        return $all[$key] ?? $all[$key_lower] ?? $all[strtoupper($key)] ?? null;
    }

    /**
     * @return (mixed|string)[]|null
     *
     * @psalm-return array{agent: string, product?: string, platform?: string, engine?: string, browser?: mixed|string}|null
     */
    #[ArrayShape([
        "agent" => "string",
        "product" => "string",
        "platform" => "string",
        "engine" => "string",
        "browser" => "string",
    ])]
    public static function user_agent() : array|null
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        if(!$agent)
            return null;

        // regex pattern for chromium
        $pattern = '/^(?<product>.*?)\s\((?<platform>.*?)\)\s(?<engine>.*?)\s\((?<engine2>.*?)\)\s(?<browser>.*?)$/';

        preg_match($pattern, $agent, $matches);

        if(empty($matches)) {
            // regex pattern for firefox
            $pattern = '/^(?<product>.*?)\s\((?<platform>.*?)\)\s(?<engine>.*?)\s(?<browser>.*?)$/';
            preg_match($pattern, $agent, $matches);

            if(empty($matches))
                return [
                    'agent' => $agent
                ];
        }

        $extract_word = function (string $pattern, string $subject) {
            $pattern = '~' . $pattern . '~';

            preg_match($pattern, $subject, $out);

            if(empty($out))
                return null;

            return $out;
        };

        $edge = $extract_word("Edg.*", $matches['browser']);
        $safari = $extract_word("(Version\/[0-9.]+) (Safari\/[0-9.]+)", $matches['browser']);
        $chrome = $extract_word("Chrome\/[0-9.]+", $matches['browser']);

        if($edge)
            $browser = $edge[0];
        elseif($safari)
            $browser = $safari[2];
        elseif($chrome)
            $browser = $chrome[0];
        else
            $browser = null;

        return [
            "agent" => $matches[0],
            "product" => $matches['product'],
            "platform" => $matches['platform'],
            "engine" => $matches['engine'],
            "browser" => $browser ?? $matches['browser'],
        ];
    }

    /**
     * Get OS of the application.
     * If you want the OS of the client, use the user_agent function
     * @return string
     */
    public static function get_os(): string
    {
        $OS = PHP_OS;
        $OS ??= explode(" ", php_uname(), 2)[0];
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
        if (self::get_mode() === LayMode::CLI) {

            if(isset($_SERVER['SSH_CONNECTION']))
                return explode(" ", $_SERVER['SSH_CONNECTION'], 2)[0];

            return "LAY_CLI_MODE";
        }

        $IP_KEY = "PUBLIC_IP";
        $public_ip = $_SESSION[self::$SESSION_KEY][$IP_KEY] ?? null;
        $now = strtotime("now");

        if ($public_ip && $public_ip['ip'] && $now < $public_ip['exp'])
            return $public_ip['ip'];

        if (self::$ENV_IS_DEV && self::new()->has_internet()) {
            $fetch_data = function($url): bool|string|null
            {
                $ch = curl_init();

                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 Lay-Framework',
                ]);

                $response = curl_exec($ch);
                $err = curl_error($ch);

                curl_close($ch);

                if ($err)
                    return null;

                return $response;
            };

            $_SESSION[self::$SESSION_KEY][$IP_KEY] = ["ip" => $_SESSION[self::$SESSION_KEY][$IP_KEY] = $fetch_data("https://api.ipify.io") ?: "127.0.0.1", "exp" => strtotime('3 hours')];

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

        $ip_address ??= "127.0.0.1-cli";

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
     * @return self
     * @example ignore_file_extensions("xml", "json")
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
     * @return LayConfig
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
     * ##[NOT RECOMMENDED]
     * If you have no intention of minifying your static assets with the built-in `php bob deploy` command, this means
     * you don't want your application requesting for assets in the `prod` folder of the respective asset file,
     * then use this method.
     *
     * @return LayConfig
     */
    public function dont_use_prod_folder(): self
    {
        return $this->switch("use_prod", false);
    }

    /**
     * Queued sent emails won't be deleted from the database
     * @return LayConfig
     */
    public function dont_delete_sent_mails() : self
    {
        return $this->switch("delete_sent_mails", false);
    }

    /**
     * This instructs Lay to use the pattern attribute of a domain as its subdomain.
     *
     * This takes effect if the Anchor class is used to create a hyperlink anywhere on the project.
     * However, it will only work on production server;
     *
     * @example example.com/blog will be converted to blog.example.com
     * @return self
     */
    public function use_domain_as_sub(): self
    {
        return $this->switch("use_domain_as_sub", true);
    }

    /**
     * Prevents the data sent through the ViewHandler of a specific domain from being cached.
     * This only takes effect in development environment, if Lay detects the server is in production, it'll cache by default
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

    public static function get_server_type(): LayServerType
    {
        $server_type = $_SERVER['SERVER_SOFTWARE'] ?? "CLI";

        return match (substr(strtolower($server_type), 0, 3)) {
            default => LayServerType::OTHER,
            "cli" => LayServerType::CLI,
            "apa" => LayServerType::APACHE,
            "php" => LayServerType::PHP,
            "ngi" => LayServerType::NGINX,
            "cad" => LayServerType::CADDY,
        };
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

    public static function is_mobile(): bool
    {
        return !empty($_SERVER['HTTP_USER_AGENT']) && preg_match('~(Mobile)~i', $_SERVER['HTTP_USER_AGENT'], flags: PREG_UNMATCHED_AS_NULL);
    }

    public function init_orm(bool $connect_by_default = true): self
    {
        if(self::get_mode() == LayMode::CLI)
            return $this;

        if ($connect_by_default)
            self::connect();

        return $this;
    }

    public static function connect(array|null|string $connection_params = null, ?OrmDriver $driver = null): SQL
    {
        self::is_init();

        return self::$SQL_INSTANCE = SQL::init($connection_params, $driver);
    }
}
