<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\View\Tags;

use BrickLayer\Lay\Core\View\Tags\Traits\Standard;
use BrickLayer\Lay\Core\View\ViewSrc;
use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;

final class Script
{
    private const ATTRIBUTES = [
        "defer" => "true",
        "type" => "text/javascript",
    ];

    use Standard;

    private bool $prepend_domain_on_src = true;

    public function type(string $type): self
    {
        return $this->attr('type', $type);
    }

    public function async(bool $choice): self
    {
        return $this->attr('async', (string)$choice);
    }

    public function dont_prepend(): self
    {
        $this->prepend_domain_on_src = false;
        return $this;
    }

    public function src(string $src, bool $print = true): string
    {
        $src = ViewSrc::gen($src, $this->prepend_domain_on_src);

        if (!isset($this->attr['defer']))
            $this->defer(true);

        $attr = $this->get_attr(function (&$value, $key) {
            if ($key == "defer") {
                if (!$value)
                    return LayLoop::CONTINUE;

                $value = "true";
            }

            return LayLoop::FLOW;
        });

        $link = "\n\t<script src=\"$src\" $attr></script>";

        if ($print)
            echo $link;

        return $link;
    }

    public function defer(bool $choice): self
    {
        return $this->attr('defer', (string)$choice);
    }

}
