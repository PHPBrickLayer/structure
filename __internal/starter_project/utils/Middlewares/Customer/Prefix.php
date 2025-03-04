<?php

namespace Utils\Middlewares\Customer;

use BrickLayer\Lay\Core\Api\ApiEngine;

abstract class Prefix
{
    public static function run(ApiEngine $instance) : ApiEngine
    {
        return $instance->prefix("client");
    }
}