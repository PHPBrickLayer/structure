<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs\Symlink;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Console;
use BrickLayer\Lay\BobDBuilder\Helper\Console\Format\Foreground;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\Dir\LayDir;
use BrickLayer\Lay\Libs\LayFn;

final class LaySymlink {
    private string $json_filename;
    public static bool $has_error = false;

    /**
     * Where Lay should track all the symlinks created by this method
     * @example "static_assets.json"
     * @param string $json_filename
     */
    public function __construct(string $json_filename) {
        $this->json_filename = self::symlink_dir() . LayFn::rtrim_word($json_filename, ".json") . ".json";
    }

    public static function make(string $src, string $dest, ?SymlinkWindowsType $type = null, bool $catch_error = false) : void
    {
        $src = str_replace(['/', DIRECTORY_SEPARATOR], DIRECTORY_SEPARATOR, $src);
        $dest = str_replace(['/', DIRECTORY_SEPARATOR], DIRECTORY_SEPARATOR, $dest);

        if(LayConfig::new()->get_os() == "WINDOWS") {
            $type = $type ? $type->value : "";
            $type = is_dir($src) ? SymlinkWindowsType::SOFT->value : $type;

            exec("mklink " . $type . " '$dest' '$src'");
            return;
        }

        $root = LayConfig::server_data()->root;

        // Make Symlink a relative path for non-windows OS
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
            $links = json_decode(file_get_contents($this->json_filename), true) ?? [];

        foreach ($links as $link) {
            if($new_link['type'] == $link['type'] && $new_link['dest'] == $link['dest'])
                return;
        }

        $links[] = $new_link;

        file_put_contents($this->json_filename, json_encode($links));
    }

    public function refresh_link(bool $recursive = false) : void
    {
        $refresh = function ($db_file): void {
            if(!file_exists($db_file))
                return;

            $links = json_decode(file_get_contents($db_file), true);

            if($links === null)
                LayException::throw("There was an error reading file: $db_file. Please open it and confirm if it's a valid JSON");

            $has_dead_links = false;

            foreach ($links as $link) {
                if(self::$has_error)
                    $has_dead_links = true;

                if(empty($link['src'])) {
                    new BobExec("link:{$link['type']} {$link['dest']} --force --silent --catch");
                    continue;
                }

                new BobExec("link:{$link['type']} {$link['src']} {$link['dest']} --force --silent --catch");
            }

            if($has_dead_links) {
                (new EnginePlug([], false, false))->write_warn("!! Found dead links, attempting to prune !!", [
                    "close_talk" => false,
                    "kill" => false,
                ]);
                $this->prune_link();
            }
        };

        if(!$recursive && empty($this->json_filename))
            LayException::throw("Trying to refresh symlink for a single file but `json_filename` is empty");

        if(!$recursive) {
            $refresh($this->json_filename);
            return;
        }

        LayDir::read(self::symlink_dir(), fn ($file, $src) => $refresh($src . $file));
    }

    public function prune_link(bool $recursive = false) : void
    {
        $prune = function ($db_file): void {
            if(!file_exists($db_file))
                return;

            $links = json_decode(file_get_contents($db_file), true);
            $root = LayConfig::server_data()->root;
            $domains = LayConfig::server_data()->domains;
            $refreshed_links = [];

            Console::log(" [x] Pruning Links for: $db_file", Foreground::light_cyan);

            foreach ($links as $i => $link) {
                $src = $root . $link['src'];
                $dest = $root . $link['dest'];
                $unlinked = false;

                switch ($link['type']) {
                    case "file": case "dir": break;

                    default:
                        $dest = $domains . $link['dest'] . $link['type'];
                        break;

                    case "htaccess":
                        $dest = $domains . $link['dest'] . ".htaccess";
                        break;
                }

                // Remove records of links that don't exist in the file system
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
                    Console::log("   ~ Pruned: $dest", Foreground::white);
                else
                    $refreshed_links[] = $link;
            }

            file_put_contents($db_file, json_encode($refreshed_links));
        };

        if(!$recursive && empty($this->json_filename))
            LayException::throw("Trying to prune symlinks for a single file but `json_filename` was not specified");

        if(!$recursive) {
            $prune($this->json_filename);
            return;
        }

        LayDir::read(self::symlink_dir(), fn ($file, $src) => $prune($src . $file));
    }
}