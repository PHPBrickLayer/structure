<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\View;

use BrickLayer\Lay\Core\LayConfig;

final class ViewSrc {
    public static function gen(string $src) : string
    {
        $client = DomainResource::get();

        $src = str_replace(
            [
                "@shared/",             "@#/",              "@static/",
                "@shared_js/",          "@js/",
                "@shared_img/",         "@img/",
                "@shared_css/",         "@css/",
            ],
            [
                $client->shared->root,  $client->root,      $client->static,
                $client->shared->js,    $client->js,
                $client->shared->img,   $client->img,
                $client->shared->css,   $client->css,
            ],
            $src
        );

        $base = $client->domain->domain_base;

        if(!str_starts_with($src, $base))
            return $src;

        $local_file = str_replace($base, $client->domain->domain_root, $src);

        $src .= "?mt=" . @filemtime($local_file);

        return $src;
    }
}
