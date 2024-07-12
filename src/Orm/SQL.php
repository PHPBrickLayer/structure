<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm;

use BrickLayer\Lay\Core\CoreException;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\Enums\OrmQueryType;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use BrickLayer\Lay\Orm\Traits\Controller;
use BrickLayer\Lay\Orm\Enums\OrmExecStatus;
use Exception;
use Generator;
use JetBrains\PhpStorm\ArrayShape;
use mysqli;
use mysqli_result;
use SQLite3Result;

/**
 * Simple Query Language
 **/
class SQL
{
    use IsSingleton;
    use Config;
    use Controller;

    public array $query_info;
    private static OrmDriver $active_driver;
    private static string $db_name;

    public static function get_driver() : OrmDriver
    {
        if(isset(self::$active_driver))
            return self::$active_driver;

        if(!isset($_ENV['DB_DRIVER']))
            self::exception(
                "NoDBDriverFound",
                "No Database driver was found. It's possible that you called this method before initializing the ORM"
            );

        if($driver = self::test_driver($_ENV['DB_DRIVER']))
            return $driver;

        self::exception(
            "UnidentifiedDriver",
            "An unidentified database driver was found: " . $_ENV['DB_DRIVER']
        );
    }

    public static function test_driver(string $string) : ?OrmDriver
    {
        return match ($string) {
            default => null,
            "mysql" => OrmDriver::MYSQL,
            "sqlite" => OrmDriver::SQLITE
        };
    }

    /**
     * @param $connection mysqli|array|null|string The link to a mysqli connection or an array of [host, user, password, db]
     * @param bool $persist_connection
     * When nothing is passed, the class assumes dev isn't doing any db operation
     */
    public static function init(
        #[ArrayShape([
            "host" => 'string',
            "user" => 'string',
            "password" => 'string',
            "db" => 'string',
            "port" => 'string',
            "socket" => 'string',
            "silent" => 'bool',
            "ssl" => 'array [
                certificate => string, 
                ca_certificate => string,
                ca_path => string, 
                cipher_algos => string, 
                flag => int,
            ]',
        ])] mysqli|array|null|string $connection = null,
        OrmDriver $driver = OrmDriver::MYSQL,
        bool $persist_connection = true
    ): self
    {
        if($connection === null){
            $driver = OrmDriver::tryFrom($_ENV['DB_DRIVER'] ?? '');

            if($driver === null)
                self::exception("InvalidOrmDriver", "An invalid db driver was received: [" . @$_ENV['DB_DRIVER'] . "]. Please specify the `DB_DRIVER`. Valid keys includes any of the following: [" . OrmDriver::stringify() . "]");

            $connection = match ($driver) {
                default => [
                    "host" => $_ENV['DB_HOST'],
                    "user" => $_ENV['DB_USERNAME'],
                    "password" => $_ENV['DB_PASSWORD'],
                    "db" => $_ENV['DB_NAME'],
                    "port" => $_ENV['DB_PORT'] ?? NULL,
                    "socket" => $_ENV['DB_SOCKET'] ?? NULL,
                    "silent" => $_ENV['DB_ALLOW_STARTUP_ERROR'] ?? false,
                    "ssl" => [
                        "key" => $_ENV['DB_SSL_KEY'] ?? null,
                        "certificate" => $_ENV['DB_SSL_CERTIFICATE'] ?? null,
                        "ca_certificate" => $_ENV['DB_SSL_CA_CERTIFICATE'] ?? null,
                        "ca_path" => $_ENV['DB_SSL_CA_PATH'] ?? null,
                        "cipher_algos" => $_ENV['DB_SSL_CIPHER_ALGOS'] ?? null,
                        "flag" => $_ENV['DB_SSL_FLAG'] ?? 0
                    ],
                ],
                OrmDriver::SQLITE => $_ENV['SQLITE_DB']
            };
        }

        self::$active_driver = $driver;
        self::new()->set_db($connection, $persist_connection);
        return self::new();
    }

    public function switch_db(string $name): bool
    {
        $name = self::escape_string($name);
        return self::$link->select_db($name);
    }

    public static function exception(string $title, string $message, array $opts = [], $exception = null) : void
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
     * @return int|bool|array|mysqli_result|SQLite3Result|Generator|null
     */
    final public function query(
        string $query,
        #[ArrayShape([
            "debug" => "bool",
            "catch" => "bool",
            "can_be_null" => "bool",
            "can_be_false" => "bool",
            "loop" => "bool",
            "except" => "string",
            "return_as" => "BrickLayer\\Lay\\Orm\\Enums\\OrmReturnType",
            "query_type" => "BrickLayer\\Lay\\Orm\\Enums\\OrmQueryType",
            "fetch_as" => "BrickLayer\\Lay\\Orm\\Enums\\OrmReturnType",
        ])] array $option = []
    ): int|bool|array|null|mysqli_result|SQLite3Result|Generator
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
        $query_type = $option['query_type'] ?? "";

        if (empty($query_type)) {
            $qr = explode(" ", trim($query), 2);
            $query_type = strtoupper(substr($qr[1], 0, 5));
            $query_type = $query_type == OrmQueryType::COUNT->name ? $query_type : strtoupper($qr[0]);
            $query_type = LayArray::some(OrmQueryType::cases(), fn($v) => $v->name === $query_type)[0] ?? $query_type;
        }

        if ($debug)
            self::exception(
                "QueryReview",
                "<pre style='color: #dea303 !important'>$query</pre>
                    <div style='margin: 10px 0'>DB: <span style='color: #00A261'>" . self::$db_name . "</span></div>
                    <div style='margin: 10px 0'>Driver: <span style='color: #00A261'>" . self::$active_driver->value . "</span></div>",
                [ "type" => "view" ]
            );

        // execute query
        $exec = false;
        $has_error = false;

        try {
            $exec = self::$link->query($query);
        } catch (Exception|\mysqli_sql_exception $e) {
            $has_error = true;
            if ($exec === false && $catch_error === false) {
                $query_type = is_string($query_type) ? $query_type : $query_type->name;
                $error = self::$link->error ?: null;

                if(method_exists(self::$link, "lastErrorMsg"))
                    $error = self::$link->lastErrorMsg() ?? null;

                self::exception(
                    "QueryExec",
                    "<b style='color: #008dc5'>" . ($error ?? $e->getMessage()) . "</b> 
                    <div style='color: #fff0b3; margin-top: 5px'>$query</div> 
                    <div style='margin: 10px 0'>Statement: $query_type</div>
                    <div style='margin: 10px 0'>DB: <span style='color: #00A261'>" . self::$db_name . "</span></div>
                    <div style='margin: 10px 0'>Driver: <span style='color: #00A261'>" . self::$active_driver->value . "</span></div>
                    ",
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

        if($return_as == OrmReturnType::EXECUTION)
            return $exec;

        if ($query_type == OrmQueryType::COUNT) {
            if(self::$active_driver == OrmDriver::SQLITE)
                return $this->query_info['data'] = (int)$exec->fetchArray(SQLITE3_NUM);

            return $this->query_info['data'] = (int)$exec->fetch_row()[0];
        }

        // prevent select queries from returning bool
        if (in_array($query_type, [OrmQueryType::SELECT, OrmQueryType::LAST_INSERTED]))
            $can_be_false = false;

        // Get affected rows count
        if(self::$active_driver == OrmDriver::SQLITE && ($query_type == OrmQueryType::SELECT || $query_type == OrmQueryType::SELECT->name)) {
            $x = explode("FROM", $query,2)[1];
            $affected_rows = self::$link->querySingle("SELECT COUNT (*) FROM" . rtrim($x, ";") . ";");
        }

        $affected_rows = $affected_rows ?? self::$link->affected_rows ?? self::$link->changes();

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