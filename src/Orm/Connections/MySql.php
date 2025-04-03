<?php

namespace BrickLayer\Lay\Orm\Connections;

use BrickLayer\Lay\Orm\Interfaces\OrmConnections;
use mysqli;
use mysqli_result;
use Override;

class MySql implements OrmConnections
{
    public function __construct(public readonly mysqli $link) {}

    #[Override]
    public function query(string $query): mysqli_result|bool
    {
        return $this->link->query($query);
    }

    #[Override]
    public function close(): void
    {
        $this->link->close();
    }

    #[Override]
    public function exec(string $query, array $params = []) : mysqli_result|bool
    {
        return $this->link->execute_query($query, $params);
    }

    #[Override]
    public function escape_string(string $value) : string
    {
        return $this->link->real_escape_string($value);
    }

    #[Override]
    public function is_connected() : bool
    {
        return isset($this->link->host_info);
    }

    #[Override]
    /**
     * @param mysqli_result|null $result
     */
    public function affected_rows(mixed $result = null) : int
    {
        return $this->link->affected_rows;
    }

    #[Override]
    /**
     * @param mysqli_result|null $result
     */
    public function fetch_result(mixed $result = null, ?int $mode = null) : array
    {
        return $result->fetch_all($mode ?? MYSQLI_ASSOC);
    }

}