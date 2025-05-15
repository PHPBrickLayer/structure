<?php

namespace BrickLayer\Lay\Orm\Connections;

use BrickLayer\Lay\Orm\Interfaces\OrmConnections;
use mysqli;
use mysqli_result;


final class MySql implements OrmConnections
{
    public function __construct(public readonly mysqli $link) {}

    
    public function query(string $query): mysqli_result|bool
    {
        return $this->link->query($query);
    }

    
    public function close(): void
    {
        $this->link->close();
    }

    
    public function exec(string $query, array $params = []) : mysqli_result|bool
    {
        return $this->link->execute_query($query, $params);
    }

    
    public function escape_string(string $value) : string
    {
        return $this->link->real_escape_string($value);
    }

    
    public function is_connected() : bool
    {
        return isset($this->link->host_info);
    }

    
    /**
     * @param mysqli_result|null $result
     * @return int
     */
    public function affected_rows(mixed $result = null) : int
    {
        return $this->link->affected_rows;
    }

    
    /**
     * @param mysqli_result|null $result
     */
    public function fetch_result(mixed $result = null, ?int $mode = null) : array
    {
        return $result->fetch_all($mode ?? MYSQLI_ASSOC);
    }

}