<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\Traits;
use BrickLayer\Lay\Libs\ID\Gen;
use BrickLayer\Lay\Libs\LayDir;
use Dotenv\Dotenv;

trait Resources {
    private static object $server;
    private static object $site;

    private static bool $env_loaded = false;

    private static string $CLIENT_VALUES = "";

    protected static function set_internal_res_server(string $dir) : void {

        $slash = DIRECTORY_SEPARATOR;

        $obj = new \stdClass();

        $obj->framework         =   $dir       .   "vendor"   . $slash . "bricklayer" . $slash .   "structure" . $slash;
        $obj->lay_static        =   $obj->framework  . "src"        . $slash . "static"     . $slash;
        $obj->framework_workers =   $obj->framework  . "__internal" . $slash . "workers"    . $slash;


        $obj->root              =   $dir;
        $obj->bricks            =   $dir  .   "bricks"    .   $slash;
        $obj->db                =   $dir  .   "db"        .   $slash;
        $obj->utils             =   $dir  .   "utils"     .   $slash;

        $obj->lay               =   $dir  .   ".lay"      .  $slash;
        $obj->workers           =   $obj->lay    .   "workers"      .   $slash;

        $obj->temp              =   $obj->lay    .   "temp"         .   $slash;
        $obj->exceptions        =   $obj->temp   .   "exceptions"   .   $slash;
        $obj->cron_outputs      =   $obj->temp   .   "cron_outputs" .   $slash;

        $obj->web               =   $dir  .   "web"       .   $slash;
        $obj->shared            =   $obj->web   .    "shared" .  $slash;
        $obj->domains           =   $obj->web   .    "domains" . $slash;
        $obj->uploads           =   $obj->web   .    "uploads" . $slash;

        $obj->uploads_no_root   =   "uploads" . $slash;

        //TODO: Delete the implementation and this section soon
        self::internal_mk_tmp_dir($obj->temp, $obj->root);

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

    /**
     * @psalm-return  object{
     *     lay: string,
     *     lay_static: string,
     *     framework: string,
     *     framework_workers: string,
     *     workers: string,
     *     root: string,
     *     temp: string,
     *     exceptions: string,
     *     cron_outputs: string,
     *     bricks: string,
     *     db: string,
     *     utils: string,
     *     web: string,
     *     shared: string,
     *     domains: string,
     *     uploads: string,
     *     uploads_no_root: string
     * }
     */
    public static function server_data() : object
    {
        if(!isset(self::$server)){
            self::set_dir();
            self::set_internal_res_server(self::$dir);
        }

        return self::$server;
    }


    /**
     * @psalm-return  object{
     *      base: string,
     *      proto: string,
     *      base_no_proto: string,
     *      base_no_proto_no_www: string,
     *      domain: string,
     *      domain_no_proto: string,
     *      domain_no_proto_no_www: string,
     *      server_mocked: bool,
     *      author: string,
     *      global_api: string,
     *      name: object{
     *          long : string,
     *          short: string
     *      },
     *     color: object{
     *          pry: string,
     *          sec: string
     *     },
     *     mail: object<int>,
     *     tel: object<int>,
     *     others: object
     * }
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

    private static function internal_mk_tmp_dir(string $temp_dir, string $root) : void
    {
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

    /**
     * Returns a generated project ID if it is generated or found, else returns null
     * @param bool $overwrite
     * @return string|null
     */
    public static function generate_project_identity(bool $overwrite = false) : ?string
    {
        $identity_file = self::server_data()->lay . "identity";
        $static_id = self::server_data()->project_id ?? null;

        $gen_id = function () use ($identity_file) {
            $new_id = Gen::uuid(32);
            self::server_data()->project_id = $new_id;

            file_put_contents($identity_file, $new_id);
            return $new_id;
        };

        if($overwrite)
            return $gen_id();

        if($static_id)
            return $static_id;

        if(!file_exists($identity_file))
            return $gen_id();

        if(empty(file_get_contents($identity_file)))
            return $gen_id();

        return null;
    }

    public static function get_project_identity() : string
    {
        $static_id = self::server_data()->project_id ?? null;

        if($static_id)
            return $static_id;

        if($current_id = self::generate_project_identity())
            return $current_id;

        $current_id = Gen::uuid(32);
        file_put_contents(self::server_data()->lay . "identity", $current_id);

        return $current_id;
    }

}
