<?php

namespace BrickLayer\Lay\Libs\Symlink;

enum SymlinkTypes : string
{
    case HARD = "/H";
    case SOFT = "/D";
    case JUNCTION = "/J";
}
