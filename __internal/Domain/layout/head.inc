<?php

\BrickLayer\Lay\core\view\tags\Link::new()->rel("stylesheet")->href("@css/style.css");

echo \BrickLayer\Lay\core\view\DomainResource::plaster()->head;
