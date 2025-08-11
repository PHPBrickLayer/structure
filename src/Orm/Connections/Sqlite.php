<?php

namespace BrickLayer\Lay\Orm\Connections;

use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use BrickLayer\Lay\Orm\Enums\OrmTransactionMode;
use BrickLayer\Lay\Orm\Interfaces\OrmConnections;

use JetBrains\PhpStorm\ExpectedValues;
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

    public function server_info() : array
    {
        $x = $this->link::version();
        return [
            "service" => 'Sqlite ' . $x['versionString'],
            "version" => $x['versionNumber'],
        ];
    }

    
    /**
     * @param SQLite3Result|null $result
     */
    public function affected_rows(mixed $result = null) : int
    {
        return $this->link->changes();

        //TODO: Test this pattern in the real world first
        $num = $this->link->changes();

        if($num)
            return $num;

        $num = $this->fetch_result($result,OrmReturnType::NUM);
        return count($num);
    }

    
    /**
     * @param SQLite3Result|null $result
     */
    public function fetch_result(mixed $result = null, ?OrmReturnType $mode = null) : array|bool
    {
        return $result->fetchArray(
            match ($mode) {
                default => SQLITE3_BOTH,
                OrmReturnType::ASSOC => SQLITE3_ASSOC,
                OrmReturnType::NUM => SQLITE3_NUM,
            }
        );
    }

    /**
     * @param SQLite3Result|null $result
     */
    public function fetch_one(mixed $result = null, ?OrmReturnType $mode = null) : array|bool
    {
        return $this->fetch_result($result, $mode)[0];
    }

    public function begin_transaction( ?OrmTransactionMode $flags = null, $name = null, bool $in_transaction = false ): bool
    {
        return $this->exec("BEGIN");
    }

    public function commit( ?OrmTransactionMode $flags = null, ?string $name = null ): bool
    {
        return $this->exec("COMMIT");
    }

    public function rollback( ?OrmTransactionMode $flags = null, ?string $name = null ): bool
    {
        return $this->exec("ROLLBACK");
    }

    public function in_transaction(): bool
    {
        $state = $this->link->querySingle("PRAGMA transaction_state");

        return $state > 0;
    }
}