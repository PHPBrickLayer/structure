<?php

use BrickLayer\Lay\Core\View\DomainResource;
use BrickLayer\Lay\Core\View\Tags\Script;

Script::new()->src("@js/another.js");
echo DomainResource::plaster()->script;
