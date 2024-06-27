<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Symlink;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;

class LaySymlink {
    use IsSingleton;

    public static function make(string $src, string $dest, SymlinkTypes $type) : void
    {
        $exe = fn() => Exception::new()->use_exception(
            "SymlinkFailed",
            "Could not successfully create a symbolic link. \n"
            . "SRC: $src \n"
            . "DEST: $dest \n"
            . "Kindly confirm if the src exists and the destination's directory exists"
            ,
        );

        if(LayConfig::new()->get_os() == "WINDOWS") {
            // TODO: Study the behaviour of symlink on windows and catch the necessary errors
            exec("mklink " . $type->value . " $dest $src");
            return;
        }

        if(!@symlink($src, $dest))
            $exe();
    }
}