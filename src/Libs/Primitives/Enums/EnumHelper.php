<?php

namespace BrickLayer\Lay\Libs\Primitives\Enums;

use BrickLayer\Lay\Core\LayException;

/**
 * Used inside Enums to add steroids to enums
 */
trait EnumHelper
{
    public static function to_enum(string $value, bool $throw_error = false, bool $use_value = true) : ?self
    {
        foreach (self::cases() as $enum) {
            $entry = $use_value ? ($enum->value ??  $enum->name) : $enum->name;

            if($value == $entry)
                return $enum;
        }

        if($throw_error)
            LayException::throw("Value [$value] is not a valid enum entry in " . self::class, "OutOfBoundsAccess");

        return null;
    }

    public static function is_enum(string $value, bool $use_value = true) : bool
    {
        foreach (self::cases() as $enum) {
            $entry = $use_value ? ($enum->value ??  $enum->name) : $enum->name;

            if($value == $entry)
                return true;
        }

        return false;
    }

    /**
     * @see cases_assoc
     * @deprecated use cases_assoc
     * @return array
     */
    public static function cases_str() : array
    {
        return self::cases_assoc();
    }

    public static function cases_assoc() : array
    {
        $all = [];

        foreach (self::cases() as $enum) {
            $all[] = [
                "id" => $enum->name,
                "name" => str_replace("_", " ", $enum->value ??  $enum->name),
            ];
        }

        return $all;
    }

    public static function cases_row() : array
    {
        $all = [];

        foreach (self::cases() as $enum) {
            $all[] = $enum->name;
        }

        return $all;
    }

    public static function api_res() : array
    {
        return [
            "status" => "success",
            "code" => 200,
            "message" => "Ok",
            "data" => self::cases_assoc(),
        ];
    }

    /**
     * @param 'default'|'upper'|'ucwords'|'lower'|'ucfirst' $case
     * @return string
     */
    public function stringify(string $case = "default") : string
    {
        $str = str_replace(["_"], [" "], $this->name);

        if($case == "upper")
            return strtoupper($str);

        if($case == "lower")
            return strtolower($str);

        if($case == "ucwords")
            return ucwords(strtolower($str));

        if($case == "ucfirst")
            return ucfirst(strtolower($str));

        return $str;
    }

}