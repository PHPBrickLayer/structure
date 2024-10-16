<?php

namespace BrickLayer\Lay\Libs\FileUpload\Enums;

enum FileUploadExtension : string
{
    case PDF =          'application/pdf';
    case CSV =          'text/csv';
    case EXCEL =        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case EXCEL_OLD =    'application/vnd.ms-excel';
    case ZIP =          'application/zip';
    case ZIP_OLD =      'application/x-zip-compressed';
}
