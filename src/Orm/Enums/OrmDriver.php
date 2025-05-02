<?php

namespace BrickLayer\Lay\Orm\Enums;

use BrickLayer\Lay\Orm\Connections\MySql;
use BrickLayer\Lay\Orm\Connections\Postgres;
use BrickLayer\Lay\Orm\Connections\Sqlite;

enum OrmDriver : string {
    case MYSQL = "mysql";
    case SQLITE = "sqlite";
    case SQLITE3 = "sqlite3";
    case POSTGRES = "pgsql";

    public static function stringify() : string
    {
        $str = "";
        foreach (self::cases() as $c){
            $str .= $c->value . ", ";
        }
        return rtrim($str, ", ");
    }

    public static function is_sqlite(self $driver): bool
    {
        return $driver == self::SQLITE || $driver == self::SQLITE3;
    }

    public static function to_orm_connections(self $driver, mixed $db_link): MySql|Postgres|Sqlite|false
    {
        if(!$db_link)
            return false;

        if($driver == self::MYSQL)
            return new MySql($db_link);

        if($driver == self::POSTGRES)
            return new Postgres($db_link);

        if($driver == self::SQLITE || $driver == self::SQLITE3)
            return new Sqlite($db_link);

        return false;
    }

}
