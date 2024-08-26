<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\Enums\CustomContinueBreak;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\Symlink\LaySymlink;
use BrickLayer\Lay\Libs\Symlink\SymlinkTrackType;
use Closure;

class LayDir {
    public static bool $result;
    private static string $link_db;

    /**
     * @param string $dir Directory to be deleted
     */
    public static function unlink(string $dir) : void
    {
        $is_windows = LayConfig::get_os() == "WINDOWS";

        if (!is_dir($dir)) {
            self::$result = false;

            if(file_exists($dir) || is_link($dir))
                self::$result = unlink($dir);

            return;
        }

        if(is_link($dir)) {
            if($is_windows && is_dir($dir)) {
                self::$result = rmdir($dir);
                return;
            }

            self::$result = unlink($dir);
            return;
        }

        foreach (scandir($dir) as $object) {
            if ($object == "." || $object == "..")
                continue;

            if (!is_dir($dir . DIRECTORY_SEPARATOR . $object)) {
                unlink($dir. DIRECTORY_SEPARATOR . $object);
                continue;
            }

            self::unlink($dir . DIRECTORY_SEPARATOR . $object);
        }

        self::$result = rmdir($dir);
    }

    /**
     * @param string $src_dir
     * @param string $dest_dir
     * @param Closure|null $pre_copy
     * @param Closure|null $post_copy
     * @param int $permissions
     * @param bool $recursive
     * @param Closure|null $skip_if
     * @param bool $use_symlink Use symbolic link instead of copying files and folders
     * @return void
     * @throws \Exception
     */
    public static function copy(
        string   $src_dir,
        string   $dest_dir,
        ?Closure $pre_copy = null,
        ?Closure $post_copy = null,
        int $permissions = 0777,
        bool $recursive = true,
        ?Closure $skip_if = null,
        bool $use_symlink = false,
        ?string $symlink_db_filename = null,
    ): void
    {
        if (!is_dir($src_dir))
            Exception::throw_exception("Source directory [$src_dir] is not a directory", "InvalidSrcDir");

        if (!is_dir($dest_dir)) {
            umask(0);
            mkdir($dest_dir, $permissions, $recursive);
        }

        $dir = opendir($src_dir);
        $s = DIRECTORY_SEPARATOR;

        if($symlink_db_filename)
            LaySymlink::set_link_db($symlink_db_filename);

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..' || (!is_null($skip_if) && $skip_if($file, $src_dir, $dest_dir)))
                continue;

            if (is_dir("$src_dir{$s}$file")) {
                if($use_symlink) {
                    LaySymlink::make(
                        $src_dir . $s . $file,
                        $dest_dir . $s . $file
                    );

                    LaySymlink::track_link(
                        $src_dir . $s . $file,
                        $dest_dir . $s . $file,
                        SymlinkTrackType::DIRECTORY
                    );

                    continue;
                }

                self::copy(
                    $src_dir . $s . $file,
                    $dest_dir . $s . $file,
                    $pre_copy,
                    $post_copy,
                    $permissions,
                    $recursive,
                    $skip_if,
                    $use_symlink,
                    $symlink_db_filename
                );
                continue;
            }

            $pre_copy_result = null;

            if(is_callable($pre_copy))
                $pre_copy_result = $pre_copy($file, $src_dir, $dest_dir);

            if ($pre_copy_result == CustomContinueBreak::CONTINUE)
                continue;

            if ($pre_copy_result == CustomContinueBreak::BREAK)
                break;

            if(!$use_symlink)
                copy(
                    $src_dir . $s . $file,
                    $dest_dir . $s . $file
                );
            else {
                LaySymlink::make(
                    $src_dir . $s . $file,
                    $dest_dir . $s . $file
                );

                LaySymlink::track_link(
                    $src_dir . $s . $file,
                    $dest_dir . $s . $file,
                    SymlinkTrackType::FILE
                );
            }

            if(is_callable($post_copy))
                $post_copy($file, $src_dir, $dest_dir);
        }

        closedir($dir);
    }


}