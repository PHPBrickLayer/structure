<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Orm\Traits;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayDir;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Orm\Connections\MySql;
use BrickLayer\Lay\Orm\Connections\Postgres;
use BrickLayer\Lay\Orm\Connections\Sqlite;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\Enums\OrmQueryType;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use BrickLayer\Lay\Orm\Interfaces\OrmConnections;
use BrickLayer\Lay\Orm\SQL;
use JetBrains\PhpStorm\ArrayShape;
use mysqli;
use PgSql\Connection;
use SQLite3;

trait Config{
    private static OrmDriver $active_driver;
    private static OrmConnections $link;

    private static string $db_name;
    private static string $DB_FILE;

    private static bool $persist_connection = true;
    private static bool $connected = false;

    private static array $DB_ARGS = [
        "host" => null,
        "user" => null,
        "password" => null,
        "db" => null,
        "port" => null,
        "socket" => null,
        "silent" => false,
        "auto_commit" => false,
        "persist_connection" => true,
        "charset" => null,
        "ssl" => [
            "key" => null,
            "certificate" => null,
            "ca_certificate" => null,
            "ca_path" => null,
            "cipher_algos" => null,
            "flag" => 0
        ],
    ];

    protected static function save_to_session(string $key, mixed $value) : void
    {
        $_SESSION["__LAY_SQL__"][$key] = $value;
    }

    protected static function get_from_session(string $key) : mixed
    {
        return $_SESSION["__LAY_SQL__"][$key] ?? null;
    }

    private static function cache_connection(OrmConnections $link) : void
    {
        if(!isset(self::$active_driver))
            return;

        if(self::$active_driver == OrmDriver::POSTGRES)
            return;

        self::save_to_session(self::$active_driver->value, $link->link);
    }

    private static function cache_pinged_data(array $args) : void
    {
        self::save_to_session("PINGED_DATA", $args);
    }

    private static function get_pinged_data() : ?array
    {
        return self::get_from_session("PINGED_DATA");
    }

    private static function get_cached_connection() : mixed
    {
        return self::get_from_session(self::$active_driver->value);
    }

    private function connect_sqlite(string $db_file) : void
    {
        if(!OrmDriver::is_sqlite(self::$active_driver))
            self::exception(
                "MismatchedDriver",
                "Database connection argument is string [$db_file], but database driver is [" .
                self::$active_driver->value . "]. Please change db driver to: " . OrmDriver::SQLITE->value . " or " .  OrmDriver::SQLITE3->value
            );

        $db = LayConfig::server_data()->db;
        self::$db_name = str_replace("/", DIRECTORY_SEPARATOR, $db_file);
        $db_file =  $db . self::$db_name;

        if(self::$connected && self::$DB_FILE == $db_file) {
            $this->get_link();
            return;
        }

        self::$DB_FILE = $db_file;
        LayDir::make($db, 0755, true);

        try {
            self::$link = new Sqlite(
                new SQLite3(
                    self::$DB_FILE,
                    SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE,
                    $_ENV['SQLITE_ENCRYPT_KEY'] ?? ''
                )
            );

            // We create a view which can be called whenever we want to use the uuid() function
            // Thanks to this gist for this solution
            // https://gist.github.com/fabiolimace/e3c3d354d1afe0b3175f65be2d962523
            self::$link->exec("
            DROP VIEW IF EXISTS uuid7;
            CREATE VIEW uuid7 AS WITH unixtime AS (
                SELECT CAST((STRFTIME('%s') * 1000) + ((STRFTIME('%f') * 1000) % 1000) AS INTEGER) AS time
            )
            SELECT PRINTF(
                '%08x-%04x-%04x-%04x-%012x', 
                (select time from unixtime) >> 16,
                (select time from unixtime) & 0xffff,
                ABS(RANDOM()) % 0x0fff + 0x7000,
                ABS(RANDOM()) % 0x3fff + 0x8000,
                ABS(RANDOM()) >> 16
            ) AS next;
            ");
        } catch (\Throwable $e){
            self::exception(
                "SQLiteConnectionError",
                "Error initializing SQLite DB [" . self::$DB_FILE . "]: ",
                exception: $e
            );
        }

        self::$link->link->enableExceptions(true);
        self::$link->link->busyTimeout($_ENV['SQLITE_BUSY_TIMEOUT'] ?? 600);
        self::$connected = true;
    }

    private function connect_mysql(array $args) : void
    {
        extract($args);

        $charset = $charset ?? "utf8mb4";
        $port ??= 3306;
        $port = (int) $port;
        $socket = $socket ?? null;
        self::$db_name = $db;

        if(self::is_connected()) {
            $this->get_link();
            return;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $mysqli = mysqli_init();

        if(!$mysqli)
            self::exception(
                "ConnErr",
                "<div style='color: #e00; font-weight: bold; margin: 5px 1px;'>Cannot initialize connection</div>"
            );

        $auto_commit ??= true;

        if($auto_commit)
            $mysqli->options(MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = 1');
        else
            $mysqli->options(MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = 0');

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

        if(!$connected) {
            if (filter_var(@$silent, FILTER_VALIDATE_BOOL))
                return;

            self::exception(
                "ConnErr",
                "<div style='color: #e00; font-weight: bold; margin: 5px 1px;'>" . mysqli_connect_error() . "</div>"
            );
        }

        self::$connected = true;
        $mysqli->set_charset($charset);
        $this->set_link(new MySql($mysqli));
    }

    private function connect_postgres(array $args) : void
    {
        extract($args);

        $charset = $charset ?? "UTF8";
        $port ??= 5432;
        $port = (int) $port;
        self::$db_name = $db;

        if(self::is_connected()) {
            $this->get_link();
            return;
        }

        $silent = filter_var(@$silent, FILTER_VALIDATE_BOOL);

        $project_name = "Lay_" . LayConfig::get_project_identity();
        $conn_arg = LayConfig::$ENV_IS_PROD ? "host=$host " : "";
        $conn_arg .= "port=$port dbname=$db user=$user password=$password options='--client_encoding=$charset --application_name=$project_name'";

        if($silent) {
            $connected = @pg_connect($conn_arg);
            if(!$connected) return;
        }
        else
            $connected = pg_connect($conn_arg);

        self::$connected = true;
        $this->set_link(new Postgres($connected));
    }

    private function set_db(array|string $args) : void
    {
        if(OrmDriver::is_sqlite(self::$active_driver)) {
            $this->connect_sqlite($args);
            return;
        }

        if(self::$active_driver == OrmDriver::POSTGRES) {
            $this->connect_postgres($args);
            return;
        }

        $this->connect_mysql($args);
    }

    public function close(?OrmConnections $link = null, bool $silent_error = false) : bool {
        try {
            if($link) {
                $link->close();
                return true;
            }

            self::get_link()->close();
            return true;
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

    public function get_db_args() : array { return self::$DB_ARGS; }

    public function set_link(OrmConnections $link): void
    {
        self::$link = $link;
        self::cache_connection($link);
    }

    public function get_link(): OrmConnections|null { return self::$link ?? null; }

    public function escape_string(string $value) : string
    {
        return self::$link->escape_string($value);
    }

    /**
     * Check Database Connection
     * @param bool $ignore_msg false by default to echo connection info
     * @param OrmConnections|null $link link to database connection
     * @param bool $ignore_no_conn false by default to silence no connection error
     * @return array{
     *     host: string,
     *     user: string,
     *     db: string,
     *     connected: bool,
     * }
     **/
    public function ping(bool $ignore_msg = false, ?OrmConnections $link = null, bool $ignore_no_conn = false) : array {
        $link = $link ?? $this->get_link() ?? null;

        if(!$link || $link->is_connected() === false)
            return ["host" => "", "user" => "", "db" => "", "connected" => false];

        $pinged = self::get_pinged_data();

        if($pinged && self::$DB_ARGS['user'] == $pinged['user'] && self::$DB_ARGS['db'] == $pinged['db'])
            return $pinged;

        $postgres_query = "SELECT current_user as users, inet_server_addr() AS host_short, current_database() AS db;";
        $mysql_query = /** @lang text */
            "SELECT SUBSTRING_INDEX(host, ':', 1) AS host_short, USER AS users, db FROM information_schema.processlist;";

        $query = $mysql_query;

        if(self::$active_driver == OrmDriver::POSTGRES)
            $query = $postgres_query;

        try {
            $data = $this->query( $query, ["fetch_as" => OrmReturnType::ASSOC, "query_type" => OrmQueryType::SELECT]);

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
        } catch (\Throwable $e) {
            if(!$ignore_no_conn)
                self::exception(
                    "ConnErr",
                    "No connection detected: <h5 style='color: #008dc5'>Connection might be closed:</h5>",
                    exception: $e
                );

        }

        return ["host" => "", "user" => "", "db" => "", "connected" => false];
    }

    public static function is_connected() : bool
    {
        $link = self::get_cached_connection();

        if($link) {
            self::$link = OrmDriver::to_orm_connections(self::$active_driver, $link);

            $ping = self::new()->ping(true, self::$link, true);
            self::$connected = $ping['connected'];
        }

        return self::$connected;
    }

    /**
     * @param $connection string|null|array{
     *     host: string,
     *     user: string,
     *     password: string,
     *     db: string,
     *     port: string,
     *     socket: string,
     *     silent: bool,
     *     auto_commit: bool,
     *     persist_connection: bool,
     *     ssl: array{
     *         certificate: string,
     *         ca_certificate: string,
     *         ca_path: string,
     *         cipher_algos: string,
     *         flag: int,
     *     }
     * } An array of db connection or a string for sqlite db
     * @param OrmDriver|null $driver
     * @return self
     */
    public static function init(
        array|null|string $connection = null,
        ?OrmDriver $driver = OrmDriver::MYSQL
    ): self
    {
        $driver = $driver ?? OrmDriver::tryFrom($_ENV['DB_DRIVER'] ?? '');

        if($driver === null)
            self::exception("InvalidOrmDriver", "An invalid db driver was received: [" . @$_ENV['DB_DRIVER'] . "]. Please specify the `DB_DRIVER`. Valid keys includes any of the following: [" . OrmDriver::stringify() . "]");

        if($connection === null) {
            $parse_bool = fn(string $key, bool $default) => filter_var($_ENV[$key] ?? $default, FILTER_VALIDATE_BOOLEAN);

            $connection = match ($driver) {
                OrmDriver::MYSQL, OrmDriver::POSTGRES => [
                    "host" => LayFn::env('DB_HOST'),
                    "user" => LayFn::env('DB_USERNAME'),
                    "password" => LayFn::env('DB_PASSWORD'),
                    "db" => LayFn::env('DB_NAME'),
                    "port" => LayFn::env('DB_PORT'),
                    "socket" => LayFn::env('DB_SOCKET'),
                    "charset" => LayFn::env('DB_CHARSET'),
                    "silent" => LayFn::env('DB_ALLOW_STARTUP_ERROR', false),
                    "auto_commit" => LayFn::env('DB_AUTO_COMMIT', true),
                    "persist_connection" => LayFn::env('DB_PERSIST_CONNECTION', false),
                    "ssl" => [
                        "key" => LayFn::env('DB_SSL_KEY'),
                        "certificate" => LayFn::env('DB_SSL_CERTIFICATE'),
                        "ca_certificate" => LayFn::env('DB_SSL_CA_CERTIFICATE'),
                        "ca_path" => LayFn::env('DB_SSL_CA_PATH'),
                        "cipher_algos" => LayFn::env('DB_SSL_CIPHER_ALGOS'),
                        "flag" => LayFn::env('DB_SSL_FLAG', 0),
                    ],
                ],
                default => LayFn::env('SQLITE_DB'),
            };
        }

        if(is_string($connection))
            $driver = OrmDriver::SQLITE;

        self::$active_driver = $driver;
        self::$persist_connection = $connection['persist_connection'] ?? true;
        self::new()->set_db($connection);

        return self::new();
    }

    /**
     * Throws exceptions if no driver is found.
     * Returns false if exception is caught
     *
     * @throws \Exception
     * @return OrmDriver|bool
     */
    public static function get_driver() : OrmDriver|bool
    {
        if(isset(self::$active_driver))
            return self::$active_driver;

        if(!isset($_ENV['DB_DRIVER']))
            self::exception(
                "NoDBDriverFound",
                "No Database driver was found. It's possible that you called this method before initializing the ORM"
            );

        if($driver = OrmDriver::tryFrom($_ENV['DB_DRIVER'] ?? ''))
            return $driver;

        self::exception(
            "UnidentifiedDriver",
            "An unidentified database driver was found: " . $_ENV['DB_DRIVER']
        );

        return false;
    }
}