<?php

namespace BrickLayer\Lay\orm;

enum QueryStatus : string {
    case success = "Successful";
    case fail = "Failure";
}
