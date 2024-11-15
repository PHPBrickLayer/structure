<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Orm\Traits;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\Enums\OrmQueryType;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use JetBrains\PhpStorm\ArrayShape;
use mysqli;
use SQLite3;

trait Config{
    private static OrmDriver $active_driver;
    private static string $db_name;
    private static mysqli|SQLite3 $link;
    private static string $CHARSET = "utf8mb4";
    private static string $DB_FILE;
    private static bool $persist_connection = true;
    private static array $DB_ARGS = [
        "host" => null,
        "user" => null,
        "password" => null,
        "db" => null,
        "port" => null,
        "socket" => null,
        "silent" => false,
        "auto_commit" => false,
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

    protected static function save_to_session(string $key, mixed $value) : void
    {
        $_SESSION["__LAY_SQL__"][$key] = $value;
    }

    protected static function get_from_session(string $key) : mixed
    {
        return $_SESSION["__LAY_SQL__"][$key] ?? null;
    }

    private static function cache_connection($link) : void
    {
        if(!isset(self::$active_driver))
            return;

        self::save_to_session(self::$active_driver->value, $link);
    }

    private static function cache_pinged_data(array $args) : void
    {
        self::save_to_session("PINGED_DATA", $args);
    }

    private static function get_pinged_data() : ?array
    {
        return self::get_from_session("PINGED_DATA");
    }

    private static function get_cached_connection() : mysqli|SQLite3|null
    {
        return self::get_from_session(self::$active_driver->value);
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
        self::$db_name = $db;

        if(self::is_connected())
            return $this->get_link();

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = mysqli_init();

        if(!$mysqli)
            self::exception(
                "ConnErr",
                "<div style='color: #e00; font-weight: bold; margin: 5px 1px;'>Cannot initialize connection</div>"
            );

        if(!$auto_commit)
            $mysqli->options(MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = 0');
        else
            $mysqli->options(MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = 1');

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

        $pinged = self::get_pinged_data();
        $now = time();

        if($pinged && self::$DB_ARGS['user'] == $pinged['user'] && self::$DB_ARGS['db'] == $pinged['db'])
            return $pinged;

        $data = $this->query(
            "SELECT SUBSTRING_INDEX(host, ':', 1) AS host_short, USER AS users, db FROM information_schema.processlist",
            [ "fetch_as" => OrmReturnType::ASSOC, "query_type" => OrmQueryType::SELECT, ]
        );

        $data = [
            "host" => $data['host_short'],
            "user" => $data['users'],
            "db" => $data['db'],
            "connected" => true,
            "expire" => strtotime("1 hour")
        ];

        self::cache_pinged_data($data);

        if (!$ignore_msg)
            self::exception(
                "ConnTest",
                <<<CONN
                        <h2>Connection Established!</h2>
                    <u>Your connection info states:</u>
                    <div style="color: gold; font-weight: bold; margin: 5px 1px;">
                        &gt; Host: <u>{$data['host']}</u>
                    </div>
                    <div style="color: gold; font-weight: bold; margin: 5px 1px;">
                        &gt; User: <u>{$data['user']}</u>
                    </div>
                    <div style="color: gold; font-weight: bold; margin: 5px 1px;">
                        &gt; Database: <u>{$data['db']}</u>
                    </div>
                CONN,
                [ "type" => "success" ]
            );

        return $data;
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

    private function set_db(mysqli|SQLite3|array|string $args, bool $persist_conn) : void {
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

        $this->set_link($args);
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
        }

        return self::$connected;
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
            "auto_commit" => 'bool',
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
                    "auto_commit" => $_ENV['DB_AUTO_COMMIT'] ?? false,
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

    public function switch_db(string $name): bool
    {
        $name = self::escape_string($name);
        return self::$link->select_db($name);
    }
}