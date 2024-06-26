<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm;

use BrickLayer\Lay\Core\CoreException;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Orm\Enums\OrmQueryType;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use BrickLayer\Lay\Orm\Traits\Controller;
use BrickLayer\Lay\Orm\Enums\OrmExecStatus;
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

    public function exception(string $title, string $message, array $opts = [], $exception = null) : void
    {
        CoreException::new()->use_exception(
            "OrmExp_" . $title,
            $message,
            opts: $opts,
            exception: $exception
        );
    }

    /**
     * Query Engine
     * @param string $query
     * @param array $option Adjust the function to fit your use case;
     * @return int|bool|array|mysqli_result|\Generator|null
     */
    final public function query(string $query, array $option = []): int|bool|array|null|mysqli_result|\Generator
    {
        if (!isset(self::$link))
            $this->exception(
                "ConnErr",
                "No connection detected: <h5>Connection might be closed!</h5>",
            );

        $option = LayArray::flatten($option);
        $debug = $option['debug'] ?? 0;
        $catch_error = $option['catch'] ?? 0;
        $return_as = $option['return_as'] ?? OrmReturnType::RESULT; // exec|result
        $can_be_null = $option['can_be_null'] ?? true;
        $can_be_false = $option['can_be_false'] ?? true;
        $query_type = $option['query_type'] ?? "";

        if (empty($query_type)) {
            $qr = explode(" ", trim($query), 2);
            $query_type = strtoupper(substr($qr[1], 0, 5));
            $query_type = $query_type == OrmQueryType::COUNT->name ? $query_type : strtoupper($qr[0]);
            $query_type = LayArray::some(OrmQueryType::cases(), fn($v) => $v->name === $query_type)[0] ?? $query_type;
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
        } catch (Exception $e) {
            $has_error = true;
            if ($exec === false && $catch_error === 0) {
                $query_type = is_string($query_type) ? $query_type : $query_type->name;

                $this->exception(
                    "QueryExec",
                    "<b style='color: #008dc5'>" . mysqli_error($this->get_link()) . "</b> 
                    <div style='color: #fff0b3; margin-top: 5px'>$query</div> 
                    <div style='margin: 10px 0'>Statement: $query_type</div>",
                    exception: $e
                );
            }
        }

        // init query info structure
        $this->query_info = [
            "status" => OrmExecStatus::SUCCESS,
            "has_data" => true,
            "data" => $exec,
            "has_error" => $has_error
        ];

        if($return_as == OrmReturnType::EXEC)
            return $exec;

        if ($query_type == OrmQueryType::COUNT)
            return $this->query_info['data'] = (int)mysqli_fetch_row($exec)[0];

        // prevent select queries from returning bool
        if (in_array($query_type, [OrmQueryType::SELECT, OrmQueryType::LAST_INSERTED]))
            $can_be_false = false;

        // Sort out result
        if (mysqli_affected_rows(self::$link) == 0) {
            $this->query_info['has_data'] = false;

            if ($query_type == OrmQueryType::SELECT || $query_type == OrmQueryType::LAST_INSERTED)
                return $this->query_info['data'] = !$can_be_null ? [] : null;

            if ($can_be_false)
                $this->query_info['data'] = false;

            return $this->query_info['data'];
        }

        if (!$exec) {
            $this->query_info = [
                "status" => OrmExecStatus::FAIL,
                "has_data" => false,
                "has_error" => $has_error,
            ];

            if ($can_be_false)
                return $this->query_info['data'] = false;

            return $this->query_info['data'] = !$can_be_null ? [] : null;
        }

        if ($query_type == OrmQueryType::SELECT || $query_type == OrmQueryType::LAST_INSERTED) {
            $loop = (bool) ($option['loop'] ?? false);

            $exec = StoreResult::store(
                $exec,
                $loop,
                $option['fetch_as'] ?? OrmReturnType::BOTH,
                $option['except'] ?? "",
                $option['fun'] ?? null,
                $option['result_dimension'] ?? 2
            );

            if(!$loop)
                $exec = $exec->getReturn();

            if (!$can_be_null)
                $exec = $exec ?? [];

            $this->query_info['data'] = $exec;
        }

        return $exec;
    }
}