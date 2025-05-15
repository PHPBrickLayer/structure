<?php

namespace BrickLayer\Lay\Orm\Connections;

use BrickLayer\Lay\Orm\Interfaces\OrmConnections;

use SQLite3;
use SQLite3Result;

final class Sqlite implements OrmConnections
{
    public function __construct(public readonly SQLite3 $link){}

    /**
     * @return SQLite3Result|false
     */
    
    public function query(string $query): SQLite3Result|bool
    {
        return $this->link->query($query);
    }

    
    public function close(): void
    {
        $this->link->close();
    }

    
    public function exec(string $query, array $params = []): bool
    {
        return $this->link->exec($query);
    }

    
    public function escape_string(string $value) : string
    {
        return $this->link::escapeString($value);
    }

    // SQLITE is a file, so checking if it's connected doesn't make sense
    /**
     * @return true
     */
    
    public function is_connected() : bool
    {
        return true;
    }

    
    /**
     * @param SQLite3Result|null $result
     */
    public function affected_rows(mixed $result = null) : int
    {
        return $this->link->changes();
    }


    
    /**
     * @param SQLite3Result|null $result
     */
    public function fetch_result(mixed $result = null, ?int $mode = null) : array|bool
    {
        return $result->fetchArray($mode ?? SQLITE3_BOTH);
    }

}