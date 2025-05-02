<?php
declare(strict_types=1);

namespace Bricks\Business\Model;

use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;
use Utils\Traits\ModelHelper;

class Prospect
{
    use IsSingleton;
    use ModelHelper;

    public static string $table = "prospects";

    public function is_exist(string $name, string $email) : ?array
    {
        $data = self::get_by("`name`='$name' AND email='$email'");
        return empty($data) ? null : $data;
    }
}