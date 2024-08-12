<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\Enums\CustomContinueBreak;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use Closure;

class LayDir {
    public static bool $result;

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

        if(is_link($dir) && ($is_windows && !is_dir($dir))) {
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

    public static function copy(
        string   $src_dir,
        string   $dest_dir,
        ?Closure $pre_copy = null,
        ?Closure $post_copy = null,
        int $permissions = 0777,
        bool $recursive = true,
        ?Closure $skip_if = null
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

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..' || (!is_null($skip_if) && $skip_if($file)))
                continue;

            if (is_dir("$src_dir{$s}$file")) {
                self::copy(
                    $src_dir . $s . $file,
                    $dest_dir . $s . $file,
                    $pre_copy,
                    $post_copy,
                    $permissions,
                    $recursive,
                    $skip_if,
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

            copy(
                $src_dir . $s . $file,
                $dest_dir . $s . $file
            );

            if(is_callable($post_copy))
                $post_copy($file, $src_dir, $dest_dir);
        }

        closedir($dir);
    }
}