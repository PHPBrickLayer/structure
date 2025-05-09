<?php

namespace BrickLayer\Lay\Libs\LayLogReader;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\Dir\Enums\SortOrder;
use BrickLayer\Lay\Libs\Dir\LayDir;
use Generator;

abstract class LayLogReader
{
    private static array $file_entry_cache = [];

    /**
     * Reads the
     * @param bool $as_html
     * @return Generator<array{
     *     time: string,
     *     message: string,
     *     x_info: string,
     *     app_trace: string,
     *     internal_trace: string,
     * }>
     * @throws \Exception
     */
    public static function exceptions(bool $as_html = true, int $max_files = 10) : Generator
    {
        $log_files = LayDir::read(LayConfig::server_data()->exceptions, sort: SortOrder::TIME_DESC);
        self::$file_entry_cache = [];

        if(!$log_files) return;

        foreach ($log_files as $i => $file) {
            if($i == $max_files - 1) break;

            $fh = fopen($file['full_path'], 'r');

            if (!$fh) continue;

            $current_entry = null;

            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");

                $is_new_entry = preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [^\]]+)\]/', $line, $matches);
                if ($is_new_entry) {
                    // Yield the last entry before starting a new one
                    if ($current_entry !== null) {
                        self::$file_entry_cache[$file['file']][] = $current_entry;
                        yield $current_entry;
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

            // Yield the last log entry
            if ($current_entry !== null) {
                self::$file_entry_cache[$file['file']][] = $current_entry;
                yield $current_entry;
            }
        }
    }

    /**
     * Render exception for your HTML view
     * @return string|null
     * @throws \Exception
     */
    public static function render_exceptions() : ?string
    {
        $all_log = "";

        // Init generator so we can reorder the per-file entry
        foreach(LayLogReader::exceptions(true, 15) as $e) : endforeach;

        foreach(self::$file_entry_cache as $entry) {
            rsort($entry);
            foreach ($entry as $e) {
                $all_log .= (
                "<details class='mb-5' open>
                        <summary class='fs-3 fw-bold ps-0 p-4'>[{$e['time']}]</summary>
                        <div class='p-4 fw-semibold bg-gray-200 fs-5'>{$e['message']}</div>
                        
                        <details class='p-2 ms-4 bg-gray-200'>
                            <summary class='fs-3 fw-bold p-1'>[X-INFO]</summary>
                            <div><pre style='line-height: .9rem; margin: 0; font-size: .9rem'>{$e['x_info']}</pre></div>
                        </details>
                        
                        <details class='p-2 ms-4 bg-gray-200'>
                            <summary class='fs-3 fw-bold p-1'>[APP TRACE]</summary>
                            <div><pre style='line-height: .9rem; margin: 0; font-size: .9rem'>{$e['app_trace']}</pre></div>
                        </details>
                        
                        <details class='p-2 ms-4 bg-gray-200'>
                            <summary class='fs-3 fw-bold p-1'>[INTERNAL TRACE]</summary>
                            <div><pre style='line-height: .9rem; margin: 0; font-size: .9rem'>{$e['internal_trace']}</pre></div>
                        </details>
                    </details>"
                );
            }
        }

        return empty($all_log) ? null : $all_log;
    }

}