<?php

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\LayException;

abstract class LayArray
{
    /**
     * Enhanced array search, this will search for values even in multiple dimensions of arrays.
     *
     * @param mixed $needle
     * @param array $haystack
     * @param bool $strict choose between == or === comparison operator
     * @param array $__RESULT_INDEX__ ***Do not modify this option, it is readonly to the developer***
     *
     * @return (array|bool|mixed|string)[] <p>Returns the first occurrence of the value in an array that contains the value as interpreted by the function and the keys based on the total dimension it took to find the value.</p> <code>::search("2", ["ss", [[2]], '2'], true) </code> <code>== ['value' => '2', index => [1,2]]</code> <code>::search("2", ["ss", [[2]], '2']) </code> <code>== ['value' => '2', index => [1,0,0]]</code>
     *
     * @psalm-return array{value: mixed|string, index: array|string, found: bool|string,...}
     */
    public static function search(mixed $needle, array $haystack, bool $strict = false, array $__RESULT_INDEX__ = []) : array
    {
        $result = [
            "value" => "LAY_NULL",
            "index" => $__RESULT_INDEX__,
            "found" => false,
        ];

        foreach ($haystack as $i => $d) {
            if(is_array($d)) {
                array_shift($result['index']);
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
     * @deprecated use `any` or `every` to express yourself. Some is confusing in the way it is used
     * Returns a new array of list of values when the callback resolves to true and ignores the value of it returns false
     *
     * @param array $array
     * @param callable $callback
     * @param bool $preserve_key
     *
     * @return (mixed|null)[]
     *
     * @psalm-return list{mixed|null}
     */
    public static function some(array $array, callable $callback, bool $preserve_key = false) : array
    {
        LayException::log("Depreciated method `some` used; Use `any` or `every` instead. `some` is confusing");
        return [self::any($array, $callback)['value']];
    }

    /**
     * Loops through the array and returns the first array that satisfies the condition set.
     * Returns null if nothing checks out
     *
     * @param array $array
     * @param callable($value,$key):bool $condition
     * @return null|array{
     *     key: string|int,
     *     value: mixed,
     * }
     */
    public static function any(array $array, callable $condition) : ?array
    {
        $rtn = null;

        foreach ($array as $key => $value)
        {
            $cond = $condition($value, $key);

            if($cond) {
                $rtn = [
                    "key" => $key,
                    "value" => $value
                ];
                break;
            }
        }

        return $rtn;
    }

    /**
     * Returns an array of every value that satisfies the condition set in the `$condition` argument.
     * Returns null if nothing satisfies.
     *
     * @param array $array
     * @param callable($value,$key):bool $condition
     * @param bool $preserve_key
     */
    public static function every(array $array, callable $condition, bool $preserve_key = true) : array|null
    {
        $rtn = [];

        foreach ($array as $key => $value)
        {
            $cond = $condition($value, $key);

            if($cond) {
                if($preserve_key)
                    $rtn[$key] = $value;
                else
                    $rtn[] = $value;
            }
        }

        return empty($rtn) ? null :  $rtn;
    }

    /**
     * Flattens multiple dimensions of an array to a single dimension array.
     * The latest values will replace arrays with the same keys
     * @param array $array
     * @return array
     */
    public static function flatten(array $array): array
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

    public static function to_object(array|object|null $array): object {
        if (is_object($array))
            return $array;

        $obj = new \stdClass();

        foreach ($array as $k => $v) {
            if (is_array($v)) {
                $obj->{$k} = self::to_object($v);
                continue;
            }

            $obj->{$k} = $v;
        }

        return $obj;
    }

    public static function to_array(array|object $array): array
    {
        if (is_array($array))
            return $array;

        $obj = [];

        foreach ($array as $k => $v) {
            if (is_object($v)) {
                $obj[$k] = self::to_array($v);
                continue;
            }

            $obj[$k] = $v;
        }

        return $obj;
    }

    /**
     * Merges the elements of one or more arrays or objects together (if the input arrays have the same string keys, then the later value for that key will overwrite the previous one; if the arrays contain numeric keys, the later value will be appended).
     * It can merge an array and an stdClass, and return a common datatype.
     * @param array|object $object1
     * @param array|object $object2
     * @param bool $return_object
     * @return array|object
     */
    public static function merge(array|object $object1, array|object $object2, bool $return_object = false) : array|object
    {
        $is_1_object = is_object($object1);

        if($return_object && !$is_1_object) {
            $object1 = self::to_object($object1);
            $is_1_object = true;
        }

        if(!$return_object && $is_1_object){
            $object1 = self::to_array($object1);
            $is_1_object = false;
        }

        foreach ($object2 as $k => $v) {
            if(is_array($v) && $return_object) {
                $v = self::to_object($v);
            }

            if(is_object($v) && !$return_object) {
                $v = self::to_array($v);
            }

            if($is_1_object)
                $object1->{$k} = $v;
            else
                $object1[$k] = $v;
        }

        return $object1;
    }

    /**
     * Return array map properly
     *
     * @param array $array
     * @param callable(mixed, mixed):array $callback
     *
     * @return array[]
     *
     * @psalm-return list{0?: array,...}
     */
    public static function map(array $array, callable $callback) : array
    {
        $all = [];

        foreach ($array as $k => $v) {
            $all[] = $callback($v, $k);
        }

        return $all;
    }

    /**
     * Count an array or an object
     * @param array|object $object
     * @return int
     */
    public static function count(array|object $object) : int
    {
        if(is_array($object)) return count($object);

        $count = 0;

        foreach ($object as $o) {
            $count++;
        }

        return $count;
    }

    /**
     * Update an array in multiple dimensions, using an array of keys
     *
     * @param array $key_chain
     * @param mixed $value
     * @param array $array_to_update
     * @return void
     */
    public static function update_recursive(array $key_chain, mixed $value, array &$array_to_update) : void
    {
        $current_key = array_shift($key_chain);

        if (empty($key_chain)) {
            $array_to_update[$current_key] = $value;
            return;
        }

        if (!isset($array_to_update[$current_key]) || !is_array($array_to_update[$current_key])) {
            $array_to_update[$current_key] = [];
        }

        // Recurse to the next level
        self::update_recursive($key_chain, $value, $array_to_update[$current_key]);
    }
}