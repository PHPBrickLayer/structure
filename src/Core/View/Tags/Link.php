<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\View\Tags;

use BrickLayer\Lay\Core\Enums\CustomContinueBreak;
use BrickLayer\Lay\Core\View\ViewSrc;

final class Link {
    private bool $rel_set = false;
    private const ATTRIBUTES = [
        "rel" => "stylesheet",
        "media" => "all",
        "type" => "text/css",
    ];

    use \BrickLayer\Lay\Core\View\Tags\Traits\Standard;

    public function rel(string $rel) : self {
        $this->rel_set = true;
        return $this->attr('rel', $rel);
    }

public static function clear() : void {
        self::$me->rel_set = false;
        self::$me->attr = self::ATTRIBUTES ?? [];
    }

public function media(string $media) : self {
        return $this->attr('media', $media);
    }

public function type(string $type) : self {
        return $this->attr('type', $type);
    }

public function href(string $href, bool $print = true, bool $lazy = false, bool $prepend_domain = true) : string {
        $href = ViewSrc::gen($href, $prepend_domain);

        if(!$this->rel_set)
            $this->rel("stylesheet");

        $media = $this->attr['media'] ?? "all";
        $as = $this->attr['as'] ?? "style";
        $rel = $this->attr['rel'] ?? "stylesheet";
        $type = $this->attr['type'] ?? "text/css";
        $lazy_type = $this->attr['lazy'] ?? "prefetch"; // 'preload' || 'prefetch';
        $attr = $this->get_attr(function ($v, $k) use ($lazy) {
            if(($lazy && $k == "rel") || $k == "src")
                return CustomContinueBreak::CONTINUE;

            return $v;
        });

        $link = "\n\t<link href=\"$href\" $attr />";

        if($lazy) {
            $attr = <<<ATTR
            media="print" onload="this.rel='$rel';this.media='$media'" rel="$lazy_type" href="$href" type="$type" as="$as"  $attr 
            ATTR;

            $link = "\n\t<link $attr>\n\t<noscript><link rel=\"$rel\" href=\"$href\" ></noscript>";
        }

        if($print)
            echo $link;

        return $link;
    }

}
