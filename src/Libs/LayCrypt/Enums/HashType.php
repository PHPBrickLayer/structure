<?php

namespace BrickLayer\Lay\Libs\LayCrypt\Enums;

use BrickLayer\Lay\Libs\Primitives\Enums\EnumHelper;

enum HashType : string
{
    case SHA256 = "HS256";
    case MD5 = "MD5";
    case SHA1 = "HS1";

    use EnumHelper;
}