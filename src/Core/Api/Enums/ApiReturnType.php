<?php

namespace BrickLayer\Lay\Core\Api\Enums;

enum ApiReturnType : string
{
    case JSON = "JSON";
    case HTML = "HTML";
    case XML = "XML";
    case STRING = "STRING";
}
