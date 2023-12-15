#!/usr/bin/env php
<?php

use BrickLayer\Lay\core\LayConfig;
const SAFE_TO_INIT_LAY = true;
$s = DIRECTORY_SEPARATOR;

include_once __DIR__ . DIRECTORY_SEPARATOR . "Autoloader.php";

$intro = function() {
    cmd_out(
        "----------------------------------------------------------\n"
        . "-- Name:     \t  Bob The Builder                          \n"
        . "-- Author:   \t  Osahenrumwen Aigbogun                    \n"
        . "-- Created:  \t  14/12/2023;                              \n"
        . "----------------------------------------------------------"
    );
};

$script_name = "./bob";

function get_arg(string $arg,  int|array $index, &$pipe, string ...$cmd_key) : void
{
    global $argv;

    if(!in_array($arg, $cmd_key, true))
        return;

    if(is_int($index) && $index == 0) {
        $pipe = true;
        return;
    }

    if(is_int($index)) {
        $pipe = $argv[($index + 1)] ?? null;
        return;
    }

    foreach ($index as $i) {
        $pipe[] = $argv[($i + 1)] ?? null;
    }
};

function cmd_out(string $message, array $opts = []) : void
{
    print "##>>> BobTheBuilder SAYS (::--__--::)\n\n";

    foreach (explode("\n", $message) as $m) {
        print "    " . $m . "\n";
    }

    print "\n####> BobTheBuilder DONE TALKING...(-_-)\n";
    die;
};


if($argc == 1) {
    $intro();
    die;
}

$opts = [];
foreach ($argv as $k => $a) {
    get_arg($a, $k, $opts['link_htaccess'], "link:htaccess");
    get_arg($a, [$k, $k + 1], $opts['link_dir'], "link:dir");
    get_arg($a, [$k, $k + 1], $opts['link_file'], "link:file");
    get_arg($a, 0, $opts['force_action'], "--force");
}

if(@$opts['help']) {
    $intro();
    cmd_out(
        ">>> Bob The Builder is meant aid in building your application\n" .
        ">>> directory indicated using the -o flag. This helps save production time\n" .
        "----------------------------------------------------------\n" .
        "### Usage: [$script_name] {directory_name} [--output || -o] {output_directory}\n" .
        "### Example: php $script_name dir/js -o prod-dir/js"
        , ["type" => "info"]
    );
}

$fs = LayConfig::server_data();
$s = DIRECTORY_SEPARATOR;
$force = $opts['force_action'] ?? false;

if($dest = @$opts['link_htaccess']) {
    $dest = $fs->domains . rtrim(str_replace(".htaccess","", $dest), "/") . $s;

    if(!is_dir($dest)) {
        if(!$force)
            cmd_out("Directory $dest does not exist! if you want the directory to be created automatically, pass the flag --force", [
                "type" => "fail"
            ]);

        umask(0);
        mkdir($dest, 0777, true);
    }

    $dest .=  ".htaccess";

    if(file_exists($dest)) {
        if(!$force)
            cmd_out(
                "htaccess exists already at: $dest"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note:: You will be deleting the former htaccess if you decide to pass the flag --force"
                , ["type" => "warn"]
            );

        unlink($dest);
    }

    symlink($fs->web . ".htaccess", $dest);

    cmd_out("htaccess successfully linked to: $dest", [
        "type" => "success"
    ]);
}

if($link = @$opts['link_dir']) {
    
    $src = $fs->root . $link[0];
    $dest = $fs->root . $link[1];

    if(!is_dir($src))
        cmd_out(
            "Source directory $src does not exist!\n"
            . "You cannot link a directory that doesn't exist"
            , ["type" => "fail"]
        );

    if(is_dir($dest)) {
        if(!$force)
            cmd_out(
                "Destination directory: $dest exists already!\n"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note:: You will be deleting the former directory if you decide to pass the flag --force"
                , ["type" => "warn"]
            );

        unlink($dest);
    }

    symlink($src, $dest);

    cmd_out(
        "Directory link created successfully!\n"
         . "Source Directory: $src\n"
         . "Destination Directory: $dest"
        , [ "type" => "success" ]
    );
}

if($link = @$opts['link_file']) {

    $src = $fs->root . $link[0];
    $dest = $fs->root . $link[1];

    if(!file_exists($src))
        cmd_out("Source file $src does not exist! You cannot link a file that doesn't exist", [
            "type" => "fail"
        ]);

    if(file_exists($dest)) {
        if(!$force)
            cmd_out(
                "Destination file: $dest exists already!\n"
                . "If you want to REPLACE!! it, pass the flag --force\n"
                . "***### Take Note::  You will be deleting the former file if you decide to pass the flag --force"
                ,["type" => "warn"]
            );

        unlink($dest);
    }

    symlink($src, $dest);

    cmd_out(
        "Directory link created successfully!\n"
         . "Source Directory: $src\n"
         . "Destination Directory: $dest"
        , [ "type" => "success" ]
    );
}

