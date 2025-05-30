<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm;

use BrickLayer\Lay\Core\CoreException;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;
use BrickLayer\Lay\Orm\Enums\OrmExecStatus;
use BrickLayer\Lay\Orm\Enums\OrmQueryType;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use BrickLayer\Lay\Orm\Traits\Config;
use BrickLayer\Lay\Orm\Traits\Functions;
use BrickLayer\Lay\Orm\Traits\SelectorOOP;
use BrickLayer\Lay\Orm\Traits\SelectorOOPCrud;
use BrickLayer\Lay\Orm\Traits\TransactionHandler;

/**
 * Simple Query Language
 **/
final class SQL
{
    use IsSingleton;
    use Config;
    use SelectorOOP;
    use SelectorOOPCrud;
    use TransactionHandler;
    use Functions;

    /**
     * @var array<string, mixed> $query_info
     * @return array{
     *     status: OrmExecStatus,
     *     has_data: bool,
     *     data: mixed,
     *     has_error: bool,
     *     rows: int,
     * }
     */
    public array $query_info;


    /**
     * @throws \Exception
     */
    protected static function exception(string $title, string $message, array $opts = [], $exception = null) : void
    {
        CoreException::new()->use_exception(
            "OrmExp::" . $title,
            $message,
            opts: $opts,
            exception: $exception
        );
    }

    /**
     * Query Engine
     * @param string $query
     * @param array{
     *  debug: bool,
     *
     *  // instructs SQL to catch any error and not display it
     *  catch: bool, // default = false
     *
     *  can_be_null: bool, // default = true
     *  can_be_false?: bool, // default = true
     *  loop?: bool, // default = false
     *  except?: string,
     *  return_as?: OrmReturnType, // RESULT
     *  query_type?: OrmQueryType, // default = auto discovered
     *  fetch_as?: OrmReturnType, // default = BOTH
     *  result_on_insert?: bool, // default = false
     * } $option Adjust the function to fit your use case;
     * @return mixed
     */
    final public function query(string $query, array $option = []) : mixed
    {
        if (!isset(self::$link))
            self::exception(
                "ConnErr",
                "No connection detected: <h5>Connection might be closed!</h5>",
            );

        $option = LayArray::flatten($option);
        $debug = $option['debug'] ?? false;
        $catch_error = $option['catch'] ?? false;
        $return_as = $option['return_as'] ?? OrmReturnType::RESULT; // exec|result
        $can_be_null = $option['can_be_null'] ?? true;
        $can_be_false = $option['can_be_false'] ?? true;
        $result_on_insert = $option['result_on_insert'] ?? false;
        $query_type = $option['query_type'] ?? "";

        if($result_on_insert){
            $query_type = OrmQueryType::SELECT;
            $option['fetch_as'] = OrmReturnType::ASSOC;
        }

        if (empty($query_type)) {
            $qr = explode(" ", trim($query), 2);
            $query_type = strtoupper(substr($qr[1], 0, 5));
            $query_type = $query_type == OrmQueryType::COUNT->name ? $query_type : strtoupper($qr[0]);
            $query_type = LayArray::any(OrmQueryType::cases(), fn($v) => $v->name === $query_type)['value'] ?? $query_type;
        }

        if ($debug)
            self::exception(
                "QueryReview",
                "<pre style='color: #dea303 !important'>$query</pre>
                    <div style='margin: 10px 0'>DB: <span style='color: #00A261'>" . self::$db_name . "</span></div>
                    <div style='margin: 10px 0'>Driver: <span style='color: #00A261'>" . self::$active_driver->value . "</span></div>",
                [ "type" => "view" ],
            );

        // execute query
        $exec = false;
        $has_error = false;

        try {
            $exec = self::$link->query($query);
        } catch (\Throwable $e) {
            $has_error = true;

            $query_type = is_string($query_type) ? $query_type : $query_type->name;
            $error = self::$link->error ?? null;

            if (method_exists(self::$link, "lastErrorMsg"))
                $error = self::$link->lastErrorMsg() ?? null;

            $title = "QueryExec";
            $message = "<b style='color: #008dc5'>" . ($error ?? $e->getMessage()) . "</b> 
            <div style='color: #fff0b3; margin-top: 5px'>$query</div> 
            <div style='margin: 10px 0'>Statement: $query_type</div>
            <div style='margin: 10px 0'>DB: <span style='color: #00A261'>" . self::$db_name . "</span></div>
            <div style='margin: 10px 0'>Driver: <span style='color: #00A261'>" . self::$active_driver->value . "</span></div>
            ";

            if ($catch_error === false)
                self::exception($title, $message, [
                    "show_e_msg" => false
                ] , $e);
            else
                LayException::log($message, $e, $title);
        }

        // init query info structure
        $this->query_info = [
            "status" => $has_error ? OrmExecStatus::FAIL : OrmExecStatus::SUCCESS,
            "has_data" => !$has_error,
            "data" => $exec,
            "has_error" => $has_error,
            "error_caught" => $catch_error,
            "rows" => 0
        ];

        if($return_as == OrmReturnType::EXECUTION)
            return $exec;

        if ($query_type == OrmQueryType::COUNT) {
            return $this->query_info['data'] = (int) (self::$link->fetch_one($exec,OrmReturnType::NUM)[0] ?? null);
        }

        // prevent select queries from returning bool
        if (in_array($query_type, [OrmQueryType::SELECT, OrmQueryType::LAST_INSERTED]))
            $can_be_false = false;

        $affected_rows = self::$link->affected_rows($exec);

        $this->query_info['rows'] = $affected_rows;

        // Record no row if there isn't any
        if ($affected_rows == 0) {
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
            );

            if(!$loop)
                $exec = $exec->getReturn();

            if (!$can_be_null)
                $exec = $exec ?? [];

            $this->query_info['data'] = $exec;

            return $exec;
        }

        return (bool) $affected_rows;
    }
}