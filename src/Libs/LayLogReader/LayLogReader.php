<?php

namespace BrickLayer\Lay\Libs\LayLogReader;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\Dir\Enums\SortOrder;
use BrickLayer\Lay\Libs\Dir\LayDir;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;
use DirectoryIterator;
use Generator;

abstract class LayLogReader
{

    /**
     * Reads the
     * @param bool $as_html
     * @return array{
     *     time: string,
     *     message: string,
     *     x_info: string,
     *     app_trace: string,
     *     internal_trace: string,
     * }
     * @throws \Exception
     */
    public static function exceptions(bool $as_html = true, int $max_files = 10) : array
    {
        $file_entry_cache = [];

        LayDir::read(
            LayConfig::server_data()->exceptions,
            function (string $name, string $dir, $handler, $file) use ($as_html, $max_files, &$file_entry_cache) {
                if($file['index'] == $max_files) return LayLoop::BREAK;

                $fh = fopen($file['full_path'], 'r');

                if (!$fh) return LayLoop::CONTINUE;

                $current_entry = null;

                while (($line = fgets($fh)) !== false) {
                    $line = rtrim($line, "\r\n");

                    $is_new_entry = preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [^\]]+)\]/', $line, $matches);
                    if ($is_new_entry) {
                        // Yield the last entry before starting a new one
                        if ($current_entry !== null) {
                            $file_entry_cache[$file['file']][] = $current_entry;
                        }

                        $still_x_info = false;
                        $still_app_trace = false;
                        $still_internal_trace = false;

                        // Start a new entry
                        $current_entry = [
                            'time' => $matches[1],
                            'message' => "",
                            'x_info' => '',
                            'app_trace' => '',
                            'internal_trace' => '',
                        ];
                    }

                    $is_internal_trace = preg_match('/__INTERNAL__/', $line, $matches);
                    if($is_internal_trace || $still_internal_trace) {
                        $still_internal_trace = true;

                        if($is_internal_trace) continue;

                        if ($as_html) $line = "<div class='log-entry-trace internal-trace'>$line</div>";

                        $current_entry['internal_trace'] .= $line . "\n";
                        continue;
                    }

                    $is_app_trace = preg_match('/__APP__/', $line, $matches);
                    if($is_app_trace || $still_app_trace) {
                        $still_app_trace = true;

                        if($is_app_trace) continue;

                        if ($as_html) $line = "<div class='log-entry-trace app-trace'>$line</div>";

                        $current_entry['app_trace'] .= $line . "\n";
                        continue;
                    }

                    $is_x_info = preg_match('/HOST:/', $line, $matches);
                    if($is_x_info || $still_x_info) {
                        $still_x_info = true;

                        if($is_x_info) continue;

                        if ($as_html) $line = "<div class='log-entry-x-info'>$line</div>";

                        $current_entry['x_info'] .= $line . "\n";
                        continue;
                    }

                    $line = str_replace("[{$current_entry['time']}]", "", $line);

                    if ($as_html)
                        $line = "<div class='log-entry-message'>$line</div>";

                    $current_entry['message'] .= $line . "\n";
                }

                fclose($fh);

                if ($current_entry !== null) {
                    $file_entry_cache[$file['file']][] = $current_entry;
                }
            },
            sort: SortOrder::TIME_DESC
        );

        return $file_entry_cache;
    }

    /**
     * Render exception for your HTML view
     * @return string|null
     * @throws \Exception
     */
    public static function render_exceptions() : ?string
    {
        $all_log = "";
        foreach(LayLogReader::exceptions(true, 15) as $entry) {
            rsort($entry);
            foreach ($entry as $e) {
                $all_log .= (
                "<details class='mb-5' open>
                    <summary class='fs-3 fw-bold ps-0 p-4'>[{$e['time']}]</summary>
                    <div class='p-4 fw-semibold bg-gray-200 fs-5'>{$e['message']}</div>
                    
                    <details class='p-2 ms-4 bg-gray-200'>
                        <summary class='fs-3 fw-bold p-1'>[X-INFO]</summary>
                        <div><pre style='line-height: .9rem; margin: 0; font-size: 1.05rem'>{$e['x_info']}</pre></div>
                    </details>
                    
                    <details class='p-2 ms-4 bg-gray-200'>
                        <summary class='fs-3 fw-bold p-1'>[APP TRACE]</summary>
                        <div><pre style='line-height: .9rem; margin: 0; font-size: 1.05rem'>{$e['app_trace']}</pre></div>
                    </details>
                    
                    <details class='p-2 ms-4 bg-gray-200'>
                        <summary class='fs-3 fw-bold p-1'>[INTERNAL TRACE]</summary>
                        <div><pre style='line-height: .9rem; margin: 0; font-size: 1.05rem'>{$e['internal_trace']}</pre></div>
                    </details>
                </details>"
                );
            }
        }

        return empty($all_log) ? null : $all_log;
    }

    /**
     * Render exception for your HTML view
     * @return string|null
     * @throws \Exception
     */
    public static function render_mails() : ?string
    {
        $all_log = "";

        LayDir::read(
            LayConfig::server_data()->temp . "emails",
            function (string $file, string $dir, DirectoryIterator $handler, array $entry) use (&$all_log) {
                $message = file_get_contents($entry['full_path']);
                $time = LayDate::date(str_replace(["[","]",".log"], "", $file));

                $all_log .= (
                "<details class='mb-5' open>
                    <summary class='fs-3 fw-bold ps-0 p-4'>[$time]</summary>
                    <div class='p-4 fw-semibold bg-gray-200 fs-5'><pre>$message</pre></div>
                </details>"
                );
            },
            sort: SortOrder::TIME_DESC,
        );

        return empty($all_log) ? null : $all_log;
    }

}