<?php
declare(strict_types=1);

namespace BrickLayer\Lay\BobDBuilder\Helper\Console;

use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Background;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Foreground;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Style;

/**
 * Used to log strings with custom colors to console using php
 *
 * Inspired by this gist
 * @link https://gist.github.com/sallar/5257396
 *
 * Original colored CLI output script:
 * (C) Jesse Donat https://github.com/donatj
 */
class Console {
    /**
     * Logs a string to console.
     * @param string $text Input String
     * @param Foreground $color Text Color
     * @param Background|null $bg_color Background Color
     * @param Style|null $style Font style
     * @param boolean $newline Append EOF?
     * @return void
     */
    public static function log(string $text = '', Foreground $color = Foreground::normal, ?Background $bg_color = null, ?Style $style = null, bool $newline = true) : void
    {
        $colored_string = "\033[" . $color->value . "m";

        if($bg_color)
            $colored_string .= "\033[" . $bg_color->value . "m";

        if($style)
            $colored_string .= "\033[" . $style->value . "m";

        echo $colored_string . $text . "\033[0m" . ($newline ? PHP_EOL : '');
    }

    /**
     * Plays a bell sound in console (if available)
     * @param int $count Bell play count
     * @return void
     */
    public static function bell(int $count = 1) : void
    {
        echo str_repeat("\007", $count);
    }

}