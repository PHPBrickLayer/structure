<?php

namespace BrickLayer\Lay\Orm\Enums;

enum OrmDriver : string {
    case MYSQL = "mysql";
    case SQLITE = "sqlite3";

    public static function stringify() : string
    {
        $str = "";
        foreach (self::cases() as $c){
            $str .= $c->value . ", ";
        }
        return rtrim($str, ", ");
    }
}
