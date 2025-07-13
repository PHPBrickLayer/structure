<?php

namespace BrickLayer\Lay\Libs\FileUpload\Enums;

use BrickLayer\Lay\Libs\Primitives\Enums\EnumHelper;

enum FileUploadStorage
{
    case BUCKET;
    case DISK; // File system
    case FTP;

    use EnumHelper;
}
