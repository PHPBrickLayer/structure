<?php

namespace BrickLayer\Lay\Core\View;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\Enums\DomainType;
use BrickLayer\Lay\Libs\LayArray;

abstract  class DomainResource
{
    private static object $resource;
    private static object $plaster;

    private static function domain () : object
    {
        $data = Domain::current_route_data("*");
        return (object) $data;
    }

    public static function init() : void
    {
        $data = LayConfig::site_data();
        $obj = new \stdClass();

        $domain = self::domain();
        $base = $domain->domain_base;
        $env_src = $data->use_prod && LayConfig::$ENV_IS_PROD ? 'prod' : 'dev';

        $obj->root =       $base;
        $obj->upload =     $base . "uploads/";
        $obj->static =     $base . "static/";
        $obj->static_env = $obj->static . $env_src . "/";

        $obj->css =     $obj->static_env . "css/";
        $obj->img =     $obj->static_env . "images/";
        $obj->js =      $obj->static_env . "js/";
        $obj->ui =      $obj->static_env . "ui/";
        $obj->plugins = $obj->static_env . "plugins/";

        $shared = $obj->root . "shared/";
        $obj->shared = (object) [
            "root" =>   $shared,
            "static" => $shared         . "static/",
            "env"    => $shared         . "static/" . $env_src,
            "css" =>    $shared         . "static/" . $env_src . "/css/",
            "img" =>    $shared         . "static/" . $env_src . "/images/",
            "js" =>     $shared         . "static/" . $env_src . "/js/",
            "plugins" =>$shared         . "static/" . $env_src . "/plugins/",
            "img_default" => (object) [
                "logo" =>       $shared . "static/" . $env_src . "/images/logo.png",
                "favicon" =>    $shared . "static/" . $env_src . "/images/favicon.png",
                "icon" =>       $shared . "static/" . $env_src . "/images/icon.png",
                "meta" =>       $shared . "static/" . $env_src . "/images/meta.png",
            ]
        ];

        $obj->domain = $domain;

        $obj->lay = (object) [
            "uri" => $shared . "lay/",
            "root" => $domain->domain_root . "shared" . DIRECTORY_SEPARATOR . "lay" . DIRECTORY_SEPARATOR,
        ];

        if(isset(self::$resource))
            $obj = (object) array_merge((array) $obj, (array) self::$resource);

        self::$resource = $obj;
    }

    public static function set_res(string $key, mixed $value) : void
    {
        if(!isset(self::$resource))
            self::$resource = new \stdClass();

        self::$resource->{$key} = $value;
    }

    /**
     * @return  object{
     *      root: string,
     *      upload: string,
     *      static: string,
     *      static_env: string,
     *      css: string,
     *      img: string,
     *      js: string,
     *      plugins: string,
     *      ui: string,
     *      shared: object{
     *          root : string,
     *          static: string,
     *          env: string,
     *          css: string,
     *          img: string,
     *          js: string,
     *          plugins: string,
     *          img_default: object{
     *              logo: string,
     *              favicon: string,
     *              icon: string,
     *              meta: string
     *          }
     *      },
     *     lay: object{
     *          uri: string,
     *          root: string
     *     },
     *     domain: object{
     *      host: string,
     *      route: string,
     *      route_as_array: array,
     *      route_has_end_slash: bool,
     *      domain_name: string,
     *      domain_type: DomainType,
     *      domain_id: string,
     *      domain_root: string,
     *      domain_referrer: string,
     *      domain_uri: string,
     *      domain_base: string,
     *      pattern: string,
     *      plaster: string,
     *      layout: string,
     *      int<0, max>
     *     }
     * }
     */
    public static function get() : object
    {
        if(!isset(self::$resource))
            self::init();

        return self::$resource;
    }

    public static function make_plaster(object $values) : void
    {
        self::$plaster = $values;
    }

    private static function make_plaster_local(mixed $values) : void
    {
        if(isset(self::$plaster->local))
            self::$plaster->local = $values;
        else {
            self::$plaster = new \stdClass();
            self::$plaster->local = $values;
        }
    }

    /**
     * You are getting everything you sent through the `ViewCast` aka `Plaster` class
     * from this method, in the exact same way
     * @return object{
     *     head: string,
     *     body: string,
     *     script: string,
     *     page: object{
     *          charset: string,
     *          base: string,
     *          route: string,
     *          url: string,
     *          canonical: string,
     *          title: string,
     *          title_raw: string,
     *          desc: string,
     *          img: string,
     *          author: string,
     *     },
     *     local: object
     * }
     */
    public static function plaster() : object
    {
        return self::$plaster ?? new \stdClass();
    }


    /**
     * @param string $file path to file
     * @param string $type use predefined file path [plaster, layout, project, etc.]. Check code for more info
     * @param array{
     *     local: array,
     *     once: bool,
     *     as_string: bool,
     *     use_referring_domain: bool,
     *     use_get_content: bool,
     *     error_file_not_found: bool,
     *     get_last_mod: bool,
     * } $option
     * @return string|null|array{
     *     last_mod: int,
     *     content: String,
     * }
     * @throws \Exception
     */
    public static function include_file(string $file, string $type = "inc", array $option = []) : string|null|array
    {
        LayConfig::is_init();

        $domain = self::get()->domain;
        $going_online = false;

        // OPTIONS
        $local = $option['local'] ?? [];
        $once = $option['once'] ?? true;
        $as_string = $option['as_string'] ?? false;
        $use_referring_domain = $option['use_referring_domain'] ?? true;
        $use_get_content = $option['use_get_content'] ?? false;
        $error_file_not_found = $option['error_file_not_found'] ?? true;
        $get_last_mod = $option['get_last_mod'] ?? false;

        $replace = fn($src) => !$use_referring_domain ? $src : str_replace(
            DIRECTORY_SEPARATOR . $domain->domain_name . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . $domain->domain_referrer . DIRECTORY_SEPARATOR,
            $src
        );

        switch ($type) {
            default:
                $type = "";
                $type_root = $replace($domain->domain_root);
                break;
            case "online":
                $type = "";
                $type_root = "";
                $going_online = true;
                $as_string = true;
                $use_get_content = true;
                break;
            case "inc":
                $type = ".inc";
                $type_root = $replace($domain->layout);
                break;
            case "view":
                $type = ".view";
                $type_root = $replace($domain->plaster);
                break;
            case "project":
                $type = "";
                $type_root = LayConfig::server_data()->root;
                break;
            case "layout":
                $type = "";
                $type_root = $replace($domain->layout);
                break;
            case "plaster":
                $type = "";
                $type_root = $replace($domain->plaster);
                break;
        }

        $file = str_replace($type, "", $file);
        $file = $type_root . $file . $type;

        self::make_plaster_local(
            LayArray::merge(
                self::plaster()->local ?? [],
                $local, true
            )
        );

        if(!$going_online && !file_exists($file)) {
            if($error_file_not_found)
                Exception::throw_exception("execution Failed trying to include file ($file)", "FileNotFound");

            return null;
        }

        if($as_string) {
            ob_start();

            if($use_get_content)
                echo file_get_contents($file);
            else
                $once ? include_once $file : include $file;

            $x = ob_get_clean();

            if($get_last_mod)
                return [
                    "last_mod" => filemtime($file),
                    "content" => $x
                ];

            return $x;
        }

        $once ? include_once $file : include $file;
        return null;
    }


}