<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm;

use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use Closure;
use Generator;
use mysqli_result;
use SQLite3Result;

class StoreResult
{
    /**
     * @param mysqli_result|SQLite3Result $exec mysqli_result
     * @param bool $return_loop int|bool to activate loop or not
     * @param OrmReturnType $fetch_as string how result should be returned [assoc|row] default = both
     * @param string $except
     * @param Closure|null $fun a function that should execute at the end of a given row storage
     * @return Generator|array returns of result that can be accessed as assoc or row or a generator
     */
    public static function store(mysqli_result|SQLite3Result $exec, bool $return_loop, OrmReturnType $fetch_as = OrmReturnType::BOTH, string $except = "", Closure $fun = null) : Generator|array
    {
        $fetch_fn = function (?OrmReturnType $custom_fetch_as = null) use ($exec, $fetch_as) : array {
            $fetch_as = $custom_fetch_as ?? $fetch_as;
            $driver = SQL::get_driver();

            if($driver == OrmDriver::SQLITE) {
                $fetch = match ($fetch_as) {
                    default => SQLITE3_BOTH,
                    OrmReturnType::ASSOC => SQLITE3_ASSOC,
                    OrmReturnType::NUM => SQLITE3_NUM,
                };

                return $exec->fetchArray($fetch) ?: [];
            }

            $fetch = match ($fetch_as) {
                default => MYSQLI_BOTH,
                OrmReturnType::ASSOC => MYSQLI_ASSOC,
                OrmReturnType::NUM => MYSQLI_NUM,
            };

            return $exec->fetch_all($fetch);
        };

        if(!$return_loop) {
            $result = $fetch_fn()[0];

            if (!empty($except))
                $result = self::exempt_column($result, $except);

            if ($fun && $result)
                $result = $fun($result);

            return $result;
        }

        foreach ($fetch_fn() as $k => $result) {
            if (!empty($except))
                $result = self::exempt_column($result, $except);

            if ($fun && $result)
                $result = $fun($result, $k, $exec);

            yield $result;
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