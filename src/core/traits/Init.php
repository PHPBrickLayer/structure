<?php
declare(strict_types=1);
namespace BrickLayer\Lay\core\traits;
use BrickLayer\Lay\core\Exception;
use BrickLayer\Lay\libs\LayObject;
use BrickLayer\Lay\orm\SQL;
use BrickLayer\Lay\AutoLoader;
use stdClass;

trait Init {
    private static string $dir;
    private static string $base;
    private static string $base_no_proto;
    private static string $base_no_proto_no_www;
    private static string $env_host;

    private static bool $INITIALIZED = false;
    private static bool $FIRST_CLASS_CITI_ACTIVE = false;
    public static bool $ENV_IS_PROD = false;
    public static bool $ENV_IS_DEV = true;

    private static function init_first_class() : void {
        if(!self::$FIRST_CLASS_CITI_ACTIVE)
            self::first_class_citizens();
    }

    private static function set_web_root(&$options) : void
    {
        $options['base'] = self::$base;
        $options['base_no_proto'] = self::$base_no_proto;
        $options['base_no_proto_no_www'] = self::$base_no_proto_no_www;

        $web = $options['using_domain'] ? "" : "/web";

        $options['domain'] = self::$base . ( $web ? "web/" : "" );
        $options['domain_no_proto'] = self::$base_no_proto . $web;
        $options['domain_no_proto_no_www'] = self::$base_no_proto_no_www . $web;
    }

    private static function set_dir() : void {
        self::$dir = AutoLoader::get_root_dir();
    }

    private static function first_class_citizens() : void {
        self::$FIRST_CLASS_CITI_ACTIVE = true;
        self::set_dir();

        // Don't bother running any process if document root is not set.
        // This means the framework is being accessed from the cli,
        // we don't want run unnecessary compute and waste resources.
        if(empty($_SERVER['DOCUMENT_ROOT']))
            return;

        $slash          = DIRECTORY_SEPARATOR;
        $base           = str_replace($slash, "/", $_SERVER['DOCUMENT_ROOT']);

        $pin = $base;
        $string = self::$dir;

        if(count_chars($base) > count_chars(self::$dir)) {
            $pin = self::$dir;
            $string = $base;
        }

        $pin = rtrim($pin, "/");
        $base           = explode($pin, $string);
        $options['using_domain'] = str_starts_with($base[1], "/web/domains/");

        if($options['using_domain'])
            $base = [""];

        self::$layConfigOptions['header']['using_domain'] = $options['using_domain'];

        $http_host      = $_SERVER['HTTP_HOST'] ?? "cli";
        $env_host       = $_SERVER['REMOTE_ADDR'] ?? "cli";
        $proto          = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME']) . "://";
        $base_no_proto  = rtrim(str_replace($slash,"/", end($base)),"/");

        self::$base = $proto . $http_host . $base_no_proto . "/";
        self::$base_no_proto  = $http_host . $base_no_proto;
        self::$base_no_proto_no_www  = str_replace("www.","", $base_no_proto);

        $localhost = ["127.0.","192.168.","::1"];

        self::$ENV_IS_PROD = (
            $env_host !== "localhost" &&
            (
                !str_contains($env_host, $localhost[0]) && !str_contains($env_host, $localhost[1]) && !str_contains($env_host, $localhost[2])
            )
        );

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
            "global_api" => $options['header']['api'] ?? null,
            "using_domain" => $options['header']['using_domain'] ?? null,
            "name" => [
                "short" => $options['meta']['name']['short'] ?? "Lay - Lite PHP Framework",
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
            "others" => $options['others'] ?? []
        ];

        self::$COMPRESS_HTML = $options['compress_html'];
        
        self::$server   = new stdClass();
        
        $options['mail'][0] = $options['mail'][0] ?? "info@" . self::$base_no_proto;

        self::set_web_root($options);

        $api = $options['domain'] . $options['global_api'];

        self::set_internal_site_data($options);
        self::set_internal_res_server(self::$dir);
        self::load_env();

        self::$INITIALIZED = true;
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
}
