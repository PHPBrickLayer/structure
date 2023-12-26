<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\View;

use BrickLayer\Lay\Core\LayConfig;

final class ViewSrc {
    public static function gen(string $src) : string
    {
        $src = SrcFilter::go($src);

        $client = DomainResource::get();

        $base = $client->domain->domain_base;

        if(!str_starts_with($src, $base))
            return $src;

        $local_file = str_replace($base, $client->domain->domain_root, $src);

        $src .= "?mt=" . @filemtime($local_file);

        return $src;
    }
}
