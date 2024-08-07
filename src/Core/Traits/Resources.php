<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\Traits;
use Dotenv\Dotenv;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\ExpectedValues;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Libs\LayObject;
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
        $obj->lay               =   $dir  .   "vendor"    .   $slash .    "bricklayer" . $slash .   "structure" . $slash;
        $obj->root              =   $dir;
        $obj->temp              =   $dir  .   ".lay_temp" .   $slash;
        $obj->bricks            =   $dir  .   "bricks"    .   $slash;
        $obj->db                =   $dir  .   "db"        .   $slash;
        $obj->utils             =   $dir  .   "utils"     .   $slash;
        $obj->web               =   $dir  .   "web"       .   $slash;
        $obj->shared            =   $dir  .   "web"       .   $slash .    "shared" .  $slash;
        $obj->domains           =   $dir  .   "web"       .   $slash .    "domains" . $slash;
        $obj->uploads           =   $dir  .   "web"       .   $slash .    "uploads" . $slash;
        $obj->uploads_no_root   =   "uploads" . $slash;

        self::mk_tmp_dir($obj->temp);

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

    public static function mk_tmp_dir (?string $temp_dir = null) : string {
        if($temp_dir) {
            if(!is_dir($temp_dir)) {
                umask(0);
                mkdir($temp_dir, 0755, true);
            }

            return $temp_dir;
        }

        $dir = self::server_data()->temp;

        if(!is_dir($dir)) {
            umask(0);
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    public static function load_env() : void {
        if(self::$env_loaded)
            return;

        Dotenv::createImmutable(self::server_data()->root)->load();
    }
}
