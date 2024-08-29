<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Symlink;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Console;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Foreground;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayDir;
use BrickLayer\Lay\Libs\LayFn;

class LaySymlink {
    private string $json_filename;

    /**
     * Where Lay should track all the symlinks created by this method
     * @example "static_assets.json"
     * @param string $json_filename
     */
    public function __construct(string $json_filename) {
        $this->json_filename = self::symlink_dir() . LayFn::rtrim_word($json_filename, ".json") . ".json";
    }

    public static function make(string $src, string $dest, ?SymlinkWindowsType $type = null) : void
    {
        $src = str_replace(['/', DIRECTORY_SEPARATOR], DIRECTORY_SEPARATOR, $src);
        $dest = str_replace(['/', DIRECTORY_SEPARATOR], DIRECTORY_SEPARATOR, $dest);

        if(LayConfig::new()->get_os() == "WINDOWS") {
            $type = $type ? $type->value : "";
            $type = is_dir($src) ? SymlinkWindowsType::SOFT->value : $type;

            // TODO: Study the behaviour of symlink on windows and catch the necessary errors
            exec("mklink " . $type . " \"$dest\" \"$src\"");
            return;
        }

        if(!@symlink($src, $dest))
            Exception::new()->use_exception(
                "SymlinkFailed",
                "Could not successfully create a symbolic link. \n"
                . "SRC: $src \n"
                . "DEST: $dest \n"
                . "DEST may exist already.\n"
                . "If DEST is a file, confirm if the attached directory exists in the destination"
                ,
            );
    }

    public static function remove(string $link) : void
    {
        LayDir::unlink($link);
    }

    private static function symlink_dir() : string
    {
        $x = LayConfig::server_data()->lay . "symlinks" . DIRECTORY_SEPARATOR;
        LayDir::make($x, 0755, true);

        return $x;
    }

    public function current_db() : string
    {
        return $this->json_filename;
    }

    public function track_link(string $src, string $dest, SymlinkTrackType $link_type) : void
    {
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

        if(file_exists($this->json_filename))
            $links = json_decode(file_get_contents($this->json_filename), true);

        foreach ($links as $link) {
            if($new_link['type'] == $link['type'] && $new_link['dest'] == $link['dest'])
                return;
        }

        $links[] = $new_link;

        file_put_contents($this->json_filename, json_encode($links));
    }

    public function refresh_link(bool $recursive = false) : void
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

        if(!$recursive && empty($this->json_filename))
            Exception::throw_exception("Trying to refresh symlink for a single file but `json_filename` is empty");

        if(!$recursive) {
            $refresh($this->json_filename);
            return;
        }

        LayDir::read(self::symlink_dir(), fn ($file, $src) => $refresh($src . $file));
    }

    public function prune_link(bool $recursive = false) : void
    {
        $prune = function ($db_file) {
            if(!file_exists($db_file))
                return;

            $links = json_decode(file_get_contents($db_file), true);
            $root = LayConfig::server_data()->root;
            $domains = LayConfig::server_data()->domains;

            Console::log(" [x] Pruning Links for: $db_file", Foreground::light_cyan);

            foreach ($links as $i => $link) {
                $src = $root . $link['src'];
                $dest = $root . $link['dest'];
                $unlinked = false;

                if($link['type'] == "htaccess")
                    $dest = $domains . $link['dest'] . ".htaccess";

                if(!is_link($dest)) {
                    unset($links[$i]);
                    $unlinked = true;
                }

                if(!is_file($src) and !is_dir($src)) {
                    unset($links[$i]);

                    if (is_link($dest))
                        LayDir::unlink($dest);

                    $unlinked = true;
                }

                if($unlinked)
                    Console::log("   - Current File: $dest", Foreground::light_purple, newline: false, maintain_line: true);
            }

            file_put_contents($db_file, json_encode($links));
        };

        if(!$recursive && empty($this->json_filename))
            Exception::throw_exception("Trying to prune symlinks for a single file but `json_filename` was not specified");

        if(!$recursive) {
            $prune($this->json_filename);
            return;
        }

        LayDir::read(self::symlink_dir(), fn ($file, $src) => $prune($src . $file));
    }
}