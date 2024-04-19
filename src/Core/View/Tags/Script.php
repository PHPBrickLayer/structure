<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core\View\Tags;

use BrickLayer\Lay\Core\Enums\CustomContinueBreak;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\View\ViewSrc;

final class Script {
    private const ATTRIBUTES = [
        "defer" => "true",
        "type" => "text/javascript",
    ];

    use \BrickLayer\Lay\Core\View\Tags\Traits\Standard;

    public function type(string $type) : self {
        return $this->attr('type', $type);
    }

public function defer(bool $choice) : self {
        return $this->attr('defer', (string) $choice);
    }

public function async(bool $choice) : self {
        return $this->attr('async', (string) $choice);
    }

public function src(string $src, bool $print = true) : string {
        $src = ViewSrc::gen($src);

        if(!isset($this->attr['defer']))
            $this->defer(true);

        $attr = $this->get_attr(function (&$value, $key){
            if($key == "defer") {
                if(!$value)
                    return CustomContinueBreak::CONTINUE;

                $value = "true";
            }

            return CustomContinueBreak::FLOW;
        });

        $link = "\n\t<script src=\"$src\" $attr></script>";

        if($print)
            echo $link;

        return $link;
    }

}
