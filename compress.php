<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . "AutoLoader.php";

use BrickLayer\Lay\libs\CopyDirectory;
use BrickLayer\Lay\libs\LayCache;
use BrickLayer\Lay\libs\LayDate;

$intro = function() {
    print "----------------------------------------------------------\n";
    print "-- Name:     \t  OsaiMinifier                             \n";
    print "-- Version:  \t  1.5                                      \n";
    print "-- Author:   \t  Osahenrumwen Aigbogun                    \n";
    print "-- Created:  \t  21/10/2021;                              \n";
    print "-- Dependencies:\n \tTerser (JS);\n \tclean-css (CSS)     \n";
    print "----------------------------------------------------------\n";

    // check dependencies
    $npm = shell_exec("npm -v 2>&1");
    $js = shell_exec("terser --version 2>&1");
    $css = shell_exec("cleancss --version 2>&1");

    if(!$npm || !$js || !$css) {
        print "Some of the dependencies have not been installed, please install all of them to continue with this script.\n
        Dependencies that exist:
        \n[npm: $npm]
        \n[terser: $js]
        \n[cleancss(clean-css): $css]
        \n";
        die;
    }
};

$args = $argv;
$script_name = "compress.php";

$opts = [];
$get_arg = function (string $arg,  int $index, &$pipe, string ...$cmd_key) use ($args) : void
{
    if(!in_array($arg, $cmd_key, true))
        return;

    if($index == 0) {
        $pipe = true;
        return;
    }

    $pipe = $args[($index + 1)] ?? null;
};

if($argc == 1) {
    $intro();
    die;
}

foreach ($args as $k => $a) {
    $get_arg($a, $k, $opts['output_dir'], '-o', "--output");
    $get_arg($a, $k, $opts['ignore'], '-i', "--ignore");
    $get_arg($a, 0, $opts['no_cache'], '-nc', "--no-cache");
    $get_arg($a, 0, $opts['help'], '-h', "--help");
}

if($opts['help']) {
    $intro();
    print ">>> This is a batch minifier for JS/CSS, that takes a directory as an input and provides the respected output in the\n";
    print ">>> directory indicated using the -o flag. This helps save production time\n";
    print "----------------------------------------------------------\n";
    print "### Usage: [$script_name] {directory_name} [--output || -o] {output_directory}\n";
    print "### Example: php $script_name dir/js -o prod-dir/js\n";
    die;
}

$src_dir = $args[1];

if(empty($opts['output_dir'])) {
    print "No output directory was specified, process aborted! use --help for more info\n";
    die;
}

$opts['ignore'] = $opts['ignore'] ? explode(",", $opts['ignore']) : [];

minify_dir($src_dir, $opts['output_dir'], $opts);

function minify_dir(string $src_dir, string $output_dir, array $opts, array &$error = []): void
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

    new CopyDirectory(
        $src_dir, $output_dir,
        fn($file) => in_array($file,$core_ignore,true) || in_array($file,$ignore,true) || (function_exists('fnmatch') && fnmatch('.*',$file)),
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
        }
    );

    $cache->dump($track_changes);

    $duration = LayDate::elapsed($duration, append_ago: false);
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
