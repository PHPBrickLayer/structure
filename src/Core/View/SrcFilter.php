<?php

namespace BrickLayer\Lay\Core\View;

class SrcFilter
{
    public static function go(string $src) : string {
        $client = DomainResource::get();

        return str_replace(
            [
                "@shared/",             "@#/",              "@static/",
                "@shared_js/",          "@js/",             "@static_env/",
                "@shared_img/",         "@img/",            "@ui/",
                "@shared_css/",         "@css/",
            ],
            [
                $client->shared->root,  $client->root,      $client->static,
                $client->shared->js,    $client->js,        $client->static_env,
                $client->shared->img,   $client->img,       $client->static_env . "ui/",
                $client->shared->css,   $client->css,
            ],
            $src
        );
    }
}