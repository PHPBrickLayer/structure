<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm;

use BrickLayer\Lay\Core\CoreException;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Orm\Traits\Controller;
use Exception;
use mysqli;
use mysqli_result;

/**
 * Simple Query Language
 **/
class SQL
{
    use IsSingleton;
    use Config;
    use Controller;

    public array $query_info;

    /**
     * @param $connection mysqli|array|null The link to a mysqli connection or an array of [host, user, password, db]
     * When nothing is passed, the class assumes dev isn't doing any db operation
     */
    public static function init(mysqli|array|null $connection = null): self
    {
        self::_init($connection);
        return self::instance();
    }

    public function switch_db(string $name): bool
    {
        $name = mysqli_real_escape_string(self::$link, $name);
        return mysqli_select_db(self::$link, $name);
    }

    public function exception(string $title, string $message, array $opts = []) : void
    {
        CoreException::new()->use_exception(
            "OrmExp_" . $title,
            $message,
            opts: $opts
        );
    }

    /**
     * Query Engine
     * @param string $query
     * @param array $option Adjust the function to fit your use case;
     * @return int|bool|array|null|mysqli_result
     * @throws Exception
     */
    final public function query(string $query, array $option = []): int|bool|array|null|mysqli_result
    {
        if (!isset(self::$link))
            $this->exception(
                "ConnErr",
                "No connection detected: <h5>Connection might be closed!</h5>",
            );

        $option = $this->array_flatten($option);
        $debug = $option['debug'] ?? 0;
        $catch_error = $option['catch'] ?? 0;
        $return_as = $option['return_as'] ?? "result"; // exec|result
        $can_be_null = $option['can_be_null'] ?? true;
        $can_be_false = $option['can_be_false'] ?? true;
        $query_type = strtoupper($option['query_type'] ?? "");

        if (empty($query_type)) {
            $qr = explode(" ", trim($query), 2);
            $query_type = strtoupper(substr($qr[1], 0, 5));
            $query_type = $query_type == "COUNT" ? $query_type : strtoupper($qr[0]);
        }

        if ($debug)
            $this->exception(
                "QueryReview",
                "<pre style='color: #dea303 !important'>$query</pre>",
                [ "type" => "view" ]
            );

        // execute query
        $exec = false;
        $has_error = false;
        try {
            $exec = mysqli_query(self::$link, $query);
        } catch (Exception) {
            $has_error = true;
            if ($exec === false && $catch_error === 0)
                $this->exception(
                    "QueryExec",
                    "<b style='color: #008dc5'>" . mysqli_error($this->get_link()) . "</b> 
                    <div style='color: #fff0b3; margin-top: 5px'>$query</div> 
                    <div style='margin: 10px 0'>Statement: $query_type</div>"
                );
        }

        // init query info structure
        $this->query_info = [
            "status" => QueryStatus::success,
            "has_data" => true,
            "data" => $exec,
            "has_error" => $has_error
        ];

        if ($query_type == "COUNT")
            return $this->query_info['data'] = (int)mysqli_fetch_row($exec)[0];

        // prevent select queries from returning bool
        if (in_array($query_type, ["SELECT", "LAST_INSERT"]))
            $can_be_false = false;

        // Sort out result
        if (mysqli_affected_rows(self::$link) == 0) {
            $this->query_info['has_data'] = false;

            if ($query_type == "SELECT" || $query_type == "LAST_INSERTED")
                return $this->query_info['data'] = !$can_be_null ? [] : null;

            if ($can_be_false)
                $this->query_info['data'] = false;

            return $this->query_info['data'];
        }

        if (!$exec) {
            $this->query_info = [
                "status" => QueryStatus::fail,
                "has_data" => false,
                "has_error" => $has_error,
            ];

            if ($can_be_false)
                return $this->query_info['data'] = false;

            return $this->query_info['data'] = !$can_be_null ? [] : null;
        }

        if (($query_type == "SELECT" || $query_type == "LAST_INSERTED") && $return_as == "result") {
            $exec = StoreResult::store(
                $exec,
                $option['loop'] ?? null,
                $option['fetch_as'] ?? null,
                $option['except'] ?? "",
                $option['fun'] ?? null,
                $option['result_dimension'] ?? 2
            );

            if (!$can_be_null)
                $exec = $exec ?? [];

            $this->query_info['data'] = $exec;
        }

        return $exec;
    }

    /**
     * Flattens multiple dimensions of an array to a single dimension array.
     * The latest values will replace arrays with the same keys
     * @param array $array
     * @return array
     */
    final public function array_flatten(array $array): array
    {
        $arr = $array;
        if (count(array_filter($array, "is_array")) > 0) {
            $arr = [];
            foreach ($array as $i => $v) {
                if (is_array($v)) {
                    array_walk($v, function ($entry, $key) use (&$arr, &$v) {
                        if (is_array($entry))
                            $arr = array_merge($arr, $entry);
                        elseif (!is_int($key))
                            $arr[$key] = $entry;
                        else
                            $arr[] = $entry;
                    });
                } else
                    $arr[$i] = $v;
            }
        }
        return $arr;
    }
}