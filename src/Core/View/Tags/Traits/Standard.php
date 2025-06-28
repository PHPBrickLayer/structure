<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Core\View\Tags\Traits;


use BrickLayer\Lay\Core\View\Tags\Anchor;
use BrickLayer\Lay\Core\View\Tags\Img;
use BrickLayer\Lay\Core\View\Tags\Link;
use BrickLayer\Lay\Core\View\Tags\Script;
use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;

trait Standard {
    private static self $me;

    private array $attr = [];

    public static function new() : self {
        self::$me = new self();

        return self::$me;
    }

    public static function clear() : void {
        self::$me->attr = [];
    }

    /**
     * Element Attributes
     *
     * @param string $key
     * @param string $value
     * @return self
     */
    public function attr(string $key, string $value) : self
    {
        $this->attr[$key] = $value;
        return $this;
    }

    /**
     * Data Attributes
     *
     * @param string $key
     * @param string $value
     * @return self
     */
    public function data(string $key, string $value) : self
    {
        $this->attr["data-" . $key] = $value;
        return $this;
    }

    /**
     * @param string ...$rules CSS Styles
     */
    public function style(string ...$rules) : self
    {
        $this->attr["style"] = implode(";", $rules);
        return $this;
    }

    public function class(string $class_name) : self
    {
        $this->attr['class'] = $class_name;
        return $this;
    }

    private function get_attr(?\Closure $callback = null) : string
    {
        $attr = "";

        foreach($this->attr as $key => $value) {
            if($callback) {
                $rtn = $callback($value, $key);

                if($rtn == LayLoop::CONTINUE)
                    continue;
            }

            $attr .= $key . '="' . $value . '" ';
        }

        return $attr;
    }
}
