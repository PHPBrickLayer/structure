<?php
use BrickLayer\Lay\Core\View\Domain;

const DOMAIN_SET = true;

include_once __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "index.php";

Domain::new()->create(
    id: "default",
    builder: new \web\domains\Default\Plaster(),
);