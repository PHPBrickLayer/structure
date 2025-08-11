<?php

namespace BrickLayer\Lay\Orm\Connections;

use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use BrickLayer\Lay\Orm\Enums\OrmTransactionMode;
use BrickLayer\Lay\Orm\Interfaces\OrmConnections;

use PgSql\Connection;
use PgSql\Result;

final class Postgres implements OrmConnections
{
    public function __construct(public readonly Connection $link){}

    
    public function query(string $query) : Result|false
    {
        $x = @pg_query($this->link, $query);

        if($x === false) {
            Throw new \Exception(pg_last_error($this->link));
        }

        return $x;
    }

    
    public function close(): void
    {
        pg_close($this->link);
    }

    /**
     * @return Result|false
     */
    
    public function exec(string $query, array $params = []) : Result|false
    {
        $x = @pg_query_params($this->link, $query, $params);

        if($x === false) {
            Throw new \Exception(pg_last_error($this->link));
        }

        return $x;
    }

    public function escape_string(string $value) : string
    {
        return pg_escape_string($this->link, $value);
    }

    public function is_connected() : bool
    {
        return pg_connection_status($this->link) === PGSQL_CONNECTION_OK;
    }

    public function server_info() : array
    {
        $x = pg_version($this->link);
        return [
            "service" => "Postgres",
            "version" => $x['client'],
        ];
    }
    
    /**
     * @param Result|null $result
     */
    public function affected_rows(mixed $result = null) : int
    {
        return pg_affected_rows($result);
    }

    /**
     * @param Result|null $result
     * @param OrmReturnType|null $mode
     */
    public function fetch_result(mixed $result = null, ?OrmReturnType $mode = null) : array
    {
        return pg_fetch_all($result, match ($mode) {
            default => PGSQL_BOTH,
            OrmReturnType::ASSOC => PGSQL_ASSOC,
            OrmReturnType::NUM => PGSQL_NUM,
        });
    }

    /**
     * @param Result|null $result
     * @param OrmReturnType|null $mode
     */
    public function fetch_one(mixed $result = null, ?OrmReturnType $mode = null) : array
    {
        return $this->fetch_result($result, $mode)[0];
    }

    public function begin_transaction(?OrmTransactionMode $flags = null, ?string $name = null, bool $in_transaction = false): bool
    {
        if($in_transaction) {
            if($name)
                $this->query("SAVEPOINT $name");

            return true;
        }

        if (!$flags)
            $this->query("BEGIN");

        if ($flags == OrmTransactionMode::READ_ONLY || $flags == OrmTransactionMode::READ_WRITE)
            $this->query("BEGIN ISOLATION LEVEL REPEATABLE READ");

        if ($flags == OrmTransactionMode::CONSISTENT_SNAPSHOT)
            $this->query("BEGIN ISOLATION LEVEL SERIALIZABLE");

        if($name)
            $this->query("SAVEPOINT $name");

        return true;
    }

    public function commit(?OrmTransactionMode $flags = null, ?string $name = null): bool
    {
        $this->query("COMMIT");
        return true;
    }

    public function rollback(?OrmTransactionMode $flags = null, ?string $name = null): bool
    {
        if($name) {
            $this->query("ROLLBACK TO SAVEPOINT $name");
            return true;
        }

        $this->query("ROLLBACK");
        return true;
    }

    public function in_transaction(): bool
    {
        return pg_transaction_status($this->link) !== PGSQL_TRANSACTION_IDLE;
    }

}