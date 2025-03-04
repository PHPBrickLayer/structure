<?php

namespace Utils\Middlewares\Customer;

use BrickLayer\Lay\Core\Api\ApiEngine;
use bricks\SystemUser\Controller\SystemUsers;

abstract class Auth
{
    public static function run(ApiEngine $instance) : void
    {
        $instance->group_middleware(fn() => ["code" => 200, "msg" => "Successful"]);
    }
}