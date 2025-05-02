<?php

namespace BrickLayer\Lay\Orm\Enums;

use BrickLayer\Lay\Orm\Interfaces\OrmConnections;

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

    public static function to_orm_connections(self $driver, mixed $db_link): \BrickLayer\Lay\Orm\Connections\MySql|\BrickLayer\Lay\Orm\Connections\Postgres|\BrickLayer\Lay\Orm\Connections\Sqlite|false|bool
    {
        if(!$db_link)
            return false;

        if($driver == self::MYSQL)
            return new \BrickLayer\Lay\Orm\Connections\MySql($db_link);

        if($driver == self::POSTGRES)
            return new \BrickLayer\Lay\Orm\Connections\Postgres($db_link);

        if($driver == self::SQLITE || $driver == self::SQLITE3)
            return new \BrickLayer\Lay\Orm\Connections\Sqlite($db_link);

        return false;
    }

}
