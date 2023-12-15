<?php
declare(strict_types=1);

namespace BrickLayer\Lay;

class AutoLoader
{
    private static string $root_dir;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public static function get_root_dir(): string
    {
        if(isset(self::$root_dir))
            return self::$root_dir;

        $s = DIRECTORY_SEPARATOR;

        self::$root_dir = explode
            (
                "{$s}vendor{$s}bricklayer{$s}lay",
                __DIR__ . $s
            )[0] . $s;

        return self::$root_dir;
    }

    public static function load_framework_classes(): void
    {
        spl_autoload_register(function ($className) {
            $location = str_replace('\\', DIRECTORY_SEPARATOR, $className);

            @include_once self::get_root_dir() . $location . '.php';
        });
    }

    public static function load_composer(): void
    {
        try{
            @require_once self::get_root_dir() . "vendor" . DIRECTORY_SEPARATOR . "autoload.php";
        } catch (\Error) {
            echo "Composer autoload.php file does not exit. Please run <b>composer dump-autoload</b> on your project root.\n";
        }
    }
}

AutoLoader::load_composer();
AutoLoader::load_framework_classes();