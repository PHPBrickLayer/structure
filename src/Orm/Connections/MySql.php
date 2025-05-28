<?php

namespace BrickLayer\Lay\Orm\Connections;

use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use BrickLayer\Lay\Orm\Enums\OrmTransactionMode;
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
     * @param OrmReturnType|null $mode
     */
    public function fetch_result(mixed $result = null, ?OrmReturnType $mode = null) : array
    {
        return $result->fetch_all(match ($mode){
            default => MYSQLI_BOTH,
            OrmReturnType::ASSOC => MYSQLI_ASSOC,
            OrmReturnType::NUM => MYSQLI_NUM,
        });
    }

    /**
     * @param mysqli_result|null $result
     * @param OrmReturnType|null $mode
     */
    public function fetch_one(mixed $result = null, ?OrmReturnType $mode = null) : array
    {
        if($mode == OrmReturnType::ASSOC) return mysqli_fetch_assoc($result);

        return mysqli_fetch_row($result);
    }

    public function begin_transaction( ?OrmTransactionMode $flags = null, ?string $name = null, bool $in_transaction = false ): bool
    {
        $flags = match ($flags) {
            default => 0,
            OrmTransactionMode::READ_ONLY => MYSQLI_TRANS_START_READ_ONLY,
            OrmTransactionMode::READ_WRITE => MYSQLI_TRANS_START_READ_WRITE,
            OrmTransactionMode::CONSISTENT_SNAPSHOT => MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT,
        };

        return $this->link->begin_transaction($flags, $name);
    }

    public function commit( ?OrmTransactionMode $flags = null, ?string $name = null ): bool
    {
        $flags = match ($flags) {
            default => 0,
            OrmTransactionMode::READ_ONLY => MYSQLI_TRANS_START_READ_ONLY,
            OrmTransactionMode::READ_WRITE => MYSQLI_TRANS_START_READ_WRITE,
            OrmTransactionMode::CONSISTENT_SNAPSHOT => MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT,
        };

        return $this->link->commit($flags, $name);
    }

    public function rollback( ?OrmTransactionMode $flags = null, ?string $name = null ): bool
    {
        $flags = match ($flags) {
            default => 0,
            OrmTransactionMode::READ_ONLY => MYSQLI_TRANS_START_READ_ONLY,
            OrmTransactionMode::READ_WRITE => MYSQLI_TRANS_START_READ_WRITE,
            OrmTransactionMode::CONSISTENT_SNAPSHOT => MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT,
        };

        return $this->link->rollback($flags, $name);
    }
}