<?php

namespace BrickLayer\Lay\Libs\FileUpload\Enums;

use BrickLayer\Lay\Libs\Primitives\Enums\EnumHelper;

enum FileUploadExtension : string
{
    // Docs
    case PDF =          'application/pdf';
    case CSV =          'text/csv';
    case EXCEL =        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case MS_DOCX =      'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    case MS_DOC =       'application/msword';
    case ZIP =          'application/zip';

    // Extra doc extensions
    case EXCEL_OLD =    'application/vnd.ms-excel';
    case ZIP_OLD =      'application/x-zip-compressed';

    // Images
    case PICTURE = "image/*";

    case PNG = "image/png";
    case JPEG = "image/jpeg";
    case HEIC = "image/heic";
    case WEBP = "image/webp";

    use EnumHelper;
}
