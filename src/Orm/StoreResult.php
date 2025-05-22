<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm;

use BrickLayer\Lay\Libs\Primitives\Enums\LayLoop;
use BrickLayer\Lay\Orm\Enums\OrmDriver;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use Closure;
use Generator;

final class StoreResult
{
    /**
     * @param mixed $exec_result DB Result Object
     * @param bool $return_loop int|bool to activate loop or not
     * @param OrmReturnType $fetch_as string how result should be returned [assoc|row] default = both
     * @param string $except
     * @param Closure|null $fun a function that should execute at the end of a given row storage
     *
     * @return Generator|array returns of result that can be accessed as assoc or row or a generator
     *
     */
    public static function store(mixed $exec_result, bool $return_loop, OrmReturnType $fetch_as = OrmReturnType::BOTH, string $except = "", ?Closure $fun = null) : Generator|array
    {
        $link = SQL::new()->get_link();
        $current_driver = SQL::get_driver();
        $is_sqlite = OrmDriver::is_sqlite($current_driver);

        if(!$return_loop) {
            $result = $link->fetch_result($exec_result, $fetch_as);
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

        foreach ($link->fetch_result($exec_result, $fetch_as) as $k => $result) {
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