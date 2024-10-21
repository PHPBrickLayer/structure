<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\View\Tags;

use BrickLayer\Lay\Core\Exception;
use JetBrains\PhpStorm\ExpectedValues;

use BrickLayer\Lay\Core\View\Domain;
use BrickLayer\Lay\Core\View\DomainResource;
use BrickLayer\Lay\Core\View\Tags\Traits\Standard;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\Enums\DomainType;
use BrickLayer\Lay\Core\View\ViewBuilder;

final class Anchor {
    private string $link = "";

    use Standard;

    public function href(?string $link = "", ?string $domain_id = null, ?bool $use_subdomain = null) : self {
        $dom = DomainResource::get()->domain;
        $link = is_null($link) ? '' : $link;
        $link = ltrim($link, "/");
        $use_subdomain = $use_subdomain !== null ? $use_subdomain : LayConfig::new()->use_domain_as_sub();

        $base = LayConfig::site_data();
        $base_full = $dom->domain_uri;

        if($domain_id) {
            $pattern = @Domain::new()->get_domain_by_id($domain_id)['patterns'][0];

            if(!$pattern)
                Exception::throw_exception("Domain with domain-id: [$domain_id] doesn't exist. Please check your domain list", "VoidDomainID");

            $same_domain = $domain_id == $dom->domain_id;

            if(!$same_domain) {
                $pattern = $pattern == "*" ? "" : $pattern;

                if($dom->domain_type == DomainType::SUB) {
                    $x = explode(".", $base->domain_no_proto, 2);
                    $base_full = $base->proto . end($x);

                    if($use_subdomain && !empty($pattern))
                        $base_full = $base->proto . $pattern . "." . end($x);
                }
                else {
                    $base_full = explode($dom->pattern . "/", $base_full, 2)[0] . $pattern;

                    if($use_subdomain && !empty($pattern) && LayConfig::$ENV_IS_PROD)
                        $base_full = $base->proto . $pattern . "." . $base->domain_no_proto_no_www;
                }
            }

            $base_full = rtrim($base_full, "/") . "/";
        }

        if(str_starts_with($link, "#"))
            $link = ViewBuilder::new()->request("route") . $link;

        $this->link = $base_full . $link;

        return $this;
    }

    public function get_href() : string {
        return $this->link;
    }

    public function target(#[ExpectedValues(['_blank','_parent','_top','_self'])] string $target) : self {
        return $this->attr('target', $target);
    }

    public function children(string ...$children) : string {
        $attr = $this->get_attr();
        $children = implode(" ", $children);

        return <<<LNK
            <a $attr href="{$this->link}">$children</a>
        LNK;
    }

}
