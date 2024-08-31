<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\Enums\CustomContinueBreak;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\Symlink\LaySymlink;
use BrickLayer\Lay\Libs\Symlink\SymlinkTrackType;
use Closure;
use DirectoryIterator;

class LayDir {
    public static bool $result;
    private static LaySymlink $symlink;

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
     * Checks if a file is inside a symlink
     * @param string $file
     * @return bool
     */
    public static function in_link(string $file) : bool
    {
        $server = LayConfig::server_data();

        $root = $server->root;
        $file = str_replace(["/", DIRECTORY_SEPARATOR], DIRECTORY_SEPARATOR, $file);
        $file =  $root . LayFn::ltrim_word($file, $root);

        $ignore = [
            $server->web,
            $server->domains,
            $server->bricks,
        ];

        if(!file_exists($file))
            return false;

        if(is_link($file))
            return true;

        $file_arr = explode(DIRECTORY_SEPARATOR, $file);
        array_pop($file_arr);

        for ($i = 0; $i < count($file_arr); $i ++) {
            $file = implode(DIRECTORY_SEPARATOR, $file_arr);

            if(in_array($file, $ignore))
                return false;

            if(is_link($file))
                return true;

            array_pop($file_arr);
        }

        return false;
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
     * @param string|null $symlink_db_filename
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

        if($use_symlink && empty($symlink_db_filename))
            Exception::throw_exception("You want to use symlink instead of direct copy, but you didn't specify `symlink_db_filename`", "NoSymlinkDB");

        self::make($dest_dir, $permissions, $recursive);

        if($symlink_db_filename)
            self::$symlink = new LaySymlink($symlink_db_filename);

        $has_js_css = false;

        $action = self::read($src_dir, function ($file, $src_dir, DirectoryIterator $handler) use (
            $dest_dir, $permissions, $recursive,
            $skip_if, $pre_copy, $post_copy,
            $use_symlink, $symlink_db_filename,
            &$has_js_css
        ) {
            $s = DIRECTORY_SEPARATOR;

            if (is_callable($skip_if) && $skip_if($file, $src_dir, $dest_dir))
                return CustomContinueBreak::CONTINUE;

            $current_src = $src_dir . $s . $file;
            $current_dest = $dest_dir . $s . $file;

            if ($handler->isDir()) {
                self::copy(
                    $current_src, $current_dest,
                    $pre_copy, $post_copy,
                    $permissions, $recursive,
                    $skip_if,
                    $use_symlink, $symlink_db_filename
                );

                return CustomContinueBreak::FLOW;
            }

            $pre_copy_result = null;

            if(is_callable($pre_copy))
                $pre_copy_result = $pre_copy($file, $src_dir, $dest_dir);

            if ($pre_copy_result == CustomContinueBreak::CONTINUE)
                return CustomContinueBreak::CONTINUE;

            if ($pre_copy_result == CustomContinueBreak::BREAK)
                return CustomContinueBreak::BREAK;

            if($pre_copy_result == "CONTAINS_STATIC")
                $has_js_css = true;

            if($use_symlink and !$has_js_css && !self::in_link($current_dest)) {
                self::unlink($current_dest);
                self::$symlink::make($current_src, $current_dest);
                self::$symlink->track_link( $current_src, $current_dest, SymlinkTrackType::FILE );
            }
            else {

                copy($current_src, $current_dest);
            }

            if(is_callable($post_copy))
                $post_copy($file, $src_dir, $dest_dir);
        });

        if($action == CustomContinueBreak::CONTINUE)
            return;

        if(self::is_empty($dest_dir)) {
            self::unlink($dest_dir);
            return;
        }

        if($has_js_css)
            return;


        if($use_symlink) {
            if(self::in_link($dest_dir))
                return;

            $all_symlinks = true;

            self::read($dest_dir, function ($entry, $src, DirectoryIterator $entry_handler) use (&$all_symlinks) {
                if (!$entry_handler->isLink()) {
                    $all_symlinks = false;
                    return CustomContinueBreak::BREAK;
                }
            }, false);

            if ($all_symlinks) {
                self::unlink($dest_dir);
                self::$symlink::make($src_dir, $dest_dir);
                self::$symlink->track_link($src_dir, $dest_dir, SymlinkTrackType::DIRECTORY);
            }
        }
    }

    /**
     * Make a directory if it doesn't exist. Throws error if application doesn't have permission to access the location
     * @param string $directory
     * @param int $permission
     * @param bool $recursive
     * @param $context
     * @return bool
     * @throws \Exception
     */
    public static function make(
        string $directory,
        int $permission = 0755,
        bool $recursive = false,
               $context = null
    ) : bool
    {
        if(!is_dir($directory)) {
            umask(0);
            if(!@mkdir($directory, $permission, $recursive, $context))
                Exception::throw_exception("Failed to create directory on location: ($directory); access denied; modify permissions and try again", "CouldNotMkDir");
        }

        return true;
    }

    /**
     * @param string $directory
     * @param callable (string, string, DirectoryIterator) : CustomContinueBreak $action
     * @param bool $throw_error
     * @return CustomContinueBreak
     * @throws \Exception
     */
    public static function read(string $directory, callable $action, bool $throw_error = true) : CustomContinueBreak
    {
        if(!is_dir($directory)) {
            if($throw_error)
                Exception::throw_exception(
                    "You are attempting to read a directory [$directory] that doesn't exist!",
                    "DirDoesNotExist"
                );

            return CustomContinueBreak::CONTINUE;
        }

        $dir_handler = new DirectoryIterator($directory);
        $result = CustomContinueBreak::FLOW;

        while ($dir_handler->valid()) {

            if(!$dir_handler->isDot())
                $result = $action($dir_handler->current()->getFilename(), $directory, $dir_handler);

            if($result == CustomContinueBreak::BREAK)
                break;

            $dir_handler->next();
        }

        if($result == CustomContinueBreak::BREAK)
            return CustomContinueBreak::BREAK;

        return CustomContinueBreak::FLOW;
    }

    public static function is_empty(string $directory) : bool
    {
        $empty = true;

        self::read (
            $directory,
            function ($file, $dir, DirectoryIterator $dir_handler) use (&$empty) {
                $empty = false;
                $dir_handler->current();
                return CustomContinueBreak::BREAK;
            },
            false
        );

        return $empty;
    }


}