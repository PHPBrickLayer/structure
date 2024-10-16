<?php

namespace BrickLayer\Lay\Libs\FileUpload\Enums;

enum FileUploadStorage
{
    case BUCKET;
    case DISK; // File system
    case FTP;
}
