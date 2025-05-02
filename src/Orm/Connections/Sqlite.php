<?php

namespace BrickLayer\Lay\Orm\Connections;

use BrickLayer\Lay\Orm\Interfaces\OrmConnections;
use Override;
use SQLite3;
use SQLite3Result;

final class Sqlite implements OrmConnections
{
    public function __construct(public readonly SQLite3 $link){}

    /**
     * @return SQLite3Result|false
     */
    #[Override]
    public function query(string $query): SQLite3Result|bool
    {
        return $this->link->query($query);
    }

    #[Override]
    public function close(): void
    {
        $this->link->close();
    }

    #[Override]
    public function exec(string $query, array $params = []): bool
    {
        return $this->link->exec($query);
    }

    #[Override]
    public function escape_string(string $value) : string
    {
        return $this->link::escapeString($value);
    }

    // SQLITE is a file, so checking if it's connected doesn't make sense
    /**
     * @return true
     */
    #[Override]
    public function is_connected() : bool
    {
        return true;
    }

    #[Override]
    /**
     * @param SQLite3Result|null $result
     */
    public function affected_rows(mixed $result = null) : int
    {
        return $this->link->changes();
    }


    #[Override]
    /**
     * @param SQLite3Result|null $result
     */
    public function fetch_result(mixed $result = null, ?int $mode = null) : array|bool
    {
        return $result->fetchArray($mode ?? SQLITE3_BOTH);
    }

}