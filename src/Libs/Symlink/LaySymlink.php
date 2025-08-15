<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Symlink;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\Dir\LayDir;

final class LaySymlink {
    public static bool $has_error = false;

    public static function make(string $src, string $dest, ?SymlinkWindowsType $type = null, bool $catch_error = false) : void
    {
        $src = str_replace(['/', DIRECTORY_SEPARATOR], DIRECTORY_SEPARATOR, $src);
        $dest = str_replace(['/', DIRECTORY_SEPARATOR], DIRECTORY_SEPARATOR, $dest);

        // Make Symlink a relative path
        $root = LayConfig::server_data()->root;

        $src = str_replace($root, "", $src);
        $dest = rtrim(str_replace($root, "", $dest), DIRECTORY_SEPARATOR);
        $dest_trail = explode(DIRECTORY_SEPARATOR, $dest);
        $dest_trail_len = count($dest_trail);
        $d_slash = "";

        foreach ($dest_trail as $i => $dot) {
            if($i == $dest_trail_len - 1)
                break;

            $d_slash .= ".." . DIRECTORY_SEPARATOR;
        }

        $src = $d_slash . $src;

        if(LayConfig::new()->get_os() == "WINDOWS") {
            $type = $type ? $type->value : "";
            $type = is_dir($src) ? SymlinkWindowsType::SOFT->value : $type;

            exec("mklink " . $type . " '$dest' '$src'");
            return;
        }

        self::$has_error = !@symlink($src, $dest);

        if(self::$has_error && !$catch_error)
            LayException::throw(
                "Could not successfully create a symbolic link. \n"
                . "SRC: $src \n"
                . "DEST: $dest \n"
                . "DEST may exist already.\n"
                . "If DEST is a file, confirm if the attached directory exists in the destination"
                ,
                "SymlinkFailed",
            );
    }

    public static function remove(string $link) : void
    {
        LayDir::unlink($link);
    }
}