<?php

namespace BrickLayer\Lay\Orm\Enums;

enum OrmReturnType {
    case ASSOC;
    case NUM;
    case BOTH;
    case EXECUTION;
    case RESULT;
    case GENERATOR;
}
