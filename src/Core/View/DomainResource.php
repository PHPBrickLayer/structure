<?php

namespace BrickLayer\Lay\Core\View;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Core\View\Enums\DomainType;
use BrickLayer\Lay\Libs\LayObject;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\ObjectShape;

class DomainResource
{
    use IsSingleton;

    private static object $resource;
    private static object $plaster;

    #[ObjectShape([
        'route' => 'string',
        'route_as_array' => 'array',
        'domain_type' => DomainType::class,
        'domain_id' => 'string',
        'domain_uri' => 'string',
        'domain_root' => 'string',
        'pattern' => 'string',
        0, 1, 2, 3, 4, 5, 6, 7, 8
    ])]
    private static function domain () : object
    {
        $data = Domain::current_route_data("*");
        $data['plaster'] = $data['domain_root'] . "plaster" . DIRECTORY_SEPARATOR;
        $data['layout'] = $data['domain_root'] . "layout" . DIRECTORY_SEPARATOR;

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

        $shared = $obj->root . "shared/";
        $obj->shared = (object) [
            "root" =>   $shared,
            "static" => $shared         . "static/",
            "env"    => $shared         . "static/" . $env_src,
            "css" =>    $shared         . "static/" . $env_src . "/css/",
            "img" =>    $shared         . "static/" . $env_src . "/images/",
            "js" =>     $shared         . "static/" . $env_src . "/js/",
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

    #[ObjectShape([
        'root' => 'string',
        'upload' => 'string',
        'static' => 'string',
        'static_env' => 'string',
        'css' => 'string',
        'img' => 'string',
        'js' => 'string',
        'ui' => 'string',
        'shared' => 'object [root, static, env, css, img, js, img_default [object [logo, favicon, icon, meta]]]',
        'domain' => 'object [domain_uri, route, route_as_array, domain_type, domain_name, domain_id, domain_root, pattern, 0, 1 ...n]',
        'lay' => 'object [uri, root]',
    ])]
    public static function get() : object
    {
        return self::$resource;
    }

    public static function make_plaster(object $values) : void
    {
        self::$plaster = $values;
    }

    /**
     * You are getting everything you sent through the `ViewCast` aka `Plaster` class
     * from this method, in the exact same way
     * @return object
     */
    #[ObjectShape([
        "head" => "string",
        "body" => "string",
        "script" => "string",
        "page" => "object [charset, base, route, url, canonical, title, desc, img, author]",
        "local" => "object",
    ])]
    public static function plaster() : object
    {
        return self::$plaster;
    }

}