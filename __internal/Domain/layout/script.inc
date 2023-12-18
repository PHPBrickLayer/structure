<?php
\BrickLayer\Lay\core\view\tags\Script::new()->src("@js/another.js");
echo \BrickLayer\Lay\Core\View\DomainResource::plaster()->script;
