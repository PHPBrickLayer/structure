<?php

namespace BrickLayer\Lay\Libs\LayCrypt\Enums;

use BrickLayer\Lay\Libs\Primitives\Enums\EnumHelper;

enum JwtError
{
    case INVALID_TOKEN;
    case INVALID_PAYLOAD;
    case EXPIRED;
    case NBF;

    use EnumHelper;
}