<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\View\Tags;

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

    public function href(?string $link = "", ?string $domain_id = null) : self {
        $dom = DomainResource::get()->domain;

        $link = is_null($link) ? '' : $link;
        $link = ltrim($link, "/");

        $base = LayConfig::site_data();
        $base_full = $dom->domain_uri;

        if($domain_id) {
            $pattern = Domain::new()->get_domain_by_id($domain_id)['patterns'][0];

            if($pattern == "*")
                $pattern = "";

            $base_full = str_replace($dom->pattern . "/", $pattern, $base_full);

            $same_domain = $domain_id == $dom->domain_id;

            if($dom->pattern != "*" && LayConfig::$ENV_IS_PROD) {
                $x = explode(".", $base->domain_no_proto, 2);
                $base_full = $base->proto . "://" . $dom->pattern . "." . end($x);
                $base_full = rtrim($base_full, "/") . "/";
                $dom->pattern = "*";
            }

            if(!$same_domain && $dom->domain_type == DomainType::SUB) {
                $x = explode(".", $base->domain_no_proto, 2);
                $base_full = $base->proto . "://" . end($x) . "/";
                $dom->pattern = "*";
            }

        }


        if(str_starts_with($link, "#"))
            $link = ViewBuilder::new()->request("route") . $link;

        $this->link = $base_full . $link;

        return $this;
    }

    public function get_href() : string {
        return $this->link;
    }

    public function class(string $class_name) : self {
        return $this->attr('class', $class_name);
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
