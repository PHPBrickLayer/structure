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
final class Console {

    /**
     * Logs a string to console.
     * @param string $text Input String
     * @param Foreground|Style $color Text Color
     * @param Background|null $bg_color Background Color
     * @param Style|null $style Font style
     * @param boolean $newline Append EOF?
     * @param bool $maintain_line
     * @return void
     */
    public static function log(string $text = '', Foreground|Style $color = Style::normal, ?Background $bg_color = null, ?Style $style = null, bool $newline = true, bool $maintain_line = false) : void
    {
        echo self::text($text, $color, $bg_color, $style, $newline, $maintain_line, true);
    }

    /**
     * Returns a formatted ASCII string
     *
     * @param string $text Input String
     * @param Foreground|Style $color Text Color
     * @param Background|null $bg_color Background Color
     * @param Style|null $style Font style
     * @param boolean $newline Append EOF?
     * @param bool $maintain_line
     * @param bool $ascii To add ascii characters to the formatted text. False by default
     * @return string
     */
    public static function text(string $text = '', Foreground|Style $color = Style::normal, ?Background $bg_color = null, ?Style $style = null, bool $newline = true, bool $maintain_line = false, bool $ascii = false) : string
    {
        if(!$ascii) {
            $newline = ($newline ? PHP_EOL : '');

            if($maintain_line)
                $newline = "\r";

            return $text . $newline;
        }

        $colored_string = "\033[" . $color->value . "m";

        if($bg_color)
            $colored_string .= "\033[" . $bg_color->value . "m";

        if($style)
            $colored_string .= "\033[" . $style->value . "m";

        $start = "\033[K";
        $newline = ($newline ? PHP_EOL : '');

        if($maintain_line)
            $newline = "\r";

        return $start . $colored_string . $text . "\033[0m" . $newline;
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