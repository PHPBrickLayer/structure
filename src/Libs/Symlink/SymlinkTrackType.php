<?php

namespace BrickLayer\Lay\Libs\Symlink;

enum SymlinkTrackType : string
{
    case DIRECTORY = "dir";
    case FILE = "file";
    case API = "api";
    case HTACCESS = "htaccess";
    case SHARED = "shared";
    case UPLOADS = "uploads";
}
