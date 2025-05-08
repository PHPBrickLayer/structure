<?php

namespace BrickLayer\Lay\Libs\FileUpload\Enums;

enum FileUploadErrors
{
    case NO_POST_NAME;
    case EXCEEDS_FILE_LIMIT;
    case FILE_NOT_SET;
    case TMP_FILE_EMPTY;
    case WRONG_FILE_TYPE;
    case BUCKET_UPLOAD_FAILED;
    case FTP_UPLOAD_FAILED;
    case DISK_UPLOAD_FAILED;
    case IMG_CREATION;
    case IMG_COPY_FAILED;
    case IMG_COMPLETION;
    case LIB_IMAGICK_NOT_FOUND;
    case DRY_RUN;
}
