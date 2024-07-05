<?php

namespace BrickLayer\Lay\Libs\String;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use JetBrains\PhpStorm\ArrayShape;
use WeakMap;

class Escape
{
    protected static array $stock_escape_string = ["%3D", "%21", "%2B", "%40", "%23", "%24", "%25", "%5E", "%26", "%2A", "%28", "%29", "%27", "%22", "%3A", "%3B", "%3C", "%3D", "%3E", "%3F", "%2F", "%5C", "%7C", "%60", "%2C", "_", "-", "–", "%0A", "%E2", "%80", "%99", "%E2%80%98", "%E2%80%99"];
    protected static array $escape_string = [];

    /**
     * Escape strings, encode uris, convert words to a acceptable hyphen (-) separated set of characters
     * @param mixed $value
     * @param EscapeType|array $type_or_combo
     * @param array $options
     * @return mixed
     */
    public static function clean(
        mixed $value,
        EscapeType|array $type_or_combo = EscapeType::STRIP_ESCAPE,
        #[ArrayShape([
            "flag" => "int",
            "allowed_tags" => "string",
            "debug" => "bool",
            "strict" => "bool",
            "connect_db" => "bool",
            "reset_esc_string" => "bool",
            "find" => "string|array",
            "replace" => "string|array",
        ])] array $options = []
    ): mixed
    {
        $flags = $options['flag'] ?? ENT_QUOTES;
        $allowedTags = $options['allowed_tags'] ?? "";
        $reset_esc_string = $options['reset_esc_string'] ?? true;
        $debug = $options['debug'] ?? false;
        $strict = $options['strict'] ?? false;
        $connect_db = $options['connect_db'] ?? true;

        // this condition is meant for the $find variable when handling urls
        if (count(self::$escape_string) == 0) self::$escape_string = self::$stock_escape_string;

        if ($type_or_combo == EscapeType::P_URL && $reset_esc_string) {
            self::reset_escape_string();
            self::add_escape_string("/", "\\", "\"", "#", "|", "^", "*", "~", "!", "$", "@", "%", "`", ';', ':', '=', '<', '>', "»", " ", "%20", "?", "'", '"', "(", ")", "[", "]", ".", ",");
        }

        $find = $options['find'] ?? self::$escape_string;
        $replace = $options['replace'] ?? "";

        // parse value
        if (is_numeric($value)) {
            $value = filter_var($value, FILTER_VALIDATE_INT) ?: $value;
            $value = !is_int($value) && preg_match('/[.]/', $value) ? (float) $value : $value;
        }

        if ($debug)
            self::exception(
                "Preview",
                "<div>Value: <b>" . print_r($value, true) . "</b></div>\n"
                . "<div>Type: <b>" . gettype($value) . "</b></div>\n"
                . "<div>Combo: <b>" . print_r($type_or_combo, true) . "</b></div>"
            );


        if (($value === "" || $value === null) && $strict)
            self::exception(
                "EmptyValue",
                "No value passed!<br> <em>An empty string cannot be cleaned</em>"
            );

        if (empty($value) && !$strict)
            return $value;

        if(!is_string($value) && $strict)
            self::exception(
                "NonString",
                "A non stringed value was received in a strict environment. In <b>strict</b> mode, only stringed values are accepted\n"
                . "<div>Value: <span style='font-weight: bold; color: #0dcaf0'>" . print_r($value, true) . "</span></div>\n"
                . "<div>Type: <span style='font-weight: bold; color: #0dcaf0'>" . gettype($value) . "</span></div>"
            );

        if(!is_string($value) && !is_numeric($value))
            self::exception(
                "InvalidValue",
                "An unaccepted value data type was received. Allowed types are <b>string and number</b>\n"
                . "<div>Value: <span style='font-weight: bold; color: #0dcaf0'>" . print_r($value, true) . "</span></div>\n"
                . "<div>Type: <span style='font-weight: bold; color: #0dcaf0'>" . gettype($value) . "</span></div>"
            );

        if (is_numeric($value))
            return $value;

        $map = new WeakMap();
        $map[EscapeType::P_ESCAPE] = fn($val = null) => LayConfig::get_orm($connect_db)->escape_string((string) $value);
        $map[EscapeType::P_STRIP] = fn($val = null) => strip_tags((string)($val ?? $value), $allowedTags);
        $map[EscapeType::P_TRIM] = fn($val = null) => trim($val ?? $value);
        $map[EscapeType::P_SPEC_CHAR] = fn($val = null) => htmlspecialchars($val ?? $value, $flags);
        $map[EscapeType::P_ENCODE_URL] = fn($val = null) => rawurlencode($val ?? $value);
        $map[EscapeType::P_REPLACE] = fn($val = null) => str_replace($find, $replace, $val ?? $value);
        $map[EscapeType::P_URL] = function ($val = null) use ($find, $value) {
            rsort($find);
            return preg_replace("/^-/", "", preg_replace("/-$/", "", strtolower(preg_replace("/--+/", "-", str_replace($find, "-", rawurlencode(trim($val ?? $value)))))));
        };

        $permute = function ($combination, $value) use ($map) {
            foreach ($combination as $combo) {
                if(!isset($map[$combo]))
                    self::exception(
                        "InvalidComboKey",
                        "An invalid Combo Key was received: <b>" . print_r($combo, true) . "</b>\n<br>"
                        . "Type: " . gettype($combo)
                    );

                $value = $map[$combo]($value);
            }
            return $value;
        };

        if(is_array($type_or_combo))
            return $permute($type_or_combo, $value);

        switch ($type_or_combo) {
            default:
                if(!is_object($type_or_combo))
                    self::exception(
                        "InvalidEscapeType",
                        "A invalid combo key was received: <b>" . print_r($type_or_combo, true) . "</b>"
                    );

                $type_or_combo = [$type_or_combo];
                break;
            case EscapeType::STRIP_TRIM_ESCAPE: // 16
                $type_or_combo = [EscapeType::P_STRIP, EscapeType::P_TRIM, EscapeType::P_ESCAPE];
                break;
            case EscapeType::STRIP_ESCAPE:
                $type_or_combo = [EscapeType::P_STRIP, EscapeType::P_ESCAPE];
                break;
            case EscapeType::TRIM_ESCAPE:
                $type_or_combo = [EscapeType::P_TRIM, EscapeType::P_ESCAPE];
                break;
            case EscapeType::ESCAPE_SPEC_CHAR:
                $type_or_combo = [EscapeType::P_ESCAPE, EscapeType::P_SPEC_CHAR];
                break;
            case EscapeType::SPEC_CHAR_STRIP:
                $type_or_combo = [EscapeType::P_SPEC_CHAR, EscapeType::P_STRIP];
                break;
            case EscapeType::STRIP_TRIM:
                $type_or_combo = [EscapeType::P_STRIP, EscapeType::P_TRIM];
                break;
            case EscapeType::ALL:
                $type_or_combo = [EscapeType::P_STRIP, EscapeType::P_SPEC_CHAR, EscapeType::P_TRIM, EscapeType::P_ESCAPE];
                break;
        }

        return $permute($type_or_combo, $value);
    }

    private static function exception(string $title, string $body): void
    {
        Exception::new()->use_exception("EscapeClean::" . $title, $body);
    }

    public static function reset_escape_string(): void
    {
        self::$escape_string = self::$stock_escape_string;
    }

    public static function add_escape_string(...$escape_string): void
    {
        self::$escape_string = [...self::$escape_string, ...$escape_string];
    }


    public function get_escape_string(): array
    {
        return self::$escape_string;
    }

}