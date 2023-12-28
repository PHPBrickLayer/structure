<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Dir;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\File;
use BrickLayer\Lay\BobDBuilder\Cmd\Traits\Symlink\Htaccess;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Libs\CopyDirectory;
use BrickLayer\Lay\Libs\LayCache;
use BrickLayer\Lay\Libs\LayCopyDir;
use BrickLayer\Lay\Libs\LayDate;

class Deploy implements CmdLayout
{
    private EnginePlug $plug;
    private array $tags;
    private string $root;
    private string $duration;

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;
        $this->root = $this->plug->server->root;

        $plug->add_arg($this, ["deploy"], 'deploy', 0, 1, 2, 3);
    }

    public function _spin(): void
    {
        $this->tags = $this->plug->tags;

        if(!isset($this->tags['deploy']))
            return;

        $this->duration = LayDate::date();

        $this->check_dependencies();

        $this->compress_lay();
//        $this->compress_shared_static();
//        $this->compress_static();

        $this->push_with_git();
    }

    public function check_dependencies() : void {
        $this->plug->write_talk("- Checking feature dependencies [*npm, terser & cleancss*]");

        $npm = shell_exec("cd $this->root && npm --version 2>&1");
        $terser = shell_exec("cd $this->root && terser --version 2>&1");
        $cleancss = shell_exec("cd $this->root && cleancss --version 2>&1");

        if(!$npm)
            $this->plug->write_fail(
                "*npm* is not installed on your machine, this feature depends on it \n"
                . "You need to install node and npm on your system to use this feature."
            );

        if(!$terser)
            $this->plug->write_fail(
                "*terser* is not installed on your machine, this feature depends on it. \n"
                . "Please run *npm install* on the root folder of your project to install all the js dependencies"
            );

        if(!$cleancss)
            $this->plug->write_fail(
                "*cleancss* is not installed on your machine, this feature depends on it. \n"
                . "Please run *npm install* on the root folder of your project to install all the js dependencies"
            );

        $this->plug->write_talk("- Check complete, all dependencies exist! moving on...");

    }

    public function compress_lay() : void
    {
        $lay = $this->plug->server->shared . "lay" . DIRECTORY_SEPARATOR;

        $this->plug->write_talk("- Compressing files in *$lay*");

        if(!is_dir($lay)) {
            $this->plug->write_talk("- *$lay* doesnt exist, ignoring...");
            return;
        }

        exec(<<<CMD
            cd $this->root && 
            git add . &&
            git commit -m "$msg" &&
            git pull && git push
            2>&1
        CMD, $output);

    }

    public function push_with_git() : void
    {
        $deploy = $this->tags["deploy"][0];
        $msg = "Updated project " . LayDate::date(format_index: 2);

        if($deploy === "-m" || $deploy === "-g")
            $msg = $this->tags['deploy'][1] ?? $msg;

        $root = $this->root;

        $is_repo = exec(<<<CMD
            cd $root && 
            git config --get remote.origin.url
            2>&1
        CMD);

        if(!$is_repo)
            $this->plug->write_warn("Project does not have git, hence git deployment has been aborted");

        exec(<<<CMD
            cd $root && 
            git add . &&
            git commit -m "$msg" &&
            git pull && git push
            2>&1
        CMD, $output);

        $msg = "";

        foreach ($output as $out) {
            $this->plug->write_talk("- $out", ["silent" => true]);
        }



    }

    public function minify_dir(string $src_dir, string $output_dir, array $opts, array &$error = []): void
    {
        if(!is_dir($src_dir)){
            print "Argument[0] is not a directory; Argument[0] & Argument[1] should be directories\n";
            die;
        }

        $ignore = $opts['ignore'];
        $core_ignore = ["node_modules"];

        $last_file = 'No Change Was Made';
        $changes = 0;
        $duration = LayDate::date();
        $cache = LayCache::new()->cache_file("deploy_cache", invalidate: $opts['no_cache'] ?? false);

        $track_changes = $cache->read("*") ?? [];

        $is_css = fn($file) => strtolower(substr(trim($file),-4)) === ".css";
        $is_js = fn($file) => strtolower(substr(trim($file),-3)) === ".js";

        $GLOBALS['intro']();
        print "### Production Begins [RES]\n";

        new LayCopyDir(
            $src_dir, $output_dir,
            function($file, $src) use ($is_css,$is_js, $cache, &$track_changes) {
                try{
                    $key = $src . DIRECTORY_SEPARATOR . $file;
                    $last_modified = filemtime($key);

                    if(@$track_changes[$key] === $last_modified)
                        return "continue";

                    $track_changes[$key] = $last_modified;

                } catch (\Exception $e){}

                if ($is_css($file) || $is_js($file))
                    return "skip-copy";

                return $file;
            },
            function ($file,$parent_dir,$output_dir) use ($is_css,$is_js,&$last_file,&$error,&$changes) {
                $output = $output_dir . "/" . $file;
                $file = $parent_dir . "/" . $file;
                $return = null;

                $last_file = $file;
                print "\033[2K\r=== Current File: $file";

                if($is_js($file))
                    $return = exec("terser $file -c -m -o $output 2>&1",$current_error);

                if ($is_css($file))
                    $return = exec("cleancss -s -o $output $file 2>&1", $current_error);

                if($return && str_contains($return, "not found")) {
                    $error[] = ["file" => $file, "error" => $return];
                    return "break";
                }

                if(!empty($current_error))
                    $error[] = ["file" => $file, "error" => join("\n", $current_error)];

                $changes++;

                if($return)
                    return "copy";

                return $file;
            },
            skip_if: fn($file) => (
                in_array($file, $core_ignore,true) ||
                in_array($file, $ignore,true) ||
                (function_exists('fnmatch') && fnmatch('.*',$file))
            ),

        );

        $cache->dump($track_changes);

        $duration = LayDate::elapsed($this->duration, append_ago: false);
        $changes = number_format($changes);
        $error_count = count($error);
        print "\033[2K\r=== Last File: $last_file";
        print "\n### Production ENDS ===\n";
        print "### Errors: $error_count\n";
        print "### Total Changes: $changes\n";
        print "### Duration: $duration\n";

        if($error_count > 0) {
            foreach ($error as $i => $e){
                print "\n------------ START ERR $i \t ------------\n";
                print "*File:* " . $e['file'] . " \n";
                print "*Err.:* \n" . $e['error'] . "\n";
                print "------------ END ERR $i \t ------------\n";
            }
        }
    }

}