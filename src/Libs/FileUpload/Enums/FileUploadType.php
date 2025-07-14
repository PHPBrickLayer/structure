<?php

namespace BrickLayer\Lay\Libs\FileUpload\Enums;

use BrickLayer\Lay\Libs\Primitives\Enums\EnumHelper;

enum FileUploadType
{
    case IMG;
    case DOC;
    case VIDEO;

    use EnumHelper;
}
