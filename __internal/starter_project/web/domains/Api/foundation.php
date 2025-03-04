<?php

// Do all the domain-level foundational things here
// You can overwrite the default logo and icon of the project.
// Anything you do here will reflect across all routes on this domain.

use BrickLayer\Lay\Core\LayConfig;

LayConfig::set_cors(
    allowed_origins: [],
    allow_all: false,
    fun: function (){
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Headers: *");
        header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
        header('Access-Control-Max-Age: 86400');    // cache for 1 day
    }
);