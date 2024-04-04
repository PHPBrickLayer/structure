<?php

namespace BrickLayer\Lay\Orm\Enums;

enum OrmQueryType {
    case LAST_INSERTED;
    case SELECT;
    case UPDATE;
    case COUNT;
    case INSERT;
    case DELETE;
}
