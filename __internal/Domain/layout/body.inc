<?php

use BrickLayer\Lay\core\view\DomainResource;

echo DomainResource::plaster()->body;
?>

<div class="copyright">
    <?= DomainResource::get()->copyright ?>
</div>
