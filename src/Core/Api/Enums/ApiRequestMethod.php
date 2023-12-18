<?php

namespace BrickLayer\Lay\Core\Api\Enums;

enum ApiRequestMethod : string
{
    case POST = "POST";
    case GET = "GET";
    case HEAD = "HEAD";
    case PUT = "PUT";
    case DELETE = "DELETE";
}
