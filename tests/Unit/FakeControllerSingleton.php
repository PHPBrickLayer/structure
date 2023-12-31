<?php

namespace Unit;

use BrickLayer\Lay\Core\Traits\IsSingleton;

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