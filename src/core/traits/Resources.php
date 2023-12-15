<?php
declare(strict_types=1);
namespace BrickLayer\Lay\core\traits;
use Dotenv\Dotenv;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\ExpectedValues;
use BrickLayer\Lay\core\Exception;
use BrickLayer\Lay\libs\LayObject;
use JetBrains\PhpStorm\ObjectShape;

trait Resources {
    private static object $server;
    private static object $site;

    private static bool $env_loaded = false;

    private static string $CLIENT_VALUES = "";
    
    protected static function set_internal_res_server(string $dir) : void {

        $slash = DIRECTORY_SEPARATOR;

        $obj = new \stdClass();

        $obj->lay     =   $dir  .   "vendor"    .   $slash .    "bricklayer" . $slash .   "lay" . $slash;
        $obj->root    =   $dir;
        $obj->temp    =   $dir  .   ".lay_temp" .   $slash;
        $obj->bricks  =   $dir  .   "bricks"    .   $slash;
        $obj->utils   =   $dir  .   "utils"     .   $slash;
        $obj->web     =   $dir  .   "web"       .   $slash;
        $obj->domains =   $dir  .   "web"       .   $slash .    "domains" . $slash;
        
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

    public static function res_server() : object
    {
        return self::server_data();
    }

    #[ObjectShape(["lay" => 'string', "root" => 'string', "temp" => 'string', "bricks" => 'string', "utils" => 'string', "web" => 'string', "domains" => 'string',])]
    public static function server_data() : object
    {
        if(!isset(self::$server)){
            self::set_dir();
            self::set_internal_res_server(self::$dir);
        }

        return self::$server;
    }

    /**
     * @param object $resource
     * @param string $index
     * @param array $accepted_index
     * @return void
     * @throws \Exception
     */
    private static function set_res(object &$resource, array $accepted_index = [], ...$index) : void {
        if(!empty($accepted_index) && !in_array($index[0],$accepted_index,true))
            Exception::throw_exception(
                "The index [$index[0]] being accessed may not exist or is forbidden.
                You can only access these index: " . implode(", ",$accepted_index),"Invalid Index"
            );

        $value = end($index);
        array_pop($index);

        $object_push = function (&$key) use ($value) {
            $key = $value;
        };

        switch (count($index)){
            default:
                $object_push($resource->{$index[0]});
                break;
            case 2:
                $object_push($resource->{$index[0]}->{$index[1]});
                break;
            case 3:
                $object_push($resource->{$index[0]}->{$index[1]}->{$index[2]});
                break;
            case 4:
                $object_push($resource->{$index[0]}->{$index[1]}->{$index[2]}->{$index[3]});
                break;
            case 5:
                $object_push($resource->{$index[0]}->{$index[1]}->{$index[2]}->{$index[3]}->{$index[4]});
                break;
            case 6:
                $object_push($resource->{$index[0]}->{$index[1]}->{$index[2]}->{$index[3]}->{$index[4]}->{$index[5]});
                break;
        }
    }

    public static function set_site_data(string $data_index, mixed ...$chain_and_value) : void {
        self::is_init();

        self::set_res(self::$site, [], $data_index, ...$chain_and_value);
    }

    /**
     * @param string $data_index
     * @param string ...$index_chain
     * @see set_internal_site_data
     * @return mixed
     */
    public function get_site_data(string $data_index = "", string ...$index_chain) : mixed {
        self::is_init(true);
        return self::get_res("site_data", self::$site, $data_index, ...$index_chain);
    }

    /**
     * @see set_internal_site_data
     * @return object
     */
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

    public static function mk_tmp_dir () : string {
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
