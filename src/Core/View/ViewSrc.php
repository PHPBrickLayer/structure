<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\View;

final class ViewSrc {
    /**
     * @param string $src
     * @param bool $prepend_domain_base
     * @param string|null $api_replacement A domain you want to use to replace Api in the url
     * @return string
     */
    public static function gen(string $src, bool $prepend_domain_base = true, ?string $api_replacement = 'Default') : string
    {
        $src = SrcFilter::go($src);

        $client = DomainResource::get();

        $base = str_replace("Api", $api_replacement, $client->domain->domain_base);

        if(!str_starts_with($src, $base)) {
            if(!$prepend_domain_base || str_starts_with($src, "http") || str_starts_with($src, "data:"))
                return $src;

            return $base . $src;
        }

        $local_file = str_replace($base, $client->domain->domain_root, $src);

        $src .= "?mt=" . @filemtime($local_file);

        return $src;
    }
}
