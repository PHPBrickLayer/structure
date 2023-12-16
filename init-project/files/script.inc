<?php
global $view;

\BrickLayer\Lay\Core\View\Tags\Script::new()->src("@custom/js/another.js");

echo $view->script;
