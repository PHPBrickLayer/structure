<?php

namespace Unit;

use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;

class FakeControllerSingleton
{
    use IsSingleton;
    public function print_user(int $id): array
    {
        return [
            "name" => "User name",
            "id" => $id
        ];
    }
}