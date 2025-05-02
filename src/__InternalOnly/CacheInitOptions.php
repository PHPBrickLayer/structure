<?php
declare(strict_types=1);

namespace BrickLayer\Lay\__InternalOnly;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\LayDate;

//TODO: Consider implementing a caching system for site_data and server_data to reduce memory usage
final class CacheInitOptions {
    private const OPTIONS_CACHE_DIR = "cached_opts" . DIRECTORY_SEPARATOR;

    /**
     * @return (array|bool|null)[]
     *
     * @psalm-return array{error: bool, expired: bool, cached: bool, data: array|null}
     */
    private static function cache_result_dto(bool $file_exists = true, bool $cached = true, bool $expired = true, ?array $data = null) : array
    {
        return [
            "error" => !$file_exists,
            "expired" => $expired,
            "cached" => $cached,
            "data" => $data
        ];
    }

    private static function check_cached_option(string $file) : array
    {
        $file = LayConfig::server_data()->temp . self::OPTIONS_CACHE_DIR . $file;

        if(!file_exists($file))
            return self::cache_result_dto(
                file_exists: false,
                cached: false,
            );

        $data = json_decode(file_get_contents($file), true);

        if(LayDate::greater($data['expires']))
            return self::cache_result_dto();

        return self::cache_result_dto(
            expired: false,
            data: $data['data']
        );
    }

    public static function check_server_data() : array
    {
        return self::check_cached_option("server_data.json");
    }

    public static function cache_server_data(array $object) : void
    {

    }

    public static function check_site_data() : array
    {
        return self::check_cached_option("site_data.json");
    }

    public static function cache_site_data(array $object) : void
    {

    }

}
