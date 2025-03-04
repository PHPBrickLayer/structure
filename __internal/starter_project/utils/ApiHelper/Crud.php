<?php

namespace Utils\ApiHelper;

use BrickLayer\Lay\Core\Api\ApiEngine;

class Crud
{
    public static function set(ApiEngine $req, $controller, string $route, bool $enable_delete = true): void
    {
        $req->get("$route/list")->bind(fn() => $controller->list())
            ->post("$route/edit")->bind(fn() => $controller->edit())
            ->post("$route/new")->bind(fn() => $controller->add());

        if($enable_delete)
            $req->post("$route/delete")->bind(fn() => $controller->delete());
    }

}