<?php

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;
use BrickLayer\Lay\Libs\Primitives\Traits\ControllerHelper;
use Closure;

abstract class LayCSV {
    use ControllerHelper;

    /**
     * @param string $file
     * @param Closure(array<int,mixed>|false $row, array<string, mixed> $row_head, int $line_num, string &$errors):(LayLoop|array<int,mixed>) $callback
     * @param int $max_size_kb
     *
     *
     * @return array{code: int, status: string, message: string, data: array<int, array<int, mixed>>|null}
     */
    public static function process(string $file, Closure $callback, int $max_size_kb = 1) : array
    {
        $file_type = mime_content_type($file);
        $max_size_kb *= 1000;
        $max_size_kb = $max_size_kb /1000;

        if((filesize($file)/1000) > $max_size_kb)
            return self::res_warning("Max file size of [{$max_size_kb}kb] exceeded");

        if(!$file_type)
            return self::res_warning("Invalid file received");

        if(!in_array($file_type, ["text/csv" , "text/plain"], true))
            return self::res_warning( "Invalid file type received, ensure your file is saved as <b>CSV</b>. File type <b>$file_type</b> received");

        $fh = fopen($file,'r');
        $output = [];

        $i = 0;
        $head = [];
        $has_error = false;
        $error_msg = "";

        while ($row = fgetcsv($fh, escape: "\\")) {
            if($i == 0) {
                foreach ($row as $r) {
                    $head[] = $r;
                }

                $i++;
                continue;
            }

            $row_head = [];
            $has_error = false;

            foreach ($row as $n => $r) {
                $h = $head[$n] ?? null;

                if($h === null) {
                    $has_error = true;
                    break;
                }

                $row_head[trim($h)] = $r;
            }

            if($has_error) break;

            $x = $callback($row, $row_head, $i, $error_msg);
            $i++;

            if(!empty($error_msg))
                $has_error = true;

            if ($x == LayLoop::CONTINUE) continue;

            if ($x == LayLoop::BREAK) break;

            $output[] = $x;
        }

        if($has_error)
            return self::res_warning(
                $error_msg ?: "Could not process CSV. An error occurred. 
                Ensure you used the template provided and you saved the file as `.csv`"
            );

        return self::res_success( "Processed successfully", $output);
    }
}