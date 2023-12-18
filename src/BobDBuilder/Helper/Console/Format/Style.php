<?php

namespace BrickLayer\Lay\BobDBuilder\Helper\Console\Format;

enum Style : string
{
    case bold           = '1';
    case normal         = '0;39';
    case dim            = '2';

    case underline  = '4';
    case reverse    = '7';
    case blink      = '5';
    case hidden     = '8';
}
