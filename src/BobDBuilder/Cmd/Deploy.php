<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\Enums\CustomContinueBreak;
use BrickLayer\Lay\Libs\LayCache;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayDir;
use Exception;

class Deploy implements CmdLayout
{
    private EnginePlug $plug;
    private string $root;
    private ?string $commit_msg;
    private ?string $ignore;
    private ?string $copy_only;
    private string $no_cache;
    private bool $push_git = true;
    private bool $git_only = false;

    private function talk(string $message) : void
    {
        $this->plug->write_talk($message, ['silent' => true]);
    }

    public function _init(EnginePlug $plug): void
    {
        $this->plug = $plug;
        $this->root = $this->plug->server->root;

        $plug->add_arg($this, ["deploy"], 'deploy', true);
    }

    public function _spin(): void
    {
        $tags = $this->plug->tags;

        if(!isset($tags['deploy']))
            return;

        $this->commit_msg = $this->plug->extract_tags(["-m", "-g"], 0)[0] ?? null;
        $this->push_git = $this->plug->extract_tags(["-ng", "--no-git"], false)[0] ?? $this->push_git;
        $this->git_only = $this->plug->extract_tags(["-go", "--git-only"], true)[0] ?? false;

        $ignore = $this->plug->extract_tags(["--ignore"], 0);
        $copy = $this->plug->extract_tags(["--copy-only"], 0);
        $ignore_file = $this->root . "bob.ignore.json";

        if($ignore && $ignore[0] == null)
            $this->plug->write_warn(
                "You added the ignore flag but didn't include the folder or file to ignore.\n"
                . "Example: --ignore 'ckeditor,sass,font.wotff'"
            );

        if($copy && $copy[0] == null)
            $this->plug->write_warn(
                "You added the copy-only flag but didn't include the folder or file to copy only.\n"
                . "Example: --copy-only 'ckeditor,sass,font.wotff'"
            );

        $this->ignore = $ignore[0] ?? null;
        $this->copy_only = $copy[0] ?? null;

        if(file_exists($ignore_file)) {
            $ignore = json_decode(file_get_contents($ignore_file));
            $ignore_file = implode(",", $ignore->ignore);
            $copy_file = implode(",", $ignore->copy_only);

            $this->ignore = $this->ignore ? $this->ignore . "," . $ignore_file : $ignore_file;
            $this->copy_only = $this->copy_only ? $this->copy_only . "," . $copy_file : $copy_file;
        }

        if($this->ignore)
            $this->talk("- Ignoring *$this->ignore*");

        $this->no_cache = $this->plug->extract_tags(["--no-cache", "-nc"], true)[0] ?? false;

        if($this->no_cache)
            $this->talk("- *--no-cache* detected. Entire project will be compressed...");

        $duration = LayDate::date();

        if($this->git_only) {
            $this->talk("- Pushing to git only *--git-only* tag detected");
            $this->push_with_git();
            return;
        }

        $this->check_dependencies();

        $this->compress_lay();
        $this->compress_shared_static();
        $this->compress_static();
        $this->push_with_git();

        $this->talk("- Duration: " . LayDate::elapsed($duration, append_ago: false));
    }

    public function batch_minification(string $src_dir, string $output_dir): void
    {
        $ignore = $this->ignore ? explode(",", $this->ignore) : [];
        $copy_only = $this->copy_only ? explode(",", $this->copy_only) : [];
        $core_ignore = ["node_modules", "scss"];

        $error = [];
        $changes = 0;
        $cache = LayCache::new()->cache_file("deploy_cache", invalidate: $this->no_cache);

        $track_changes = $cache->read("*") ?? [];

        $is_css = fn($file) => strtolower(substr(trim($file),-4, 4)) === ".css";
        $is_js = fn($file) => strtolower(substr(trim($file),-3, 3)) === ".js";

        LayDir::copy(
            $src_dir, $output_dir,

            // Check if the file was modified, else store last modified time
            pre_copy: function($file, $src_dir) use ($is_css, $is_js, $cache, &$track_changes) {
                try{
                    $key = $src_dir . DIRECTORY_SEPARATOR . $file;
                    $last_modified = filemtime($key);

                    if(@$track_changes[$key] == $last_modified)
                        return CustomContinueBreak::CONTINUE;

                    $track_changes[$key] = $last_modified;

                } catch (Exception){}

                return CustomContinueBreak::FLOW;
            },

            // After the file has been copied, work on it if it meets our criteria
            post_copy: function ($file,$parent_dir,$output_dir) use ($is_css, $is_js, &$error, &$changes, $copy_only) {

                // Check if directory matches one that needs to be copied only
                foreach ($copy_only as $copy) {
                    $regex_pattern = preg_quote($copy, '/');
                    $regex_pattern = str_replace('\*', '[^/]*', $regex_pattern);
                    $regex_pattern = '#' . $regex_pattern . '$#';

                    preg_match($regex_pattern, $parent_dir, $match);

                    if($match) {
                        $changes++;
                        return CustomContinueBreak::CONTINUE;
                    }
                }

                // Check if file matches one that needs to be copied only
                if(in_array($file, $copy_only,true)) {
                    $changes++;
                    return CustomContinueBreak::CONTINUE;
                }

                // Start file compression

                $output = $output_dir . DIRECTORY_SEPARATOR . $file;
                $file = $parent_dir . DIRECTORY_SEPARATOR . $file;
                $return = null;

                $this->plug->write_talk("*[x]* Current File: *$file*", [
                    "silent" => true,
                    "maintain_line" => true
                ]);

                // TODO: we can add a condition to optimize if it's an image, reduce the size to a reasonable dimension
                // also we can make the quality of the photo 70

                if($is_js($file))
                    $return = exec("terser $file -c -m -o $output 2>&1 &",$current_error);

                if ($is_css($file))
                    $return = exec("uglifycss $file --output $output 2>&1 &", $current_error);

                if(!empty($current_error))
                    $error[] = ["file" => $file, "error" => join("\n", $current_error)];

                if(!$return)
                    $changes++;

                return $file;
            },

            // Skip file if condition is true
            skip_if: fn($file) => (
                in_array($file, $core_ignore,true) ||
                in_array($file, $ignore,true) ||
                (function_exists('fnmatch') && fnmatch('.*',$file))
            ),

        );

        $cache->dump($track_changes);

        $changes = number_format($changes);
        $error_count = count($error);

        $this->talk(
            " (-) *Changes:* $changes\n"
            . " (-) *Errors:* $error_count"
        );

        if($error_count > 0) {
            $this->talk(" (-) *Error Messages:*");

            foreach ($error as $e) {
                $this->talk(
                    "      File: *{$e['file']}* \n" .
                    "      Error: \n{$e['error']}"
                );
            }

            print "--- --- END --- ---\n";
        }
    }

    public function check_dependencies() : void {
        $this->talk("- Checking feature dependencies [*npm, terser & uglifycss*]");

        $npm = shell_exec("cd $this->root && npm --version 2>&1");
        $terser = shell_exec("cd $this->root && terser --version 2>&1");
        $uglifycss = shell_exec("cd $this->root && uglifycss --version 2>&1");

        if(!$npm || str_contains($npm, "not found"))
            $this->plug->write_fail(
                "*npm* is not installed on your machine, this feature depends on it \n"
                . "You need to install node and npm on your system to use this feature."
            );

        if(!$terser || str_contains($terser, "not found"))
            $this->plug->write_fail(
                "*terser* is not installed on your machine, this feature depends on it. \n"
                . "Please run *npm install* on the root folder of your project to install all the js dependencies"
            );

        if(!$uglifycss || str_contains($uglifycss, "not found"))
            $this->plug->write_fail(
                "*uglifycss* is not installed on your machine, this feature depends on it. \n"
                . "Please run *npm install* on the root folder of your project to install all the js dependencies"
            );

        $this->talk("- Check complete, all dependencies exist! moving on...");

    }

    public function compress_lay() : void
    {
        $lay = $this->plug->server->shared . "lay";

        $this->talk("- Compressing files in *$lay*");

        if(!is_dir($lay)) {
            $this->talk("- *$lay* doesn't exist, ignoring...");
            return;
        }

        $lay .= DIRECTORY_SEPARATOR;

        if($err = shell_exec("terser {$lay}index.js -c -m -o {$lay}index.min.js 2>&1"))
            $this->plug->write_warn($err, ["kill" => false]);

        if($err = shell_exec("terser {$lay}constants.js -c -m -o {$lay}constants.min.js 2>&1"))
            $this->plug->write_warn($err, ["kill" => false]);
    }

    public function compress_shared_static() : void
    {
        $static = $this->plug->server->shared . "static";
        $dev = $static . DIRECTORY_SEPARATOR . "dev";
        $prod = $static . DIRECTORY_SEPARATOR . "prod";

        $this->talk("- Compressing files in *$dev*");

        if(!is_dir($dev)) {
            $this->plug->write_warn("- *$dev* doesn't exist, ignoring...");
            return;
        }

        if($this->no_cache)
            LayDir::unlink($prod);

        $this->batch_minification($dev, $prod);
    }

    public function compress_static() : void
    {
        foreach (scandir($this->plug->server->domains) as $domain) {
            $static = $this->plug->server->domains . $domain . DIRECTORY_SEPARATOR . "static";

            if(is_link($static)) {
                $this->talk("- Symlinked static directory detected: *$dev* skipping directory");
                continue;
            }

            $dev = $static . DIRECTORY_SEPARATOR . "dev";
            $prod = $static . DIRECTORY_SEPARATOR . "prod";

            if($domain == "." || $domain == ".." || !is_dir($dev))
                continue;

            $this->talk("- Compressing files in *$dev*");

            if(!is_dir($dev)) {
                $this->plug->write_warn("- *$dev* doesn't exist, ignoring...");
                return;
            }

            if($this->no_cache)
                LayDir::unlink($prod);

            $this->batch_minification($dev, $prod);
        }
    }

    public function push_with_git() : void
    {
        if(!$this->push_git) {
            $this->talk("- *--no-git* flag detected, so ignoring git push...");
            return;
        }

        $this->talk("- Attempting git deployment");

        $msg = $this->commit_msg ?? "Updated project " . LayDate::date(format_index: 2);

        $root = $this->root;

        $is_repo = exec(<<<CMD
            cd $root && 
            git config --get remote.origin.url 2>&1
            2>&1
        CMD);

        if(!$is_repo)
            $this->plug->write_warn("Project does not have git, hence git deployment has been aborted");

        $branch = shell_exec("git branch --show-current");

        if(str_contains(exec("cd $root | git pull --recurse-submodules 2>&1"), "error: ")) {
            exec(<<<CMD
                git add -A 2>&1 &&
                git commit -m "$msg" 2>&1  
            CMD, $output);

            exec("cd $root | git pull 2>&1", $output);

            $dont_commit = true;
        }

        if(!isset($dont_commit)) {
            exec("git add . 2>&1", $output);
            exec("git commit -m \"$msg\" 2>&1", $output);
        }

        $this->talk(" (-) *Git Says*");

        exec("git push -u origin $branch > /dev/null &", $output);
        exec("cd $root | git add . && git commit -m \"$msg\" && git push --recurse-submodules=on-demand > /dev/null &");

        foreach ($output as $out){
            print "     " . $out . "\n";
        }
    }

}