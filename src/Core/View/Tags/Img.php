<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\View\Tags;

use BrickLayer\Lay\Core\View\Tags\Traits\Standard;
use BrickLayer\Lay\Core\View\ViewSrc;
use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;

final class Img {
    use Standard;

    private bool $prepend_domain_on_src = true;


    public function width(int|string $width) : self
    {
        return $this->attr('width',(string)  $width);
    }

    public function height(int|string $height) : self
    {
        return $this->attr('height', (string) $height);
    }

    public function ratio(int|string $width, int|string $height) : self {
        $this->width((string) $width);
        $this->height((string) $height);
        return $this;
    }

    public function alt(string $alt_text) : self
    {
        return $this->attr('alt', $alt_text);
    }

    public function dont_prepend() : self
    {
        $this->prepend_domain_on_src = false;
        return $this;
    }

    public function srcset(string $srcset) : self
    {
        $srcset = ViewSrc::gen($srcset, $this->prepend_domain_on_src);
        return $this->attr('srcset', $srcset);
    }

    public function src(string $src, bool $lazy_load = true) : string
    {
        $src = ViewSrc::gen($src, $this->prepend_domain_on_src);
        $lazy_load = $lazy_load ? 'lazy' : 'eager';

        $alt = null;

        $attr = $this->get_attr(function($v, $k) use (&$alt) {
            if($k == "alt") {
                $alt = $v;
                return LayLoop::CONTINUE;
            }
        });

        $alt ??= substr($src, strrpos($src, "/") + 1);

        return <<<LNK
            <img src="$src" loading="$lazy_load" $attr alt="$alt">
        LNK;
    }

}
