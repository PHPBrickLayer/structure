<?php

namespace Bricks\Business\Model;

use BrickLayer\Lay\Core\Traits\IsSingleton;
use Utils\Traits\ModelHelper;

class Newsletter {
    use IsSingleton;
    use ModelHelper;

    public static string $table = "newsletters";

    public function is_exist(string $email): bool
    {
        return self::exists("`email`='$email'") > 0;
    }
}