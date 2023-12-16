<?php

namespace BrickLayer\Lay\BobDBuilder\Helper\Console\Format;

enum Foreground : string
{
    case bold           = '1';
    case normal         = '0;39';
    case dim            = '2';

    case black          = '0;30';
    case blue           = '0;34';
    case green          = '0;32';
    case cyan           = '0;36';
    case red            = '0;31';
    case purple         = '0;35';
    case brown          = '0;33';
    case light_gray     = '0;37';

    case dark_gray      = '1;30';
    case light_blue     = '1;34';
    case light_green    = '1;32';
    case light_cyan     = '1;36';
    case light_red      = '1;31';
    case light_purple   = '1;35';
    case yellow         = '1;33';
    case white          = '1;37';
}
