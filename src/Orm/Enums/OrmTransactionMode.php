<?php

namespace BrickLayer\Lay\Orm\Enums;

enum OrmTransactionMode {
    case READ_ONLY;
    case READ_WRITE;
    case CONSISTENT_SNAPSHOT;

}
