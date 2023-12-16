<?php

namespace BrickLayer\Lay\Orm;

enum QueryStatus : string {
    case success = "Successful";
    case fail = "Failure";
}
