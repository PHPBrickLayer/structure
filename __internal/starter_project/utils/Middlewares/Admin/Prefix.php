<?php

namespace Utils\Middlewares\Admin;

use BrickLayer\Lay\Core\Api\ApiEngine;

abstract class Prefix
{
    public static function run(ApiEngine $instance) : ApiEngine
    {
        return $instance->prefix("admin");
    }
}