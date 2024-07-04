<?php

declare(strict_types=1);
namespace BrickLayer\Lay\Libs\String\Enum;

enum EscapeType
{
    case ALL;

    /**
     * Primitive Escape Types
     */
    case P_ESCAPE;        ##[0]
    case P_STRIP;         ##[1]
    case P_TRIM;          ##[2]
    case P_SPEC_CHAR;     ##[3]
    case P_ENCODE_URL;    ##[4]
    case P_REPLACE;       ##[5]
    case P_URL;           ##[6]

    /**
     * Combined primitive escape types
     */
    case SQL_ESCAPE;
    case STRIP_ESCAPE;
    case STRIP_TRIM_ESCAPE;
    case TRIM_ESCAPE;
    case ESCAPE_SPEC_CHAR;
    case SPEC_CHAR_STRIP;
    case STRIP_TRIM;
}