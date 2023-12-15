<?php

use BrickLayer\Lay\core\LayConfig;

include_once __DIR__ . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "bricklayer" . DIRECTORY_SEPARATOR . "lay" . DIRECTORY_SEPARATOR . "Autoloader.php";

LayConfig::validate_lay();

LayConfig::session_start([
    "http_only" => true,
    "only_cookies" => true,
    "secure" => true,
    "samesite" => 'None',
]);

LayConfig::set_cors(
    [],
    false,
    function (){
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Headers: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header('Access-Control-Max-Age: 86400');    // cache for 1 day
    }
);

$site_name = "Sample Lay Project";

///// Project Configuration
$layConfig = LayConfig::new();

$GLOBALS['layConfig'] = $layConfig;

$layConfig
    ->dont_use_prod_folder()
    ->init_name($site_name, "$site_name | Slogan Goes Here")
    ->init_color("#082a96", "#0e72e3")
    ->init_mail("EMAIL-1", "EMAIL-2")
    ->init_tel("TEL-1", "TEL-2")
    ->init_others([
        "desc" => "
            This is an awesome project that is about to unfold you just watch and see 😉.
        ",
    ])
    ->init_orm(false);
