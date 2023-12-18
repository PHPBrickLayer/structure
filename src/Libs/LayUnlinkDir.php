<?php
declare(strict_types=1);
namespace BrickLayer\Lay\Libs;

class LayUnlinkDir {
    public static bool $result;

    /**
     * @param string $dir Directory to be deleted
     */
    public function __construct(string $dir)
    {
        if (!is_dir($dir)) {
            self::$result = false;

            if(file_exists($dir))
                self::$result = unlink($dir);

            return;
        }

        if(is_link($dir)) {
            self::$result = unlink($dir);
            return;
        }

        foreach (scandir($dir) as $object) {
            if ($object == "." || $object == "..")
                continue;

            if (!is_dir($dir . "/" . $object)) {
                unlink($dir."/".$object);
                continue;
            }


            new self($dir . "/" . $object);

        }

        self::$result = rmdir($dir);
    }
}