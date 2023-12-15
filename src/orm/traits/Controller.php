<?php
declare(strict_types=1);
namespace BrickLayer\Lay\orm\traits;

use BrickLayer\Lay\core\traits\IsSingleton;

trait Controller{
    use IsSingleton;
    use Clean;
    use SelectorOOP;
}
