<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\View\Tags;

use BrickLayer\Lay\Core\View\Tags\Traits\Standard;
use BrickLayer\Lay\Core\View\ViewSrc;

final class Img {
    private const ATTRIBUTES = [
        "alt" => "Page Image"
    ];

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

    public function src(string $src, bool $lazy_load = true) : string {
        $src = ViewSrc::gen($src, $this->prepend_domain_on_src);
        $lazy_load = $lazy_load ? 'lazy' : 'eager';
        $attr = $this->get_attr();

        return <<<LNK
            <img src="$src" loading="$lazy_load" $attr>
        LNK;
    }

}
