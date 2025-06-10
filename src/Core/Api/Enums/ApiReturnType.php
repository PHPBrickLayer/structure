<?php

namespace BrickLayer\Lay\Core\Api\Enums;

enum ApiReturnType
{
    case JSON;
    case HTML;
    case STREAM;
    case XML;
    case STRING;
}
