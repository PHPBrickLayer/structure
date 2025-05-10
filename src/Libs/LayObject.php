<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\Primitives\Abstracts\RequestHelper;
use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;

/**
 * @deprecated
 */
final class LayObject
{
    use IsSingleton;

    /**
     * @deprecated
     */
    public function get_json(bool $throw_errors = true, bool $return_array = false, bool $invalidate_cache = false): array|object
    {
        LayException::trigger_depreciation(
            "LayObject::get_json",
            "RequestHelper::request"
        );

        return RequestHelper::request($throw_errors, $return_array, $invalidate_cache);
    }

}
