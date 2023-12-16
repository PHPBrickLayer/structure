<?php

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\Enums\CustomContinueBreak;
use BrickLayer\Lay\Core\Exception;
use Closure;

class LayCopyDir
{
    public function __construct(
        string   $src_dir,
        string   $dest_dir,
        ?Closure $pre_copy = null,
        ?Closure $post_copy = null,
        int $permissions = 0777,
        bool $recursive = true
    )
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
            if ($file === '.' || $file === '..')
                continue;

            if (is_dir("$src_dir{$s}$file")) {
                $this->__construct(
                    $src_dir . $s . $file,
                    $dest_dir . $s . $file,
                    $pre_copy,
                    $post_copy,
                    $permissions,
                    $recursive,
                );
                continue;
            }

            $pre_copy = !is_null($pre_copy) ? $pre_copy($file, $src_dir, $dest_dir) : null;

            if ($pre_copy == CustomContinueBreak::CONTINUE)
                continue;

            if ($pre_copy == CustomContinueBreak::BREAK)
                break;

            copy(
                $src_dir . $s . $file,
                $dest_dir . $s . $file
            );

            if(!is_null($post_copy))
                $post_copy($file, $src_dir, $dest_dir);
        }

        closedir($dir);
    }
}