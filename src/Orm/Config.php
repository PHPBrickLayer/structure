<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Orm;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\Enums\OrmQueryType;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use JetBrains\PhpStorm\ArrayShape;
use mysqli;
use SQLite3;

trait Config{
    private const SESSION_KEY = "__LAY_SQL__";
    private static mysqli|SQLite3 $link;
    private static string $CHARSET = "utf8mb4";
    private static string $DB_FILE;
    private static bool $persist_connection = true;
    private static array $PINGED_DB_ARGS;
    private static array $DB_ARGS = [
        "host" => null,
        "user" => null,
        "password" => null,
        "db" => null,
        "port" => null,
        "socket" => null,
        "silent" => false,
        "ssl" => [
            "key" => null,
            "certificate" => null,
            "ca_certificate" => null,
            "ca_path" => null,
            "cipher_algos" => null,
            "flag" => 0
        ],
    ];
    private static bool $connected = false;

    private static function cache_connection($link) : void
    {
        if(!isset(self::$active_driver))
            return;

        $_SESSION[self::SESSION_KEY][self::$active_driver->value] = $link;
    }

    private static function get_cached_connection() : mysqli|SQLite3|null
    {
        return $_SESSION[self::SESSION_KEY][self::$active_driver->value] ?? null;
    }
    /**
     * Connect Controller Manually From Here
     * @return mysqli|null
     **/
    private function connect() : ?mysqli {
        extract(self::$DB_ARGS);
        $charset = $charset ?? self::$CHARSET;
        $port ??= 3306;
        $port = (int) $port;
        $socket = $socket ?? null;

        if(self::is_connected()) {
            $cxn = self::$PINGED_DB_ARGS;
            self::$db_name = $cxn['db'];

            if($cxn['host'] == $host and $cxn['user'] == $user and $cxn['db'] == $db)
                return $this->get_link();
        }


        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = mysqli_init();

        if(!$mysqli)
            self::exception(
                "ConnErr",
                "<div style='color: #e00; font-weight: bold; margin: 5px 1px;'>Cannot initialize connection</div>"
            );

//        $mysqli->options(MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = 0');
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);

        if (!empty(@$ssl['certificate']) || !empty(@$ssl['ca_certificate'])) {
            $mysqli->ssl_set(
                @$ssl['key'],
                @$ssl['certificate'],
                @$ssl['ca_certificate'],
                @$ssl['ca_path'],
                @$ssl['cipher_algos']
            );
        }

        $connected = false;

        try {
            $connected = @$mysqli->real_connect((self::$persist_connection ? "p:" : "" ). $host, $user, $password, $db, $port, $socket, (int)@$ssl['flag']);
        }
        catch (\Exception $e) {}

        if($connected) {
            self::$connected = true;
            $mysqli->set_charset($charset);
            $this->set_link($mysqli);
            return $this->get_link();
        }

        if (filter_var($silent, FILTER_VALIDATE_BOOL))
            return null;

        self::exception(
            "ConnErr",
            "<div style='color: #e00; font-weight: bold; margin: 5px 1px;'>" . mysqli_connect_error() . "</div>"
        );
    }

    private function connect_sqlite(string $db_file) : SQLite3
    {
        if(self::$active_driver !== OrmDriver::SQLITE)
            self::exception(
                "MismatchedDriver",
                "Database connection argument is string [$db_file], but database driver is [" .
                self::$active_driver->value . "]. Please change db driver to: " . OrmDriver::SQLITE->value
            );

        try {
            $db = LayConfig::server_data()->db;

            if(!is_dir($db)) {
                umask(0);
                mkdir($db, 0755, true);
            }

            self::$db_name = str_replace("/", DIRECTORY_SEPARATOR, $db_file);

            $file = $db . self::$db_name;
            self::$link = new SQLite3($file);
            self::$link->enableExceptions(true);
            self::$connected = true;

        } catch (\Exception $e){
            self::exception(
                "SQLiteConnectionError",
                "Error initializing SQLite DB [$file]: " . $e->getMessage(),
                exception: $e
            );
        }

        return self::$link;
    }

    /**
     * Connect Controller Using Existing Link
     * @param mysqli $link
     * @return mysqli
     */
    private function plug(mysqli $link) : mysqli {
        $cxn_old = $this->ping(true);

        if(empty($cxn_old['host']) || empty($cxn_old['user']) || empty($cxn_old['db']))
            $this->set_link($link);
        else {
            $cxn_new = $this->ping(true, $link);
            if (!($cxn_old['host'] == $cxn_new['host'] and $cxn_old['user'] == $cxn_new['user'] and $cxn_old['db'] == $cxn_new['db']))
                $this->set_link($link);
        }
        return $this->get_link();
    }

    #[ArrayShape(['host' => 'string', 'user' => 'string', 'db' => 'string', 'connected' => 'bool'])]
    /**
     * Check Database Connection
     * @param bool $ignore_msg false by default to echo connection info
     * @param mysqli|null $link link to database connection
     * @param bool $ignore_no_conn false by default to silence no connection error
     * @return array containing [host,user,db]
     **/
    public function ping(bool $ignore_msg = false, ?mysqli $link = null, bool $ignore_no_conn = false) : array {
        $link = $link ?? $this->get_link() ?? null;

        if(!$link || !isset($link->host_info))
            return ["host" => "", "user" => "", "db" => "", "connected" => false];

        if(!$link->ping() && !$ignore_no_conn)
            self::exception(
                "ConnErr",
                "No connection detected: <h5 style='color: #008dc5'>Connection might be closed:</h5>",
            );

        extract(
            $this->query(
                "SELECT SUBSTRING_INDEX(host, ':', 1) AS host_short, USER AS users, db FROM information_schema.processlist",
                [ "fetch_as" => OrmReturnType::ASSOC, "query_type" => OrmQueryType::SELECT, ]
            )
        );

        if (!$ignore_msg)
            self::exception(
                "ConnTest",
                <<<CONN
                        <h2>Connection Established!</h2>
                    <u>Your connection info states:</u>
                    <div style="color: gold; font-weight: bold; margin: 5px 1px;">
                        &gt; Host: <u>$host_short</u>
                    </div>
                    <div style="color: gold; font-weight: bold; margin: 5px 1px;">
                        &gt; User: <u>$users</u>
                    </div>
                    <div style="color: gold; font-weight: bold; margin: 5px 1px;">
                        &gt; Database: <u>$db</u>
                    </div>
                CONN,
                [ "type" => "success" ]
            );

        return ["host" => $host_short, "user" => $users, "db" => $db, "connected" => true];
    }

    public function close(mysqli|SQLite3|null $link = null, bool $silent_error = false) : bool {
        try {
            if($link)
                return $link->close();

            return self::get_link()->close();
        }catch (\Exception $e){
            if(!$silent_error)
                self::exception(
                    "ConnErr",
                    "<div style='color: #e00; font-weight: bold; margin: 5px 1px;'>Failed to close connection. No pre-existing DB connection</div>",
                    exception: $e
                );
        }

        return false;
    }

    private function set_db(mysqli|array|string $args, bool $persist_conn) : void {
        self::$persist_connection = $persist_conn;

        if(is_string($args)) {
            $this->connect_sqlite($args);
            return;
        }

        if(is_array($args)) {
            self::$DB_ARGS = $args;
            $this->connect();
            return;
        }

        $this->plug($args);
    }

    public function get_db_args() : array { return self::$DB_ARGS; }

    public function set_link(mysqli|SQLite3 $link): void {
        self::$link = $link;
        self::cache_connection($link);
    }

    public function get_link(): mysqli|SQLite3|null { return self::$link ?? null; }

    public function escape_string(string $value) : string
    {
        if(!isset(self::$active_driver))
            self::exception(
                "AccessingDbWithoutConn",
                "You are trying to access the database link without an active connection"
            );

        if (self::$active_driver == OrmDriver::MYSQL)
            return self::$link->real_escape_string($value);

        return self::$link::escapeString($value);
    }

    public static function is_connected() : bool
    {
        $link = self::get_cached_connection();

        if($link) {
            self::$link = $link;
            $ping = self::new()->ping(true, $link, true);
            self::$connected = $ping['connected'];
            self::$PINGED_DB_ARGS = $ping;
        }

        return self::$connected;
    }
}