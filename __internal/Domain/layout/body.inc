<?php

use BrickLayer\Lay\Core\View\DomainResource;

echo DomainResource::plaster()->body;
?>

<div class="copyright">
    <?= DomainResource::get()->copyright ?>
</div>
