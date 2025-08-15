<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Dir;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\Dir\Enums\SortOrder;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;
use BrickLayer\Lay\Libs\Symlink\LaySymlink;
use BrickLayer\Lay\Libs\Symlink\SymlinkTrackType;
use Closure;
use DirectoryIterator;

final class LayDir {
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
                return LayLoop::CONTINUE;

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

                return LayLoop::FLOW;
            }

            $pre_copy_result = null;

            if(is_callable($pre_copy))
                $pre_copy_result = $pre_copy($file, $src_dir, $dest_dir);

            if ($pre_copy_result == LayLoop::CONTINUE)
                return LayLoop::CONTINUE;

            if ($pre_copy_result == LayLoop::BREAK)
                return LayLoop::BREAK;

            if($pre_copy_result == "CONTAINS_STATIC")
                $has_js_css = true;

            if($use_symlink and !$has_js_css && !self::in_link($current_dest)) {
                self::unlink($current_dest);
                self::$symlink::make($current_src, $current_dest);
            }
            else {

                copy($current_src, $current_dest);
            }

            if(is_callable($post_copy))
                $post_copy($file, $src_dir, $dest_dir);
        });

        if($action == LayLoop::CONTINUE)
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
                    return LayLoop::BREAK;
                }
            }, false);

            if ($all_symlinks) {
                self::unlink($dest_dir);
                self::$symlink::make($src_dir, $dest_dir);
            }
        }
    }

    /**
     * Make a directory if it doesn't exist. Throws error if application doesn't have permission to access the location
     *
     * @param string $directory
     * @param int $permission
     * @param bool $recursive
     * @param $context
     *
     * @return true
     *
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
     * Get a list of files in a directory and carryout an operation on each entry
     * @param string $directory
     * @param null|Closure (string $file_name, string $directory, DirectoryIterator $dir_handler, array{
     *      file: string,
     *      full_path: string,
     *      dir: string,
     *      current: DirectoryIterator|mixed,
     *      index: int,
     *      mt: int,
     *      size: int,
     *  } $entry_obj) : LayLoop $action
     *
     * @param bool $throw_error
     * @param SortOrder|callable $sort
     * @return null|array<int, array{
     *     file: string,
     *     full_path: string,
     *     dir: string,
     *     mt: int,
     *     size: int,
     *     index: int,
     * }>
     * @throws \Exception
     */
    public static function read(
        string $directory,
        ?Closure $action = null,
        bool $throw_error = true,
        SortOrder|callable $sort = SortOrder::DEFAULT
    ) : ?array
    {
        if(!is_dir($directory)) {
            if($throw_error)
                Exception::throw_exception(
                    "You are attempting to read a directory [$directory] that doesn't exist!",
                    "DirDoesNotExist"
                );

            return null;
        }

        $entries = [];
        $entry = new DirectoryIterator($directory);
        $empty = true;

        $i = 0;
        while ($entry->valid()) {
            if(!$entry->isDot()) {
                $empty = false;
                $current = $entry->current();

                $entry_obj = [
                    "file" => $current->getFilename(),
                    "dir" => $directory,
                    "full_path" => $current->getPath() . DIRECTORY_SEPARATOR . $current->getFilename(),
                    "mt" => $current->getMTime(),
                    "size" => $current->getSize(),
                    "index" => $i++,
                    "current" => $current,
                ];

                $entries[] = $entry_obj;

                if($sort == SortOrder::DEFAULT && $action) {
                    $result = $action($current->getFilename(), $directory, $entry, $entry_obj);

                    if ($result == LayLoop::BREAK)
                        break;
                }
            }

            $entry->next();
        }

        if($empty) return null;

        switch ($sort) {
            default: usort($entries, $sort); break;

            case SortOrder::DEFAULT: break;

            case SortOrder::NAME_ASC:
                usort($entries, function (array $a, array $b){
                    return strcmp($a['file'], $b['file']);
                });
                break;

            case SortOrder::NAME_DESC:
                usort($entries, function (array $a, array $b){
                    return strcmp($b['file'], $a['file']);
                });
                break;

            case SortOrder::TIME_ASC:
                usort($entries, function (array $a, array $b){
                    return $a['mt'] - $b['mt'];
                });
                break;

            case SortOrder::TIME_DESC:
                usort($entries, function (array $a, array $b){
                    return $b['mt'] - $a['mt'];
                });
                break;

            case SortOrder::SIZE_ASC:
                usort($entries, function (array $a, array $b){
                    return $a['size'] - $b['size'];
                });
                break;

            case SortOrder::SIZE_DESC:
                usort($entries, function (array $a, array $b){
                    return $b['size'] - $a['size'];
                });
                break;

        }

        if($sort != SortOrder::DEFAULT) {
            foreach ($entries as $i => $entry) {
                if (!$action)  break;

                $entry['index'] = $i;

                $result = $action($entry['file'], $entry['dir'], $entry['current'], $entry);

                if ($result == LayLoop::BREAK)
                    break;
            }
        }

        return $entries;
    }

    public static function is_empty(string $directory) : bool
    {
        $empty = true;

        self::read (
            $directory,
            function ($file, $dir, DirectoryIterator $dir_handler) use (&$empty) {
                $empty = false;
                $dir_handler->current();
                return LayLoop::BREAK;
            },
            false,
        );

        return $empty;
    }

}