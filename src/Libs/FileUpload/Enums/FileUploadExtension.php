<?php

namespace BrickLayer\Lay\Libs\FileUpload\Enums;

enum FileUploadExtension : string
{
    // Docs
    case PDF =          'application/pdf';
    case CSV =          'text/csv';
    case EXCEL =        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case ZIP =          'application/zip';

    // Extra doc extensions
    case EXCEL_OLD =    'application/vnd.ms-excel';
    case ZIP_OLD =      'application/x-zip-compressed';

    // Images
    case PNG = "image/png";
    case JPEG = "image/jpeg";
    case HEIC = "image/heic";
}
