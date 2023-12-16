<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Orm\Traits;

use BrickLayer\Lay\Core\Traits\IsSingleton;

trait Controller{
    use IsSingleton;
    use Clean;
    use SelectorOOP;
}
