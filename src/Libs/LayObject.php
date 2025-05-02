<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;

/**
 * @deprecated
 */
class LayObject
{
    use IsSingleton;

    /**
     * Cached response of an already parsed $_POST data
     * @var array|object
     */
    private static array|object $gotten_json;

    /**
     * @deprecated
     * Gets the HTTP request form data
     * @param bool $throw_errors
     * @param bool $return_array
     * @param bool $invalidate_cache
     * @return array|object
     * @throws \Exception
     */
    public function get_json(bool $throw_errors = true, bool $return_array = false, bool $invalidate_cache = false): array|object
    {
        LayException::log("This method is deprecated. Use `ControllerHelper::request()` instead", log_title: "DeprecatedMethod");

        if(isset(self::$gotten_json) and !$invalidate_cache)
            return self::$gotten_json;

        if($_SERVER['REQUEST_METHOD'] != "POST") {
            parse_str(file_get_contents("php://input"), $data);

            if(!$data && $throw_errors)
                Exception::throw_exception(
                    "Trying to access post data for a [" . $_SERVER['REQUEST_METHOD'] . "] method request",
                    "LayObject::ERR",
                );

            self::$gotten_json = $data;

            if($return_array)
                return self::$gotten_json;

            self::$gotten_json = (object) self::$gotten_json;

            return self::$gotten_json;
        }

        $data = file_get_contents("php://input");

        $msg = "No values found in request; check if you actually sent your values as \$_POST";
        $post = $return_array ? $_POST : (object) $_POST;

        if (!empty($data) && !str_starts_with($data, "{")) {
            if((is_object($post) && empty(get_object_vars($post))) || empty($post)) {
                $post = [];
                @parse_str($data, $post);
                $post = $return_array ? $post : (object) $post;
            }

            $msg = "JSON formatted \$_POST needed; but invalid JSON format was found";
        }

        if ($throw_errors && empty($data) && empty($post))
            Exception::throw_exception(
                $msg,
                "LayObject::ERR",
            );

        self::$gotten_json =  json_decode($data, $return_array) ?? $post;

        return self::$gotten_json;
    }

}
