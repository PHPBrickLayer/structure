<?php

namespace BrickLayer\Lay\Orm\Connections;

use BrickLayer\Lay\Orm\Interfaces\OrmConnections;

use PgSql\Connection;
use PgSql\Result;

final class Postgres implements OrmConnections
{
    public function __construct(public readonly Connection $link){}

    
    public function query(string $query) : Result|false
    {
        return pg_query($this->link, $query);
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
        return pg_query_params($this->link, $query, $params);
    }

    
    public function escape_string(string $value) : string
    {
        return pg_escape_string($this->link, $value);
    }

    
    public function is_connected() : bool
    {
        return pg_connection_status($this->link) === PGSQL_CONNECTION_OK;
    }

    
    /**
     * @param Result|null $result
     */
    public function affected_rows(mixed $result = null) : int
    {
        return pg_affected_rows($result);
    }

    /**
     * @return array[]
     *
     * @psalm-return array<array>
     */
    
    /**
     * @param Result|null $result
     */
    public function fetch_result(mixed $result = null, ?int $mode = null) : array
    {
        return pg_fetch_all($result, $mode ?? PGSQL_ASSOC);
    }

}