<?php

namespace BrickLayer\Lay\Libs\Image\Enums;

enum ImageErrorType
{
    case EXCEEDS_FILE_LIMIT;
    case FILE_NOT_SET;
    case TMP_FILE_EMPTY;
}
