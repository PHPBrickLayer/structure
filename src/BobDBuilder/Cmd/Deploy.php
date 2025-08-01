<?php

namespace BrickLayer\Lay\BobDBuilder\Cmd;

use BrickLayer\Lay\BobDBuilder\BobExec;
use BrickLayer\Lay\BobDBuilder\EnginePlug;
use BrickLayer\Lay\BobDBuilder\Interface\CmdLayout;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\Dir\LayDir;
use BrickLayer\Lay\Libs\LayArray;
use BrickLayer\Lay\Libs\LayCache;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;
use BrickLayer\Lay\Libs\Symlink\LaySymlink;
use DirectoryIterator;
use Exception;


final class Deploy implements CmdLayout
{
    private EnginePlug $plug;
    private string $root;
    private ?string $commit_msg;
    private object $config;
    private ?string $ignore;
    private ?string $copy_only;
    private string $no_cache;
    private bool $push_git = true;

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
        $git_only = $this->plug->extract_tags(["-go", "--git-only"], true)[0] ?? false;

        $ignore = $this->plug->extract_tags(["--ignore"], 0);
        $copy = $this->plug->extract_tags(["--copy-only"], 0);
        $ignore_file = $this->root . "bob.config.json";

        if(file_exists($this->root . "bob.ignore.json"))
            $this->plug->write_fail(
                "You are using the old bob config file *bob.ignore.json*.\n"
                . "Run: php bob make:config for the new bob config file or delete this old config file to suppress this error",
                [ "kill" => true ]
            );

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
            $ignore_file = isset($ignore->ignore) ? implode(",", $ignore->ignore) : "";
            $copy_file = isset($ignore->copy_only) ? implode(",", $ignore->copy_only): "";

            $this->config = $ignore;
            $this->ignore = $this->ignore ? $this->ignore . "," . $ignore_file : $ignore_file;
            $this->copy_only = $this->copy_only ? $this->copy_only . "," . $copy_file : $copy_file;
        }

        if($this->ignore)
            $this->talk("- Ignoring *$this->ignore*");

        if(!$this->push_git)
            $this->talk("- *--no-git* flag detected, so git will be ignored");

        $this->no_cache = $this->plug->extract_tags(["--no-cache", "-nc"], true)[0] ?? false;

        if($this->no_cache) {
            $this->talk("- *--no-cache* detected. Entire project will be compressed...");
            new BobExec("purge:static_prod --silent");
            new BobExec("link:prune --silent");
            new BobExec("link:refresh --silent");
        }

        if($git_only) {
            $this->talk("- Pushing to git only *--git-only* tag detected");
            $this->push_with_git();
            return;
        }

        $this->check_dependencies();

        $this->compress_lay();
        $this->compress_shared_static();
        $this->compress_static();

        (new LaySymlink(""))->prune_link(true);

        $this->push_with_git();
    }

    public function batch_minification(string $src_dir, string $output_dir, ?string $domain = null): void
    {
        $domain_root = LayConfig::server_data()->domains . $domain . DIRECTORY_SEPARATOR;

        $copy_only = [];

        $ignore_pattern = ["node_modules", "scss", ".DS_Store"];
        $ignore_path = [];

        $purge_lookup_ext = ["php", "view", "inc", "html", "js"];
        $purge_lookup_ext_user = [];
        $whitelist = "";
        $lookup = "";

        if($domain) {
            foreach ($purge_lookup_ext as $ext) {
                $lookup .= " --content '{$domain_root}**/*.$ext'";
            }
        }

        if(isset($this->config)) {
            $root = LayConfig::server_data()->root;

            foreach($this->config->purge_config ?? [] as $dm => $list) {
                if($dm != $domain) continue;

                $purge_lookup_ext_user = array_unique([...$purge_lookup_ext, ...($list->lookup_ext ?? [])]);

                foreach ($list->lookup_src ?? [] as $src) {
                    foreach ($purge_lookup_ext_user as $ext) {
                        $lookup .= " --content '" . $root . trim($src, "/") . "/**/*.$ext'";
                    }
                }

                if(isset($list->ignore->path))
                    $ignore_path = [...$ignore_path, ...$list->ignore->path];

                if(isset($list->ignore->pattern))
                    $ignore_pattern = [...$ignore_pattern, ...$list->ignore->pattern];

                foreach($list->css_whitelist ?? [] as $white) {
                    $whitelist .= "'$white' ";
                }
            }

            if (!empty($whitelist))
                $whitelist = "--safelist $whitelist";
        }

        $gen_regex = function ($entry) : string {
            $regex_pattern = preg_quote($entry, '/');
            $regex_pattern = str_replace('\/\*', '(?:\/[^\/]*)*', $regex_pattern);
            return '#' . $regex_pattern . '$#';
        };

        $error = [];
        $changes = 0;
        $cache = LayCache::new()->cache_file("deploy_cache", invalidate: $this->no_cache);

        $track_changes = $cache->read("*") ?? [];

        $is_css = fn($file): bool => strtolower(substr(trim($file),-4, 4)) === ".css";
        $is_js = fn($file): bool => strtolower(substr(trim($file),-3, 3)) === ".js";

        LayDir::copy(
            $src_dir, $output_dir,

            // Check if the file was modified, else store last modified time
            // Also check if current file is a css or js file and tell LayDir to copy or symlink
            pre_copy: function($file, $src_dir) use ($is_css, $is_js, &$track_changes) {
                try{
                    $key = $src_dir . DIRECTORY_SEPARATOR . $file;
                    $last_modified = filemtime($key);

                    if(@$track_changes[$key] == $last_modified)
                        return LayLoop::CONTINUE;

                    $track_changes[$key] = $last_modified;

                    if( $is_css($file) || $is_js($file) )
                        return "CONTAINS_STATIC";

                } catch (Exception){}

                return LayLoop::FLOW;
            },

            // After the file has been copied, work on it if it meets our criteria
            post_copy: function ($file,$parent_dir,$output_dir) use ($lookup, $whitelist, $domain, $domain_root, $is_css, $is_js, &$error, &$changes, $copy_only, $gen_regex) {

                // Check if directory matches one that needs to be copied only
                foreach ($copy_only as $copy) {
                    preg_match($gen_regex($copy), $parent_dir . DIRECTORY_SEPARATOR . $file, $match);

                    if($match) {
                        $changes++;
                        return LayLoop::BREAK;
                    }
                }

                // Check if file matches one that needs to be copied only
                if(in_array($file, $copy_only,true)) {
                    $changes++;
                    return LayLoop::CONTINUE;
                }

                // Start file compression

                $output = $output_dir . DIRECTORY_SEPARATOR . $file;
                $file = $parent_dir . DIRECTORY_SEPARATOR . $file;
                $return = null;

                $this->plug->write_talk("*[x]* Current File: *$file*", [
                    "silent" => true,
                    "maintain_line" => true
                ]);

                //TODO: we can add a condition to optimize if it's an image, reduce the size to a reasonable dimension
                // also we can make the quality of the photo 70

                if($is_js($file)) {
                    if($domain) {
                        $return = exec("esbuild '$file' --bundle --format=esm --minify --tree-shaking=true --outfile=$output 2>&1 &", $current_error);

                        if(!$return) $file = $output;
                    }

                    $return = exec("terser '$file' --compress dead_code=true,unused=true,passes=2  -m -o '$output' 2>&1 &", $current_error);
                }

                if ($is_css($file)) {
                    if($domain) {
                        $return = exec("purgecss --css $file $lookup --output $output_dir/ $whitelist --allow-overwrite 2>&1 &", $current_error);


                        if(!$return) $file = $output;
                    }

                    $return = exec("esbuild '$file' --minify --outfile=$output 2>&1 &", $current_error);
                }

                if(!empty($current_error))
                    $error[] = ["file" => $file, "error" => join("\n", $current_error)];

                if(!$return)
                    $changes++;

                return $file;
            },

            // Skip file if condition is true
            skip_if: function($file, $parent_dir) use ($ignore_pattern, $ignore_path, $domain_root, $gen_regex) {
                $skip = false;

                foreach ($ignore_pattern as $entry) {
                    preg_match($gen_regex($entry), $parent_dir . DIRECTORY_SEPARATOR . $file, $match);

                    if ($match) {
                        $skip = true;
                        break;
                    }
                }

                if(!$skip) {
                    foreach ($ignore_path as $entry) {
                        if (($parent_dir . DIRECTORY_SEPARATOR . $file) === ($domain_root . $entry)) {
                            $skip = true;
                            break;
                        }
                    }
                }

                return $skip;
            },

            use_symlink: true,

            symlink_db_filename: "static_assets.json"

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
        $this->talk("- Checking feature dependencies [*npm, terser, esbuild & purgecss*]");

        $npm = shell_exec("cd $this->root && npm --version 2>&1");
        $terser = shell_exec("cd $this->root && terser --version 2>&1");
        $esbuild = shell_exec("cd $this->root && esbuild --version 2>&1");
        $purge_css = shell_exec("cd $this->root && purgecss --version 2>&1");

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

        if(!$esbuild || str_contains($esbuild, "not found"))
            $this->plug->write_fail(
                "*esbuild* is not installed on your machine, this feature depends on it. \n"
                . "Please run *npm install* on the root folder of your project to install all the js dependencies"
            );

        if(!$purge_css || str_contains($purge_css, "not found"))
            $this->plug->write_fail(
                "*purgecss* is not installed on your machine, this feature depends on it. \n"
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
        LayDir::read($this->plug->server->domains, function (string $domain, string $src, DirectoryIterator $handler, array $obj) {
            $static = $src . $domain . DIRECTORY_SEPARATOR . "static";

            $dev = $static . DIRECTORY_SEPARATOR . "dev";
            $prod = $static . DIRECTORY_SEPARATOR . "prod";

            if($handler->isLink()) {
                $this->talk("- Symlinked static directory detected: *$dev* skipping directory");
                return;
            }

            if(!is_dir($dev))
                return;

            $this->talk("- Compressing files in *$dev*");

            if(!is_dir($dev)) {
                $this->plug->write_warn("- *$dev* doesn't exist, ignoring...");
                return;
            }

            if($this->no_cache)
                LayDir::unlink($prod);

            $this->batch_minification($dev, $prod, $domain);
        });
    }

    public function push_with_git() : void
    {
        if(!$this->push_git)
            return;

        $this->talk("- Attempting git deployment");

        $msg = $this->commit_msg ?? "Updated project " . LayDate::date(format_index: 2);

        $root = $this->root;

        $is_repo = exec(<<<CMD
            cd '$root' && 
            git config --get remote.origin.url 2>&1
            2>&1
        CMD);

        if(!$is_repo)
            $this->plug->write_warn("Project does not have git, hence git deployment has been aborted");

        $branch = shell_exec("git branch --show-current");

        $msg = str_replace("'", "\'", $msg);

        exec(<<<CMD
            git add -A 2>&1 &&
            git commit -m '$msg' 2>&1  
        CMD, $output);

        if(str_contains(exec("cd '$root' | git pull | git submodule update --remote --merge 2>&1"), "error: ")) {
            exec(<<<CMD
                git add -A 2>&1 &&
                git commit -m '$msg' 2>&1  
            CMD, $output);

            exec("cd '$root' | git pull 2>&1", $output);
        }

        $this->talk(" (-) *Git Says*");

        exec("git push -u origin $branch > /dev/null &", $output);
        exec("cd '$root' | git add . && git commit -m '$msg' && git push --recurse-submodules=on-demand > /dev/null &");

        foreach ($output as $out){
            print "     " . $out . "\n";
        }
    }

}