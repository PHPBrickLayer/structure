<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\View\Tags\Traits;


use BrickLayer\Lay\Core\Enums\CustomContinueBreak;
use BrickLayer\Lay\Core\View\Tags\Anchor;
use BrickLayer\Lay\Core\View\Tags\Img;
use BrickLayer\Lay\Core\View\Tags\Link;
use BrickLayer\Lay\Core\View\Tags\Script;

trait Standard {
    private static self $me;

    private array $attr = [];

    public static function new() : self {
        self::$me = new self();

        return self::$me;
    }

    public static function clear() : void {
        self::$me->attr = self::ATTRIBUTES ?? [];
    }

    public function attr(string $key, string $value) : self {
        $this->attr[$key] = $value;
        return $this;
    }

    /**
     * @param string ...$rules CSS Styles
     * @return self
     */
    public function style(string ...$rules) : self
    {
        $this->attr["style"] = implode(";", $rules);
        return $this;
    }

    public function class(string $class_name) : self {
        $this->attr['class'] = $class_name;
        return $this;
    }

    private function get_attr(?\Closure $callback = null) : string {
        $attr = "";

        foreach($this->attr as $key => $value) {
            if($callback) {
                $rtn = $callback($value, $key);

                if($rtn == CustomContinueBreak::CONTINUE)
                    continue;
            }

            $attr .= $key . '="' . $value . '" ';
        }

        return $attr;
    }
}
