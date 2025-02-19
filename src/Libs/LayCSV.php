<?php

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\Traits\ControllerHelper;

abstract class LayCSV {
    use ControllerHelper;

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
        $output = "";

        while ($row = fgetcsv($fh)){
            $x = $callback($row);

            if(is_array($x))
                return $x;

            $output .= $x;
        }

        return self::res_success( "Processed successfully", ["output" => $output]);
    }
}