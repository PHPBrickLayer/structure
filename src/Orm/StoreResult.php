<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm;

use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use Closure;
use Generator;
use mysqli_result;
use PgSql\Result;
use SQLite3Result;

final class StoreResult
{
    /**
     * @param mysqli_result|SQLite3Result|Result $exec_result mysqli_result
     * @param bool $return_loop int|bool to activate loop or not
     * @param OrmReturnType $fetch_as string how result should be returned [assoc|row] default = both
     * @param string $except
     * @param Closure|null $fun a function that should execute at the end of a given row storage
     *
     * @return Generator returns of result that can be accessed as assoc or row or a generator
     *
     * @psalm-return Generator<int, mixed, mixed, array|mixed|true>
     */
    public static function store(mixed $exec_result, bool $return_loop, OrmReturnType $fetch_as = OrmReturnType::BOTH, string $except = "", ?Closure $fun = null) : Generator|array
    {
        $link = SQL::new()->get_link();
        $current_driver = SQL::get_driver();
        $is_sqlite = OrmDriver::is_sqlite($current_driver);

        switch ($current_driver) {
            default: $mode = null; break;

            case OrmDriver::SQLITE:
            case OrmDriver::SQLITE3:
                $mode = match ($fetch_as) {
                    default => SQLITE3_BOTH,
                    OrmReturnType::ASSOC => SQLITE3_ASSOC,
                    OrmReturnType::NUM => SQLITE3_NUM,
                };
                break;

            case OrmDriver::MYSQL:
                $mode = match ($fetch_as) {
                    default => MYSQLI_BOTH,
                    OrmReturnType::ASSOC => MYSQLI_ASSOC,
                    OrmReturnType::NUM => MYSQLI_NUM,
                };
                break;

            case OrmDriver::POSTGRES:
                $mode = match ($fetch_as) {
                    default => PGSQL_BOTH,
                    OrmReturnType::ASSOC => PGSQL_ASSOC,
                    OrmReturnType::NUM => PGSQL_NUM,
                };
                break;
        }

        if(!$return_loop) {
            $result = $link->fetch_result($exec_result, $mode);
            $result = !$is_sqlite ? $result[0] : ($result ?: []);

            if (!empty($except))
                $result = self::exempt_column($result, $except);

            if ($fun && $result)
                $result = $fun($result);

            return $result;
        }

        $loop_handler = function ($k, &$result) use ($fun, $except, $exec_result): void {
            if (!empty($except))
                $result = self::exempt_column($result, $except);

            if ($fun && $result)
                $result = $fun($result, $k, $exec_result);
        };

        foreach ($link->fetch_result($exec_result, $mode) as $k => $result) {
            $break = false;
            $loop_handler($k, $result);

            if(isset($result['_LAY_LOOP_']) && $result['_LAY_LOOP_'] == LayLoop::BREAK) {
                unset($result['_LAY_LOOP_']);
                $break = true;
            }

            yield $result;

            if($break) break;
        }

    }

    private static function exempt_column(?array $entry, ?string $columns): array
    {
        if (!($entry && $columns))
            return [];

        foreach (explode(",", $columns) as $x) {
            unset($entry[$x]);
        }

        return $entry;
    }
}