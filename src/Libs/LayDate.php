<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs;

use DateTime;
use BrickLayer\Lay\Core\Traits\IsSingleton;

class LayDate {
    use IsSingleton;

    /**
     * @param string|int|null $datetime values accepted by `strtotime` or integer equivalent of a datetime
     * @link https://php.net/manual/en/function.idate.php
     * @param string $format <p>
     *  <table>
     *  The following characters are recognized in the
     *  format parameter string
     *  <tr valign="top">
     *  <td>format character</td>
     *  <td>Description</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>B</td>
     *  <td>Swatch Beat/Internet Time</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>d</td>
     *  <td>Day of the month</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>h</td>
     *  <td>Hour (12 hour format)</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>H</td>
     *  <td>Hour (24 hour format)</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>i</td>
     *  <td>Minutes</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>I (uppercase i)</td>
     *  <td>returns 1 if DST is activated,
     *  0 otherwise</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>L (uppercase l)</td>
     *  <td>returns 1 for leap year,
     *  0 otherwise</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>m</td>
     *  <td>Month number</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>s</td>
     *  <td>Seconds</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>t</td>
     *  <td>Days in current month</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>U</td>
     *  <td>Seconds since the Unix Epoch - January 1 1970 00:00:00 UTC -
     *  this is the same as time</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>w</td>
     *  <td>Day of the week (0 on Sunday)</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>W</td>
     *  <td>ISO-8601 week number of year, weeks starting on
     *  Monday</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>y</td>
     *  <td>Year (1 or 2 digits - check note below)</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>Y</td>
     *  <td>Year (4 digits)</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>z</td>
     *  <td>Day of the year</td>
     *  </tr>
     *  <tr valign="top">
     *  <td>Z</td>
     *  <td>Timezone offset in seconds</td>
     *  </tr>
     *  </table>
     *  </p>
     * @param int $format_index 0 = date; 1 = time; 2 = appearance: [Ddd dd, Mmm YYYY | hh:mm a] - format: [D d, M Y | h:i a]
     * @param bool $figure to return the integer equivalent of the give datetime
     * @return string|int
     */
    public static function date(string|int|null $datetime = null, string $format = "Y-m-d H:i:s", int $format_index = -1, bool $figure = false) : string|int {

        $format = match ($format_index) {
            0 => "Y-m-d",
            1 => "H:i:s",
            2 => "D d, M Y | h:i a",
            3 => "D, d M Y H:i:s T",
            default => $format,
        };

        if(is_int($datetime))
            return date($format, $datetime);

        $datetime = $datetime ?: date($format);

        $strtotime = strtotime($datetime);

        if($figure && $strtotime)
            return $strtotime;

        if(!$strtotime)
            return $datetime;

        return date($format, $strtotime);
    }

    public static function now() : int
    {
        return self::date("", figure: true);
    }

    public static function week_of_month($date) : int
    {
        $first_day_of_month = strtotime(date("Y-m-01", $date));

        //Apply above formula.
        return self::week_of_year($date) - self::week_of_year($first_day_of_month) + 1;
    }

    public static function week_of_year($date) : int
    {
        $week_of_year = intval(date("W", $date));

        // It's the last week of the previous year.
        if (date('n', $date) == "1" && $week_of_year > 51)
            return 0;

        // It's the first week of the next year.
        if (date('n', $date) == "12" && $week_of_year == 1)
            return 53;

        // It's a "normal" week.
        return $week_of_year;
    }

    public static function elapsed(string $current_time, int $depth = 1, string $format = "M d, o", bool $append_ago = true): string
    {
        $now = new DateTime;
        $ago = new DateTime($current_time);
        $diff = $now->diff($ago);

        $week = floor($diff->d / 7);
        $diff->d -= $week * 7;

        $string = [
            'y' => 'year',
            'm' => 'month',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        ];

        if($week > 0)
            return self::date($current_time, $format);

        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
                continue;
            }

            unset($string[$k]);
        }

        $string = array_slice($string, 0, $depth);
        return $string ? implode(', ', $string) . ($append_ago ? ' ago' : '') : 'just now';
    }

    public static function diff(string|int $datetime_earlier, string|int $datetime_latest = "now", bool $absolute = false) : int
    {
        $datetime_earlier = self::date($datetime_earlier, figure: true);
        $datetime_latest = self::date($datetime_latest, figure: true);

        if($absolute)
            return abs($datetime_latest - $datetime_earlier);

        return $datetime_latest - $datetime_earlier;
    }

    public static function greater(string|int $datetime_earlier, string|int $datetime_latest = "now", bool $invert = false) : bool
    {
        if($invert)
            return self::date($datetime_latest, figure: true) < self::date($datetime_earlier, figure: true);

        return self::date($datetime_latest, figure: true) > self::date($datetime_earlier, figure: true);
    }

}