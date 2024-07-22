<?php

namespace BrickLayer\Lay\Core\View;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Core\View\Enums\DomainType;

class DomainResource
{
    use IsSingleton;

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
     * @psalm-return  object{
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
        return self::$resource;
    }
    public static function make_plaster(object $values) : void
    {
        self::$plaster = $values;
    }

    public static function make_plaster_local(mixed $values) : void
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
     * @psalm-return object{
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

}