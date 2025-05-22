<?php

namespace BrickLayer\Lay\Core\Enums;

enum LayServerType
{
    case APACHE;
    case NGINX;
    case CADDY;
    case PHP;
    case OTHER;
    case CLI;
}
