<?php

namespace BrickLayer\Lay\Libs\Symlink;

enum SymlinkWindowsType : string
{
    case HARD = "/H";
    case SOFT = "/D";
}
