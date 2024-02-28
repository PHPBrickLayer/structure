<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Symlink;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;

class LaySymlink {
    use IsSingleton;

    public static function make(string $src, string $dest, SymlinkTypes $type) : void
    {
        if(LayConfig::new()->get_os() == "WINDOWS") {
            exec("mklink " . $type->value . " $dest $src");
            return;
        }

        symlink($src, $dest);
    }
}