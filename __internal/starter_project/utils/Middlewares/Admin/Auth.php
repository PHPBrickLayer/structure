<?php

namespace Utils\Middlewares\Admin;

use BrickLayer\Lay\Core\Api\ApiEngine;
use bricks\SystemUser\Controller\SystemUsers;

abstract class Auth
{
    public static function run(ApiEngine $instance) : void
    {
        $instance->group_middleware(fn() => SystemUsers::new()->validate_session());
    }
}