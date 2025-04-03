<?php

namespace BrickLayer\Lay\Orm\Interfaces;

use mysqli_result;
use PgSql\Result;
use SQLite3Result;

interface OrmConnections {
    public function query(string $query) : mysqli_result|bool|SQLite3Result|Result;
    public function close() : void;
    public function exec(string $query, array $params = []) : mysqli_result|Result|bool;
    public function escape_string(string $value) : string;
    public function is_connected() : bool;
    public function affected_rows(Result|mysqli_result|null $result = null) : int;
    public function fetch_result(Result|mysqli_result|null $result = null, ?int $mode = null) : array|bool;
}