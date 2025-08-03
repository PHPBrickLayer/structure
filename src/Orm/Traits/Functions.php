<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm\Traits;

use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use UUID\UUID;

trait Functions
{
    final public function uuid(): string
    {
        return match (self::uuid_version()){
            default => UUID::uuid7(),
            4 => UUID::uuid4(),
            6 => UUID::uuid6(),
            8 => UUID::uuid8(),
        };
    }

    public static function escape_identifier(string $identifier) : string
    {
        $identifier = trim($identifier, "`\"");

        $iden = explode(".", $identifier, 2);

        if(self::get_driver() == OrmDriver::MYSQL) {
            if(!isset($iden[1]))
                return "`$identifier`";

            return "`$iden[0]`.`$iden[1]`";
        }

        if(!isset($iden[1]))
            return "\"$identifier\"";

        return "\"$iden[0]\".\"$iden[1]\"";
    }

    /**
     * Forms a search query based on a relevance scale
     *
     * @param string $query
     * @param array $columns
     * @param string $select_as by default, it returns its result as relevance, so you can sort as that
     * @param array $filter_list List of filler words than need to be removed
     * @return string
     * @example relevance_query (
     *  "Egypt minister", // query
     *  [
     *      "blogs.title" => [ // column to match
     *          'full' => 10, // score for full match
     *          'keyword' => 8 // score for keywords/partial match
     *      ],
     *      "blogs.tags" => 3, // Score for partial match
     *      "blogs.tags" => [
     *          "keyword" => 3,
     *          "is_json" => true,
     *      ], // Check a json column
     *      ... // you can add as much columns as needed
     * ]);
     */
    final public function relevance_query(
        string $query,
        array $columns,
        string $select_as = "relevance",
        array $filter_list = ["in","it","a","the","of","or","I","you","he","me","us","they","she","to","but","that","this","those","then"]
    ) : string
    {
        $filter_words = function ($query) use ($filter_list) : array {
            $words = [];

            $c = 0;

            foreach (explode(" ", trim($query)) as $key) {
                if (in_array($key, $filter_list))
                    continue;

                $words[] = $key;

                if ($c > 14)
                    break;

                $c++;
            }

            return $words;
        };

        $query = trim($query);

        if (mb_strlen($query) === 0)
            return "";

        $keywords = $filter_words($query);

        $format = function($token, $col, $score, $op = "LIKE", $rule = null): string {
            $insensitive = $rule['insensitive'] ?? true;
            $op = $op ? strtolower($op) : null;

            if($rule['insensitive'] ?? true && $op = "like") {
                if(self::get_driver() == OrmDriver::POSTGRES)
                    $op = "ILIKE";
                else {
                    $col = "LOWER($col)";
                    $token = strtolower($token);
                }
            }

            if($op == "=")
                $op = "='$token'";
            else
                $op = "$op '%$token%'";

            if(isset($rule['is_json'])) {
                $col = $this->json_contains($col, $token, opts: [
                    'return_query' => true
                ]);

                $op = "";
            }

            return "CASE WHEN $col $op THEN $score ELSE 0 END + ";
        };

        $sql_text = "";

        foreach ($columns as $col => $rule) {
            $esc_query = Escape::clean($query, EscapeType::STRIP_TRIM_ESCAPE);

            $sql_text .= "(";

            if (is_array($rule)) {
                $score = $rule['full'] ?? $rule[0] ?? null;
                $sql_text .= $score ? $format($esc_query, $col, $score, $rule['op'] ?? null, rule: $rule) : "";
            }
            else {
                $sql_text .= rtrim($format($esc_query, $col, $rule), "+ ") . ") + ";
                continue;
            }

            if(isset($rule['keyword'])) {
                foreach ($keywords as $key) {
                    if (empty($key))
                        continue;

                    $esc_query = Escape::clean($key, EscapeType::STRIP_TRIM_ESCAPE);
                    $sql_text .= $format($esc_query, $col, $rule['keyword'] ?? $rule[1], rule: $rule);
                }
            }

            $sql_text = rtrim($sql_text, "+ ") . ") + ";
        }

        return "( " . rtrim($sql_text, "+ ") . " ) as $select_as";
    }


    /**
     * Get the syntax to calculate the difference between two dates in the database.
     *
     * ## Note
     * If $date_1 is smaller than $date_2, your result will be negative.
     *
     * ## In plain language
     * By default this function is doing this: `$date_1 - $date_2`
     *
     * @example '2026-02-20', '2025-02-20' == 365
     * @param string $date_1
     * @param string $date_2
     * @param bool $invert_arg use this to invert the syntax to place $date_2 first and $date_1 next
     * @return string
     */
    final public function days_diff(string $date_1, string $date_2, bool $invert_arg = false, bool $cast = true) : string
    {
        if (LayDate::is_valid($date_1)) {
            $date_1 = explode(" ", $date_1)[0]; // Strip the time part, we're only interested in the date
            $date_1 = "'$date_1'";
        }
        else self::escape_identifier($date_1);

        if (LayDate::is_valid($date_2)) {
            $date_2 = explode(" ", $date_2)[0]; // Strip the time part, we're only interested in the date
            $date_2 = "'$date_2'";
        }
        else self::escape_identifier($date_2);

        $x1 = $date_1;
        $x2 = $date_2;

        if($invert_arg) {
            $x1 = $date_2;
            $x2 = $date_1;
        }

        $driver = self::get_driver();

        if($driver == OrmDriver::POSTGRES) {
            $x1 .= "::date";
            $x2 .= "::date";

            return $cast ? "(CAST(($x1 - $x2) AS INTEGER))" : "(($x1 - $x2) AS INTEGER)";
        }

        if(OrmDriver::is_sqlite($driver))
            return $cast ? "(CAST(julianday(date($x1)) - julianday(date($x2)) AS INTEGER))" : "(julianday(date($x1)) - julianday(date($x2)) AS INTEGER)";


        return "(DATEDIFF($x1, $x2))";
    }




    /**
     * @deprecated use json_contains
     * @uses \BrickLayer\Lay\Orm\SQL::json_contains()
     * @param string $column
     * @param mixed $value
     * @return string
     */
    final public function contains(string $column, mixed $value) : string
    {
        return "JSON_CONTAINS($column, '\"$value\"', '$')";
    }

    /**
     * @deprecated Find alternatives
     * @param string $column
     * @param mixed $key
     * @param bool $unquote
     * @return string
     */
    final public function extract(string $column, mixed $key, bool $unquote = true) : string
    {
        $x = "JSON_EXTRACT($column, '$.$key')";

        if($unquote)
            return "JSON_UNQUOTE($x)";

        return $x;
    }
}