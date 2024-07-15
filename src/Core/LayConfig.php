<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core;

use BrickLayer\Lay\Core\Traits\Config;
use BrickLayer\Lay\Core\Traits\Includes;
use BrickLayer\Lay\Core\Traits\Init;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Core\Traits\Resources;

// If you want to measure the run time, just call this constant and subtract the new `microtime(true)` from it
define("LAY_START", microtime(true));

final class LayConfig {
    use IsSingleton;
    use Init;
    use Config;
    use Resources;
    use Includes;
}
