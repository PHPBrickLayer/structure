<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\Traits;

use BrickLayer\Lay\__InternalOnly\CacheInitOptions;
use BrickLayer\Lay\Core\Enums\LayMode;
use BrickLayer\Lay\Core\Enums\LayServerType;
use stdClass;

trait Init {
    private static string $base;
    private static string $proto;
    private static string $base_no_proto;
    private static string $base_no_proto_no_www;

    private static LayMode $LAY_MODE;
    private static bool $INITIALIZED = false;
    private static bool $MOCKED = false;
    private static bool $MOCK_HTTPS = false;
    private static bool $FIRST_CLASS_CITI_ACTIVE = false;

    protected static string $dir;
    protected static LayServerType $SERVER_TYPE;

    public static bool $ENV_IS_PROD = true;
    public static bool $ENV_IS_DEV = false;

    private static function init_first_class() : void {
        if(!self::$FIRST_CLASS_CITI_ACTIVE)
            self::first_class_citizens();
    }

    private static function set_web_root(&$options) : void
    {
        $options['base'] = self::$base;
        $options['proto'] = self::$proto;
        $options['base_no_proto'] = self::$base_no_proto;
        $options['base_no_proto_no_www'] = self::$base_no_proto_no_www;

        $options['server_mocked'] = false;
        $web =  "";

        if(!$options['using_domain']) {
            $web = $options['using_web'] ? "" : "web/";
            $options['use_domain_file'] = true;
        }

        if(self::$MOCKED) {
            $options['server_mocked'] = true;
            $options['use_domain_file'] = false;

            $web = "";
            $options['using_web'] = "";
            $options['using_domain'] = "";
        }

        $options['domain'] = self::$base . ( $web ?: "" );
        $options['domain_no_proto'] = self::$base_no_proto . ($web ? "/$web" : "");
        $options['domain_no_proto_no_www'] = self::$base_no_proto_no_www . ($web ? "/$web" : "");
    }

    private static function set_dir() : void {
        $s = DIRECTORY_SEPARATOR;

        self::$dir = explode
            (
                "{$s}vendor{$s}bricklayer{$s}structure",
                __DIR__ . $s
            )[0] . $s;
    }

    private static function first_class_citizens() : void {
        self::$FIRST_CLASS_CITI_ACTIVE = true;
        self::set_dir();

        // Don't bother running any process if document root is not set.
        // This means the framework is being accessed from the cli,
        // we don't want to run unnecessary compute and waste resources.
        if(empty($_SERVER['DOCUMENT_ROOT']))
            self::$LAY_MODE = LayMode::CLI;

        $slash          = DIRECTORY_SEPARATOR;
        $base           = str_replace("/", $slash, $_SERVER['DOCUMENT_ROOT']);

        $pin = $base;
        $string = self::$dir;

        if(strlen($pin) > strlen($string)) {
            $pin = self::$dir;
            $string = $base;
        }

        $pin            = rtrim($pin, "/");
        $base           = $pin ? explode($pin, $string) : ["", ""];

        $options['using_web'] = str_starts_with($base[1], "/web");
        $options['using_domain'] = str_starts_with($base[1], "/web/domain");

        if($options['using_domain'] || $options['using_web'])
            $base = [""];

        self::$layConfigOptions['header']['using_domain'] = $options['using_domain'];
        self::$layConfigOptions['header']['using_web'] = $options['using_web'];

        $base           = str_replace($slash, "/", end($base));
        $http_host      = $_SERVER['HTTP_HOST'] ?? $_ENV['LAY_CUSTOM_HOST'] ?? "cli";
        $env_host       = $_SERVER['REMOTE_ADDR'] ?? $_ENV['LAY_CUSTOM_REMOTE_ADDR'] ?? "cli";
        $proto          = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME'] ?? (self::$MOCK_HTTPS ? 'https' : 'http')) . "://";
        $base_no_proto  = rtrim(str_replace($slash,"/", $base),"/");

        if($http_host != "cli" && !isset($_ENV['LAY_CUSTOM_HOST']))
            self::$LAY_MODE = LayMode::HTTP;

        self::$proto = $proto;
        self::$base = $proto . $http_host . $base_no_proto . "/";
        self::$base_no_proto  = $http_host . $base_no_proto;
        self::$base_no_proto_no_www  = str_replace("www.","", self::$base_no_proto);
        $server_type = $_SERVER['SERVER_SOFTWARE'] ?? null;

        if($server_type) {
            $server_type = match (substr(strtolower($server_type), 0, 3)) {
                default => LayServerType::OTHER,
                "apa" => LayServerType::APACHE,
                "php" => LayServerType::PHP,
                "ngi" => LayServerType::NGINX,
                "cad" => LayServerType::CADDY,
            };
        }

        self::$SERVER_TYPE = $server_type ?? LayServerType::OTHER;

        $localhost = ["127.0.","192.168.","::1"];

        self::$ENV_IS_PROD = (
            $env_host !== "localhost" &&
            (
                !str_contains($env_host, $localhost[0]) && !str_contains($env_host, $localhost[1]) && !str_contains($env_host, $localhost[2])
            )
        );

        if(self::get_mode() == LayMode::CLI && !isset($_SERVER['SSH_CONNECTION']))
            self::$ENV_IS_PROD = false;

        self::$ENV_IS_DEV = !self::$ENV_IS_PROD;

        self::set_web_root($options);

        self::set_internal_site_data($options);
    }

    private static function initialize() : self {
        self::init_first_class();

        $options = self::$layConfigOptions ?? [];

        $options = [
            # This tells Lay to use `dev/` folder on production rather than `prod/` folder as the source for client resources
            "use_prod" => $options['switch']['use_prod'] ?? true,
            # On true, this strips space from the html output. Note; it doesn't strip space off the <script></script> elements or anything in-between elements for that matter
            "compress_html" => $options['switch']['compress_html'] ?? true,
            # Used by the Domain module to instruct the handler to cache all the listed domains in a session or cookie,
            # depending on the value sent by dev
            "cache_domains" => $options['switch']['cache_domains'] ?? true,
            # If true, when linking to a domain using the Anchor tag class, on production server;
            # example.com/blog will be converted to
            # blog.example.com
            "use_domain_as_sub" => $options['switch']['use_domain_as_sub'] ?? false,
            # Internal option set by lay to tell dev that the /web/domain/ folder is where html is being served from
            "using_domain" => $options['header']['using_domain'] ?? null,
            # Internal option set by lay to tell dev that the /web/ folder is where html is being served from
            "using_web" => $options['header']['using_web'] ?? null,
            "use_domain_file" => null, // this is updated when webroot is created after first class is init
            "name" => [
                "short" => $options['meta']['name']['short'] ?? "Lay - Lite PHP Framework",
                "long" => $options['meta']['name']['full'] ?? "Lay - Lite PHP Framework | Simple, Light, Quick",
                "full" => $options['meta']['name']['full'] ?? "Lay - Lite PHP Framework | Simple, Light, Quick",
            ],
            "author" => $options['meta']['author'] ?? "Lay - Lite PHP Framework",
            "copy" => $options['meta']['copy'] ?? "Copyright &copy; Lay - Lite PHP Framework " . date("Y") . ", All Rights Reserved",
            "color" => [
                "pry" => $options['meta']['color']['pry'] ?? "",
                "sec" => $options['meta']['color']['sec'] ?? "",
            ],
            "mail" => $options['meta']['mail'] ?? [],
            "tel" => $options['meta']['tel'] ?? [],
            "others" => $options['others'] ?? [],
            "ext_ignore_list" => $options['ext_ignore_list'] ?? [],
        ];

        self::$COMPRESS_HTML = $options['compress_html'];

        self::$server   = new stdClass();

        $options['mail'][0] = $options['mail'][0] ?? "info@" . self::$base_no_proto;

        self::$INITIALIZED = true;

        self::set_web_root($options);
        self::set_internal_site_data($options);
        self::set_internal_res_server(self::$dir);
        self::load_env();

        if(isset($_ENV['APP_ENV'])) {
            $env = strtolower($_ENV['APP_ENV']);
            self::$ENV_IS_PROD = !($env == "dev" || $env == "development");
            self::$ENV_IS_DEV = !self::$ENV_IS_PROD;
        }

        if(self::get_mode() == LayMode::CLI)
            self::new()->init_orm(true);

        return self::$instance;
    }

    public static function is_init(bool $init_first_class = false) : void {
        if($init_first_class && !self::$FIRST_CLASS_CITI_ACTIVE) {
            self::init_first_class();
            return;
        }

        if(!self::$INITIALIZED)
            self::initialize();
    }

    public static function get_mode() : LayMode {
        if(isset(self::$LAY_MODE))
            return self::$LAY_MODE;

        self::$LAY_MODE = LayMode::HTTP;


        if(empty($_SERVER['DOCUMENT_ROOT']) || !isset($_SERVER['HTTP_HOST']))
            self::$LAY_MODE = LayMode::CLI;

        return self::$LAY_MODE;
    }

    public static function mock_server(string $host, bool $use_https) : void
    {
        $_ENV['LAY_CUSTOM_HOST'] = $host;
        $_ENV['LAY_CUSTOM_REMOTE_ADDR'] = "lay_remote_addr";

        self::$MOCKED = true;
        self::$MOCK_HTTPS = $use_https;
        self::$FIRST_CLASS_CITI_ACTIVE = false;
        self::$INITIALIZED = false;
    }
}
