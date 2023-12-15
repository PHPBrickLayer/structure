<?php
// Relocate to the web folder, if for whatever reason,
// Dev doesn't configure the server well and makes here
// the root directory.
// ** It should be noted that using this as your root directory
// is a security risk, and sensitive files like you .env file can be exposed
const SAFE_TO_INIT_LAY = true;
include_once __DIR__ . DIRECTORY_SEPARATOR . "foundation.php";

header("location: " . \BrickLayer\Lay\core\LayConfig::site_data()->domain);