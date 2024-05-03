<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\View\Tags;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\Enums\DomainType;
use BrickLayer\Lay\Core\View\ViewBuilder;
use BrickLayer\Lay\Core\View\Domain;
use BrickLayer\Lay\Core\View\ViewSrc;

final class Img {
    private const ATTRIBUTES = [
        "alt" => "Page Image"
    ];

    use \BrickLayer\Lay\Core\View\Tags\Traits\Standard;

    public function class(string $class_name) : self {
        return $this->attr('class', $class_name);
    }

    public function width(int|string $width) : self {
        return $this->attr('width',(string)  $width);
    }
    
    public function height(int|string $height) : self {
        return $this->attr('height', (string) $height);
    }

    public function ratio(int|string $width, int|string $height) : self {
        $this->width((string) $width);
        $this->height((string) $height);
        return $this;
    }

    public function alt(string $alt_text) : self {
        return $this->attr('alt', $alt_text);
    }

    public function src(string $src, bool $lazy_load = true, bool $prepend_domain = true) : string {
        $src = ViewSrc::gen($src, $prepend_domain);
        $lazy_load = $lazy_load ? 'lazy' : 'eager';
        $attr = $this->get_attr();

        return <<<LNK
            <img src="$src" loading="$lazy_load" $attr>
        LNK;
    }

}
