<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\Traits;
use BrickLayer\Lay\Libs\LayDir;
use Dotenv\Dotenv;
use JetBrains\PhpStorm\ObjectShape;

trait Resources {
    private static object $server;
    private static object $site;

    private static bool $env_loaded = false;

    private static string $CLIENT_VALUES = "";

    protected static function set_internal_res_server(string $dir) : void {

        $slash = DIRECTORY_SEPARATOR;

        $obj = new \stdClass();

        $obj->lay_static        =   $dir  .   "vendor"    .   $slash .    "bricklayer" . $slash .   "structure" . $slash . "src" . $slash . "static" . $slash;
        $obj->framework         =   $dir  .   "vendor"    .   $slash .    "bricklayer" . $slash .   "structure" . $slash;
        $obj->root              =   $dir;
        $obj->lay               =   $dir  .   ".lay"      .  $slash;
        $obj->temp              =   $obj->lay             .   "temp" .   $slash;
        $obj->workers           =   $obj->lay             .   "workers" .   $slash;
        $obj->bricks            =   $dir  .   "bricks"    .   $slash;
        $obj->db                =   $dir  .   "db"        .   $slash;
        $obj->utils             =   $dir  .   "utils"     .   $slash;
        $obj->web               =   $dir  .   "web"       .   $slash;
        $obj->shared            =   $dir  .   "web"       .   $slash .    "shared" .  $slash;
        $obj->domains           =   $dir  .   "web"       .   $slash .    "domains" . $slash;
        $obj->uploads           =   $dir  .   "web"       .   $slash .    "uploads" . $slash;
        $obj->uploads_no_root   =   "uploads" . $slash;

        self::internal_mk_tmp_dir($obj->temp, $obj->root);
        self::updated_workers($obj->workers, $obj->framework);

        self::$server = $obj;
    }

    protected static function set_internal_site_data(array $options) : void {
        $to_object = function (&$value) : void {
            $value = (object) $value;
        };

        $obj = array_merge([
            "author" => $options['author'] ?? null,
            "name" => $options['name'] ?? null,
            "color" => $options['color'] ?? null,
            "mail" => [
                ...$options['mail'] ?? []
            ],
            "tel" => $options['tel'] ?? null,
            "others" => $options['others'] ?? null,
        ], $options );

        $to_object($obj['name']);
        $to_object($obj['color']);
        $to_object($obj['mail']);
        $to_object($obj['tel']);
        $to_object($obj['others']);

        self::$site = (object) $obj;
    }

    #[ObjectShape([
        "lay" => 'string',
        "lay_static" => 'string',
        "framework" => 'string',
        "root" => 'string',
        "temp" => 'string',
        "bricks" => 'string',
        "db" => 'string',
        "utils" => 'string',
        "web" => 'string',
        "shared" => 'string',
        "domains" => 'string',
        "uploads" => 'string',
        "uploads_no_root" => 'string',
    ])]
    public static function server_data() : object
    {
        if(!isset(self::$server)){
            self::set_dir();
            self::set_internal_res_server(self::$dir);
        }

        return self::$server;
    }


    #[ObjectShape([
        "base" => 'string',
        "proto" => 'string',
        "base_no_proto" => 'string',
        "base_no_proto_no_www" => 'string',
        "domain" => 'string',
        "domain_no_proto" => 'string',
        "domain_no_proto_no_www" => 'string',
        "author" => 'string',
        "global_api" => 'string',
        "name" => 'object[long, short]',
        "color" => 'object[pry, sec]',
        "mail" => 'object[0,1,...n]',
        "tel" => 'object[0,1,...n]',
        "others" => 'object',
    ])]
    public static function site_data() : object
    {
        self::is_init(true);
        return self::$site;
    }

    public function send_to_client(array $values) : string {
        self::is_init();

        foreach ($values as $v){
            self::$CLIENT_VALUES .= $v;
        }

        return self::$CLIENT_VALUES;
    }

    public function get_client_values() : string {
        self::is_init();
        return self::$CLIENT_VALUES;
    }

    private static function internal_mk_tmp_dir(string $temp_dir, string $root) : void
    {
        LayDir::make($temp_dir, 0755, true);

        //TODO: Delete this after all legacy projects have been updated. Delete the root arg as well
        // START
        $old_temp_dir = $root . ".lay_temp";

        if(is_dir($old_temp_dir)) {
            $new_dir_is_empty = LayDir::is_empty($temp_dir);

            if($new_dir_is_empty) {
                rmdir($temp_dir);
                rename($old_temp_dir, $temp_dir);
            }
        }

        //TODO: Delete this after all legacy projects have been updated
        //END
    }

    private static function updated_workers(string $worker_dir, string $framework_root) : void
    {
        LayDir::make($worker_dir, 0755, true);

        $worker = $worker_dir . "mail-processor.php";

        if(!@$_SESSION[self::$SESSION_KEY]['workers']['mail'] && !file_exists($worker)) {
            copy(
                $framework_root . "workers" . DIRECTORY_SEPARATOR . "mail-processor.php",
                $worker,
            );

            $_SESSION[self::$SESSION_KEY]['workers']['mail'] = true;
        }
    }

    public static function mk_tmp_dir () : string {
        $dir = self::server_data()->temp;

        LayDir::make($dir, 0755, true);

        return $dir;
    }

    public static function load_env() : void {
        if(self::$env_loaded)
            return;

        Dotenv::createImmutable(self::server_data()->root)->load();
    }
}
