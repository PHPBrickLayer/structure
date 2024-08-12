<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Symlink;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Libs\LayDir;

class LaySymlink {
    use IsSingleton;

    public static function make(string $src, string $dest, ?SymlinkTypes $type = null) : void
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
            $type = $type ? $type->value : "";
            $type = is_dir($src) ? SymlinkTypes::SOFT->value : $type;

            // TODO: Study the behaviour of symlink on windows and catch the necessary errors
            exec("mklink " . $type . " \"$dest\" \"$src\"");
            return;
        }

        if(!@symlink($src, $dest))
            $exe();
    }

    public static function remove(string $link) : void
    {
        LayDir::unlink($link);
    }
}