<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs;

use DateInterval;
use DateTime;

class LayDate {
    public int|string $result;

    public function minus(string|int $date1, string|int $date2 = "now") : self
    {
        $this->result = self::diff($date1, $date2);
        return $this;
    }

    public function minute() : int
    {
        return self::sec_2_min($this->result);
    }
    /**
     * @param string|int|null $datetime values accepted by `strtotime` or integer equivalent of a datetime.
     * If you pass null to it, it will return null, except you explicitly state that it shouldn't with the `return_null` arg
     *
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
     * @return string|int|null
     */
    public static function date(string|int|null $datetime = "now", string $format = "Y-m-d H:i:s", int $format_index = -1, bool $figure = false, bool $return_null = true) : string|int|null
    {
        if($datetime === null && $return_null)
            return null;

        $format = match ($format_index) {
            0 => "Y-m-d",
            1 => "H:i:s",
            2 => "D, d M Y h:i a",
            3 => "D, d M Y H:i:s T",
            default => $format,
        };

        if(is_numeric($datetime)) {
            $datetime = (int) $datetime;

            if($figure) return $datetime;

            return date($format, $datetime);
        }

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
        return self::date(figure: true);
    }

    public static function unix(string|int|null $datetime) : int
    {
        return self::date($datetime, figure: true);
    }

    /**
     * Convert words to their seconds value.
     * @param string $date_word Any valid `strtotime` word is valid here
     * @return int
     * @see strtotime()
     * @example LayDate::in_seconds('1 day'); // returns 86400;
     */
    public static function in_seconds(string $date_word) : int
    {
        return self::diff($date_word, "now", true);
    }

    /**
     * Converts seconds to minutes
     * @param int $sec
     * @return int
     */
    public static function sec_2_min(int $sec) : int
    {
        return (int) ceil($sec/60);
    }

    public static function month_total_weeks(string|int|null $date, bool $include_floating_week = true) : int
    {
        $start = new DateTime(self::date($date,'Y-m'));

        $days = (clone $start)->add(new DateInterval('P1M'))->diff($start)->days;

        $offset = intval(self::date($date, 'N')) - 1;

        $fn = $include_floating_week ? "ceil" : "floor";

        return (int) $fn(($days + $offset) / 7);
    }

    /**
     * @param int $year
     * @return array{
     *     total: int,
     *     month: array<int, array>,
     * }
     */
    public static function year_total_weeks(int $year) : array
    {
        $start = self::date("$year-01-01", 'Y-');
        $all_weeks = [
            "total" => 0,
            "month" => [],
        ];

        for ($i =1; $i <= 12; $i++) {
            $x = self::month_total_weeks($start . $i . "-01", false);
            $all_weeks["month"][] = $x;
            $all_weeks["total"] += $x;
        }

        return $all_weeks;
    }

    public static function week_of_month(string|int|null $date) : int
    {
        $first_day_of_month = self::date($date, "Y-m-01", figure: true);

        //Apply above formula.
        return self::week_of_year($date) - self::week_of_year($first_day_of_month) + 1;
    }

    public static function week_of_year(string|int|null $date) : int
    {
        $week_of_year = intval(self::date($date, "W"));

        // It's the last week of the previous year.
        if (self::date( $date, 'n') == "1" && $week_of_year > 51)
            return 0;

        // It's the first week of the next year.
        if (self::date($date, 'n') == "12" && $week_of_year == 1)
            return 53;

        // It's a "normal" week.
        return $week_of_year;
    }

    public static function elapsed(string $current_time, int $depth = 1, string $format = "M d, o", bool $append_ago = true): string|int
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

    /**
     * ## Subtract two dates and return the value in seconds.
     * This function subtracts `$date2` - `$date1`; $date2 = now by default
     * @param string|int $date1
     * @param string|int $date2
     * @param bool $absolute when true, it makes the result unsigned or always positive
     * @return int
     */
    public static function diff(string|int $date1, string|int $date2 = "now", bool $absolute = false) : int
    {
        $date1 = self::unix($date1);
        $date2 = self::unix($date2);

        if($absolute)
            return abs($date2 - $date1);

        return $date2 - $date1;
    }

    /**
     * Check if $date2 is greater than $date1. $date2 is "now" by default
     * @param string|int $date1
     * @param string|int $date2
     * @param bool $invert invert the function to check if $date2 is greater than $date1
     * @return bool
     */
    public static function greater(string|int $date1, string|int $date2 = "now", bool $invert = false) : bool
    {
        if($invert)
            return self::date($date2, figure: true) < self::date($date1, figure: true);

        return self::date($date2, figure: true) > self::date($date1, figure: true);
    }

    public static function expired(string|int $expiry_date) : bool
    {
        return self::greater($expiry_date);
    }

    /**
     * Checks if a date literal is a valid database date format
     * @param string $date_literal
     * @return bool
     */
    public static function is_valid(string $date_literal) : bool
    {
        $pattern = "/^(\d{4}-\d{2}-\d{2})( \d{2}:\d{2}:\d{2}(\.\d{3})?)?$/";
        return (bool) preg_match($pattern, $date_literal);
    }

}