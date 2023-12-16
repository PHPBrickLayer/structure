<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Core;

use BrickLayer\Lay\Core\Traits\Config;
use BrickLayer\Lay\Core\Traits\Includes;
use BrickLayer\Lay\Core\Traits\Init;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Core\Traits\Resources;

final class LayConfig {
    use IsSingleton;
    use Init;
    use Config;
    use Resources;
    use Includes;
}
