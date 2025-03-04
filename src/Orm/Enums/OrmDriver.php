<?php

namespace BrickLayer\Lay\Orm\Enums;

//TODO: Implement support for POSTGRE
enum OrmDriver : string {
    case MYSQL = "mysql";
    case SQLITE = "sqlite3";
    case POSTGRES = "postgres";

    public static function stringify() : string
    {
        $str = "";
        foreach (self::cases() as $c){
            $str .= $c->value . ", ";
        }
        return rtrim($str, ", ");
    }
}
