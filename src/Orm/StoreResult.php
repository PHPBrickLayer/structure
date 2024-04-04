<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm;

use BrickLayer\Lay\Core\CoreException;
use BrickLayer\Lay\Orm\Enums\OrmReturnType;
use Closure;
use Generator;
use mysqli_result;

class StoreResult extends CoreException
{
    /**
     * @param $exec mysqli_result
     * @param bool $return_loop int|bool to activate loop or not
     * @param OrmReturnType $fetch_as string how result should be returned [assoc|row] default = both
     * @param string $except
     * @param Closure|null $fun a function that should execute at the end of a given row storage
     * @param int $store_dimension 1 | 2. Return stored result as 1D or 2D array. Default [2D].
     * @return Generator of result that can be accessed as assoc or row
     */
    public static function store(mysqli_result $exec, bool $return_loop, OrmReturnType $fetch_as = OrmReturnType::BOTH, string $except = "", Closure $fun = null, int $store_dimension = 2) : Generator
    {
        $num_rows = $exec->num_rows;

        $fetch = match ($fetch_as) {
            default => MYSQLI_BOTH,
            OrmReturnType::ASSOC => MYSQLI_ASSOC,
            OrmReturnType::NUM => MYSQLI_NUM,
        };

        if(!$return_loop) {
            $result = mysqli_fetch_array($exec, $fetch);

            if (!empty($except))
                $result = self::exempt_column($result, $except);

            if ($fun && $result)
                $result = $fun($result);

            return $result;
        }

        for ($k = 0; $k < $num_rows; $k++) {

            if ($store_dimension == 1) {
                foreach (mysqli_fetch_array($exec, MYSQLI_NUM) as $row) {
                    yield $row;
                }

                continue;
            }

            $result = mysqli_fetch_array($exec, $fetch);

            if (!empty($except))
                $result = self::exempt_column($result, $except);

            if ($fun && $result)
                $result = $fun($result, $k);

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