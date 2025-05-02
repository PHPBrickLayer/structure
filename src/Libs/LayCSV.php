<?php

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;
use BrickLayer\Lay\Libs\Primitives\Traits\ControllerHelper;

abstract class LayCSV {
    use ControllerHelper;

    /**
     * @param string $file
     * @param \Closure $callback
     * @param int $max_size_kb
     *
     * @return (array|int|null|string)[]
     *
     * @psalm-return array{code: int, status: string, message: string, data: array|null}
     */
    public static function process(string $file, \Closure $callback, int $max_size_kb = 1000) : array {
        $file_type = mime_content_type($file);
        $max_size_kb = $max_size_kb /1000;

        if((filesize($file)/1000) > $max_size_kb)
            return self::res_warning("Max file size of [{$max_size_kb}kb] exceeded");

        if(!$file_type)
            return self::res_warning("Invalid file received");

        if(!in_array($file_type, ["text/csv" , "text/plain"], true))
            return self::res_warning( "Invalid file type received, ensure your file is saved as <b>CSV</b>");

        $fh = fopen($file,'r');
        $output = [];

        while ($row = fgetcsv($fh)) {
            $x = $callback($row);

            if($x instanceof LayLoop::class) {
                if ($x == LayLoop::CONTINUE)
                    continue;

                if ($x == LayLoop::BREAK)
                    break;
            }

            $output[] = $x;
        }

        return self::res_success( "Processed successfully", $output);
    }
}