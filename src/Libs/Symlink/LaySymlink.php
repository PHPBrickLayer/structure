<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Symlink;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayDir;
use BrickLayer\Lay\Libs\LayFn;

class LaySymlink {
    private static string $link_db;

    public static function make(string $src, string $dest, ?SymlinkWindowsType $type = null) : void
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
            $type = is_dir($src) ? SymlinkWindowsType::SOFT->value : $type;

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

    private static function symlink_dir() : string
    {
        $x = LayConfig::server_data()->lay . "symlinks" . DIRECTORY_SEPARATOR;
        LayFn::mkdir($x, 0755, true);

        return $x;
    }

    /**
     * Where Lay should track all the symlinks created by this method
     * @example "static_assets.json"
     * @param string $json_filename
     * @return string file path
     */
    public static function set_link_db(string $json_filename) : string
    {
        return self::$link_db = self::symlink_dir() . LayFn::rtrim_word($json_filename, ".json");
    }

    private static function link_isset() : void
    {
        if(isset(self::$link_db))
            Exception::throw_exception(
                "You did not set the db file to track symlinks. Use `LaySymlink::set_link_db` and 
                put the name of the file you want lay to use for tracking your symbolic links",
                "NoLinkDBFile"
            );
    }

    public static function track_link(string $src, string $dest, SymlinkTrackType $link_type) : void
    {
        self::link_isset();

        // Remove absolute path
        $root = LayConfig::server_data()->root;
        $src = str_replace($root, "", $src);
        $dest = str_replace($root, "", $dest);

        $new_link = [
            "type" => $link_type->value,
            "src" => str_replace(["/","\\"], DIRECTORY_SEPARATOR, $src),
            "dest" => str_replace(["/","\\"], DIRECTORY_SEPARATOR, $dest),
        ];

        $links = [];

        if(file_exists(self::$link_db))
            $links = json_decode(file_get_contents(self::$link_db), true);

        foreach ($links as $link) {
            if($new_link['type'] == $link['type'] && $new_link['dest'] == $link['dest'])
                return;
        }

        $links[] = $new_link;

        file_put_contents(self::$link_db, json_encode($links));
    }

    public static function refresh_link(bool $recursive = false) : void
    {
        $refresh = function ($db_file) {
            if(!file_exists($db_file))
                return;

            $links = json_decode(file_get_contents($db_file), true);

            foreach ($links as $link) {
                if(empty($link['src'])) {
                    new BobExec("link:{$link['type']} {$link['dest']} --force --silent");
                    continue;
                }

                new BobExec("link:{$link['type']} {$link['src']} {$link['dest']} --force --silent");
            }

        };

        if(!$recursive && !isset(self::$link_db))
            Exception::throw_exception("Trying to refresh symlink for a single file but `set_link_db` was not used to specify the symlink file to refresh");

        if(isset(self::$link_db) && !$recursive) {
            $refresh(self::$link_db);
            return;
        }

        LayFn::read_dir(self::symlink_dir(), fn ($file) => $refresh($file));
    }

    public static function prune_link(bool $recursive = false) : void
    {
        $prune = function ($db_file) {

            if(!file_exists($db_file))
                return;

            $links = json_decode(file_get_contents($db_file), true);
            $root = LayConfig::server_data()->root;
            $domains = LayConfig::server_data()->domains;

            foreach ($links as $i => $link) {
                $src = $root . $link['src'];
                $dest = $root . $link['dest'];

                if($link['type'] == "htaccess")
                    $dest = $domains . $link['dest'] . ".htaccess";

                if(!is_link($dest))
                    unset($links[$i]);

                if(!is_file($src) and !is_dir($src)) {
                    unset($links[$i]);

                    if (is_link($dest))
                        LayDir::unlink($dest);
                }
            }

            file_put_contents(self::$link_db, json_encode($links));
        };

        if(!$recursive && !isset(self::$link_db))
            Exception::throw_exception("Trying to prune symlink for a single file but `set_link_db` was not used to specify the symlink file to prune");

        if(isset(self::$link_db) and !$recursive) {
            $prune(self::$link_db);
            return;
        }

        LayFn::read_dir(self::symlink_dir(), fn ($file) => $prune($file));
    }
}