<?php

use BrickLayer\Lay\Core\View\DomainResource;
use BrickLayer\Lay\Core\View\Tags\Link;

Link::new()->rel("stylesheet")->href("@css/style.css");

echo DomainResource::plaster()->head;
