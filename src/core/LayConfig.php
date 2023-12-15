<?php
declare(strict_types=1);
namespace BrickLayer\Lay\core;

use BrickLayer\Lay\core\traits\Config;
use BrickLayer\Lay\core\traits\Includes;
use BrickLayer\Lay\core\traits\Init;
use BrickLayer\Lay\core\traits\IsSingleton;
use BrickLayer\Lay\core\traits\Resources;

final class LayConfig {
    use IsSingleton;
    use Init;
    use Config;
    use Resources;
    use Includes;
}
