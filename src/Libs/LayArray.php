<?php

namespace BrickLayer\Lay\Libs;

class LayArray
{
    /**
     * Enhanced array search, this will search for values even in multiple dimensions of arrays.
     * @param mixed $needle
     * @param array $haystack
     * @param bool $strict choose between == or === comparison operator
     * @param array $__RESULT_INDEX__ ***Do not modify this option, it is readonly to the developer***
     * @return string[] <p>Returns the first occurrence of the value in an array that contains the value
     * as interpreted by the function and the keys based on the total dimension it took to find the value.</p>
     * <code>::run("2", ["ss", [[2]], '2'], true) </code>
     * <code>== ['value' => '2', index => [1,2]]</code>
     *
     * <code>::run("2", ["ss", [[2]], '2']) </code>
     * <code>== ['value' => '2', index => [1,0,0]]</code>
     */
    final public static function search(mixed $needle, array $haystack, bool $strict = false, array $__RESULT_INDEX__ = []) : array {
        $result = [
            "value" => "LAY_NULL",
            "index" => $__RESULT_INDEX__,
            "found" => false,
        ];

        foreach ($haystack as $i => $d) {
            if(is_array($d)) {
                $result['index'][] = $i;
                $search = self::search($needle, $d, $strict, $result['index']);

                if($search['value'] !== "LAY_NULL") {
                    $result = array_merge($result,$search);
                    break;
                }
                continue;
            }

            if(($strict === false && $needle == $d)){
                $result['index'][] = $i;
                $result['value'] = $d;
                $result['found'] = true;
                break;
            }

            if(($strict === true && $needle === $d)){
                $result['index'][] = $i;
                $result['value'] = $d;
                $result['found'] = true;
                break;
            }
        }

        return $result;
    }

    /**
     * Returns a new array of list of values when the callback resolves to true and ignores the value of it returns false
     * @param array $array
     * @param callable $callback
     * @param bool $preserve_key
     * @return array
     */
    final public static function some(array $array, callable $callback, bool $preserve_key = false) : array
    {
        $rtn = [];

        foreach ($array as $key => $value)
        {
            if($callback($value, $key)) {
                if($preserve_key) {
                    $rtn[$key] = $value;
                    continue;
                }

                $rtn[] = $value;
            }
        }

        return $rtn;
    }

    /**
     * Flattens multiple dimensions of an array to a single dimension array.
     * The latest values will replace arrays with the same keys
     * @param array $array
     * @return array
     */
    final public static function flatten(array $array): array
    {
        $arr = $array;
        if(!count(array_filter($array, "is_array")) > 0)
            return $arr;

        $arr = [];

        foreach ($array as $i => $v) {
            if (!is_array($v)) {
                $arr[$i] = $v;
                continue;
            }

            array_walk($v, function ($entry, $key) use (&$arr, &$v) {
                if (is_array($entry))
                    $arr = array_merge($arr, $entry);
                elseif (!is_int($key))
                    $arr[$key] = $entry;
                else
                    $arr[] = $entry;
            });
        }

        return $arr;
    }
}