<?php

namespace BrickLayer\Lay\Orm\Interfaces;

use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use BrickLayer\Lay\Orm\Enums\OrmTransactionMode;
use mysqli_result;
use PgSql\Result;
use SQLite3Result;

interface OrmConnections {
    public function query(string $query) : mysqli_result|bool|SQLite3Result|Result;
    public function exec(string $query, array $params = []) : mysqli_result|Result|bool;
    public function close() : void;
    public function is_connected() : bool;

    public function escape_string(string $value) : string;
    public function affected_rows(Result|mysqli_result|SQLite3Result|null $result = null) : int;

    public function fetch_result(Result|mysqli_result|SQLite3Result|null $result = null, ?OrmReturnType $mode = null) : array|bool;
    public function fetch_one(Result|mysqli_result|SQLite3Result|null $result = null, ?OrmReturnType $mode = null) : array|bool;

    public function begin_transaction(?OrmTransactionMode $flags = null, ?string $name = null, bool $in_transaction = false) : bool;
    public function commit(?OrmTransactionMode $flags = null, ?string $name = null) : bool;
    public function rollback(?OrmTransactionMode $flags = null, ?string $name = null) : bool;
}