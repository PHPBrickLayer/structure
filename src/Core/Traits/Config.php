<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\Traits;

use BrickLayer\Lay\Core\Enums\LayMode;
use BrickLayer\Lay\Libs\LayArray;
use Closure;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayMail;
use BrickLayer\Lay\Orm\SQL;
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

    private function header_data(string $key, mixed $value): self
    {
        self::$layConfigOptions['header'][$key] = $value;
        return self::$instance;
    }

    private function metadata(string $key, mixed $value): self
    {
        self::$layConfigOptions['meta'][$key] = $value;
        return self::$instance;
    }

    /**
     * File Extention that should be ignore by the ViewEngine. example: xml, json.
     * Please don't add dot (.), simply use the file extension directly.
     * @param string ...$extensions
     * @return LayConfig|Config
     */
    public function ignore_file_extensions(string ...$extensions) : self
    {
        self::$layConfigOptions['ext_ignore_list'] = $extensions;
        return self::$instance;
    }

    public function init_others(array $other_data): self
    {
        self::$layConfigOptions['others'] = $other_data;
        return self::$instance;
    }

    private function switch(string $key, mixed $value): self
    {
        self::$layConfigOptions['switch'][$key] = $value;
        return self::$instance;
    }

    public static function session_start(array $flags = []): void
    {
        $cookie_opt = [];
        $flags['expose_php'] ??= false;
        $flags['timezone'] ??= 'Africa/Lagos';

        date_default_timezone_set($flags['timezone']);

        if (!$flags['expose_php'])
            header_remove('X-Powered-By');

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

        if (isset($_SESSION))
            return;

        session_start();
        $_SESSION[self::$SESSION_KEY] ??= [];
    }

    /**
     * @param array $allowed_origins String[] of allowed origins like "http://example.com"
     * @param bool $allow_all
     * @param Closure|null $fun example function(){ header("Access-Control-Allow-Origin: Origin, X-Requested-With, Content-Type, Accept"); }
     * @return bool
     */
    public static function set_cors(array $allowed_origins = [], bool $allow_all = false, ?Closure $fun = null, bool $lazy_cors = true): bool
    {
        if($lazy_cors) {
            self::$CACHED_CORS = [$allowed_origins, $allow_all, $fun];
            return true;
        }

        self::$CORS_ACTIVE = true;

        $http_origin = rtrim($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['HTTP_REFERER'] ?? "", "/");

        if ($allow_all) {
            $http_origin = "*";
        } else {
            if (!in_array($http_origin, $allowed_origins, true))
                return false;
        }

        // in an ideal word, this variable will only be empty if it's the same origin
        if (empty($http_origin))
            return true;

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

        if ($fun !== null)
            $fun("");

        return true;

    }

    public static function call_lazy_cors() : void
    {
        if(isset(self::$CACHED_CORS))
            self::set_cors(...self::$CACHED_CORS, lazy_cors: false);
    }

    public static function cors_active() : bool
    {
        return self::$CORS_ACTIVE;
    }

    public static function set_smtp(): void
    {
        if (isset(self::$SMTP_ARRAY))
            return;

        $parse = function ($value): ?string {
            if (empty($value))
                return null;

            $code = "@layConfig";

            if (str_starts_with($value, $code)) {
                $value = explode($code, $value);
                $value = end($value);
                $value = eval("return \BrickLayer\Lay\Core\LayConfig{$value};");
            }

            return $value;
        };

        self::load_env();

        self::$SMTP_ARRAY = [
            "host" => $_ENV['SMTP_HOST'],
            "port" => $_ENV['SMTP_PORT'],
            "protocol" => $_ENV['SMTP_PROTOCOL'],
            "username" => $_ENV['SMTP_USERNAME'],
            "password" => $_ENV['SMTP_PASSWORD'],
            "default_sender_name" => $parse(@$_ENV['DEFAULT_SENDER_NAME']),
            "default_sender_email" => $parse(@$_ENV['DEFAULT_SENDER_EMAIL']),
        ];

        LayMail::set_credentials(self::$SMTP_ARRAY);
    }

    public static function get_orm(): SQL
    {
        self::is_init();

        if(!isset(self::$SQL_INSTANCE))
            Exception::throw_exception(
                "Trying to access the database without connecting; use`\$this->builder->connect_db()` to connect db.",
                "OrmNotDetected"
            );

        return self::$SQL_INSTANCE;
    }

    public static function is_page_compressed(): bool
    {
        self::is_init();
        return self::$COMPRESS_HTML;
    }

    public static function close_sql(?mysqli $link = null): void
    {
        self::is_init();
        if (!isset(self::$SQL_INSTANCE))
            return;

        $orm = self::$SQL_INSTANCE;

        if (!isset($orm->query_info))
            return;

        $orm?->close($orm->get_link() ?? $link, true);
    }

    public static function validate_lay(): void
    {
        self::init_first_class();

        if (!defined("SAFE_TO_INIT_LAY") || !SAFE_TO_INIT_LAY)
            Exception::throw_exception("This script cannot be accessed this way, please return home", "BadRequest");

        Exception::new()->capture_errors();
    }

    public function dont_compress_html(): self
    {
        return $this->switch("compress_html", false);
    }

    public function dont_use_prod_folder(): self
    {
        return $this->switch("use_prod", false);
    }

    public function dont_use_objects(): self
    {
        return $this->switch("use_objects", false);
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

    public static function is_bot(): bool
    {
        return !empty($_SERVER['HTTP_USER_AGENT']) && preg_match('~(bot|crawl)~i', $_SERVER['HTTP_USER_AGENT'], flags: PREG_UNMATCHED_AS_NULL);
    }

    /**
     *  Get a list of all the headers received by this application from an HTTP request
     * @return array
     */
    public static function headers() : array
    {
        $rtn = [];

        foreach ($_SERVER as $k => $v) {
            if(str_starts_with($k, "HTTP_"))
                $rtn[$k] = $v;
        }

        return $rtn;
    }

    /**
     * Get a header received by this application from an HTTP request using a key
     * @param string $key
     * @return mixed
     */
    public static function get_header(string $key) : mixed
    {
        $key = str_replace("-", "_", ltrim($key, "HTTP_"));
        return $_SERVER["HTTP_" . $key] ?? null;
    }

    public static function get_os(): string
    {
        $OS = strtoupper(PHP_OS);

        if(str_starts_with($OS, "DAR") || str_starts_with($OS, "MAC"))
            return "MAC";

        if(str_starts_with($OS, "WIN"))
            return "WINDOWS";

        return $OS;
    }

    public static function geo_data(): bool|object
    {
        $data = false;
        try {
            $data = @json_decode(file_get_contents('https://ipinfo.io/' . self::get_ip()));
        } catch (TypeError) {
        }

        if (!$data)
            return false;

        return $data;
    }

    public static function get_ip(): string
    {
        if(self::get_mode() === LayMode::CLI)
            return "LAY_CLI_MODE";

        $IP_KEY = "PUBLIC_IP";
        $public_ip = $_SESSION[self::$SESSION_KEY][$IP_KEY] ?? null;
        $now = strtotime("now");

        if ($public_ip && $now < $public_ip['exp'])
            return $public_ip['ip'];

        if (self::$ENV_IS_DEV && self::new()->has_internet()) {
            $_SESSION[self::$SESSION_KEY][$IP_KEY] = [
                "ip" => $_SESSION[self::$SESSION_KEY][$IP_KEY] = file_get_contents("https://api.ipify.io"),
                "exp" => strtotime('3 hours')
            ];

            return $_SESSION[self::$SESSION_KEY][$IP_KEY]['ip'];
        }

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        foreach (
            [
                'HTTP_CLIENT_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_X_CLUSTER_CLIENT_IP',
                'HTTP_FORWARDED_FOR',
                'HTTP_FORWARDED',
                'REMOTE_ADDR'
            ] as $key
        ) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip_address) {
                    $ip_address = trim($ip_address);

                    if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false)
                        return $ip_address;
                }
            }

        }

        $_SESSION[self::$SESSION_KEY][$IP_KEY] = [
            "ip" => $ip_address,
            "exp" => strtotime('1 hour')
        ];

        return $_SESSION[self::$SESSION_KEY][$IP_KEY]['ip'];
    }

    public static function user_agent() : string
    {
        if(empty($_SERVER['HTTP_USER_AGENT']))
            return "NO USER AGENT DETECTED";

        return $_SERVER['HTTP_USER_AGENT'];
    }

    public static function has_internet(): bool|array
    {
        return @fsockopen("google.com", 443, timeout: 1) !== false;
    }

    public function init_orm(bool $connect_by_default = true): self
    {
        if (isset(self::$CONNECTION_ARRAY)) {
            if ($connect_by_default)
                self::connect(self::$CONNECTION_ARRAY);

            return $this;
        }

        $ENV = self::$ENV_IS_DEV ? 'dev' : 'prod';

        self::load_env();

        if (!isset($_ENV['DB_HOST']))
            return $this;

        self::$CONNECTION_ARRAY = [
            "host" => $_ENV['DB_HOST'],
            "user" => $_ENV['DB_USERNAME'],
            "password" => $_ENV['DB_PASSWORD'],
            "db" => $_ENV['DB_NAME'],
            "env" => $_ENV['DB_ENV'] ?? $ENV,
            "port" => $_ENV['DB_PORT'] ?? NULL,
            "socket" => $_ENV['DB_SOCKET'] ?? NULL,
            "silent" => $_ENV['DB_ALLOW_STARTUP_ERROR'] ?? false,
            "ssl" => [
                "key" => $_ENV['DB_SSL_KEY'] ?? null,
                "certificate" => $_ENV['DB_SSL_CERTIFICATE'] ?? null,
                "ca_certificate" => $_ENV['DB_SSL_CA_CERTIFICATE'] ?? null,
                "ca_path" => $_ENV['DB_SSL_CA_PATH'] ?? null,
                "cipher_algos" => $_ENV['DB_SSL_CIPHER_ALGOS'] ?? null,
                "flag" => $_ENV['DB_SSL_FLAG'] ?? 0
            ],
        ];

        if ($connect_by_default)
            self::connect(self::$CONNECTION_ARRAY);

        return $this;
    }

    public static function connect(?array $connection_params = null): SQL
    {
        self::is_init();
        $env = self::$ENV_IS_DEV ? 'dev' : 'prod';

        if (isset($connection_params['host']) || isset(self::$CONNECTION_ARRAY['host'])) {
            $opt = self::$CONNECTION_ARRAY ?? $connection_params;
        } else {
            $opt = self::$CONNECTION_ARRAY[$env] ?? $connection_params[$env];
        }

        if (empty($opt))
            Exception::throw_exception("Invalid Connection Parameter Passed");

        if (is_array($opt))
            $opt['env'] = $opt['env'] ?? $env;

        if ($env == "prod")
            $opt['env'] = "prod";

        self::$SQL_INSTANCE = SQL::init($opt);
        return self::$SQL_INSTANCE;
    }
}
