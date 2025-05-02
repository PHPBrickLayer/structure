<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\View\Tags;

use BrickLayer\Lay\Core\View\Tags\Traits\Standard;
use BrickLayer\Lay\Core\View\ViewSrc;
use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;

final class Link
{
    use Standard;

    private const ATTRIBUTES = [
        "rel" => "stylesheet",
        "media" => "all",
        "type" => "text/css",
    ];
    private bool $rel_set = false;
    private bool $prepend_domain_on_src = true;

    public static function clear(): void
    {
        self::$me->rel_set = false;
        self::$me->attr = self::ATTRIBUTES ?? [];
    }

    public function media(string $media): Standard|Img|Anchor|self|Script
    {
        return $this->attr('media', $media);
    }

    public function type(string $type): Standard|Img|Anchor|self|Script
    {
        return $this->attr('type', $type);
    }

    public function href(string $href, bool $print = true, bool $lazy = false): string
    {
        $href = ViewSrc::gen($href, $this->prepend_domain_on_src);

        if (!$this->rel_set)
            $this->rel("stylesheet");

        $media = $this->attr['media'] ?? "all";
        $as = $this->attr['as'] ?? "style";
        $rel = $this->attr['rel'] ?? "stylesheet";
        $type = $this->attr['type'] ?? "text/css";
        $attr = $this->get_attr(function ($v, $k) use ($lazy) {

            if (($lazy && ($k == "rel" || $k == "media" || $k == "type")) || $k == "src") {
                return LayLoop::CONTINUE;
            }

            return $v;
        });

        $link = "\n\t<link href=\"$href\" $attr />";

        if ($lazy) {
            $attr = <<<ATTR
            media="print" onload="this.media='$media'; this.onload=null" rel="$rel" href="$href" type="$type" as="$as"  $attr 
            ATTR;

            $link = "\n\t<link $attr>\n\t<noscript><link rel=\"$rel\" media='$media' type='$type' href=\"$href\" ></noscript>";
        }

        if ($print)
            echo $link;

        return $link;
    }

    public function rel(string $rel): Standard|Img|Anchor|self|Script
    {
        $this->rel_set = true;
        return $this->attr('rel', $rel);
    }

}
