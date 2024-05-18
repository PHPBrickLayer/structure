<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use stdClass;

class LayObject
{
    use IsSingleton;

    /**
     * @param bool $strict [default = true] throws error if nothing is found in request POST request
     * @param bool $return_array
     * @return mixed Returns what json_decode returns
     * @throws \Exception
     */
    public function get_json(bool $strict = true, bool $return_array = false): mixed
    {
        if($_SERVER['REQUEST_METHOD'] != "POST") {
            parse_str(file_get_contents("php://input"), $data);

            if(!$data)
                Exception::throw_exception(
                    "Trying to access post data for a " . $_SERVER['REQUEST_METHOD'] . " method request",
                    "LayObject::ERR",
                );

            if($return_array)
                return $data;

            return (object) $data;
        }

        $data = file_get_contents("php://input");

        $msg = "No values found in request; check if you actually sent your values as \$_POST";
        $post = $return_array ? $_POST : (object) $_POST;

        if (!empty($data) && !str_starts_with($data, "{")) {
            $x = "";
            $msg = "JSON formatted \$_POST needed; but invalid JSON format was found";
        }

        if ($strict && empty($data) && empty($post))
            Exception::throw_exception(
                $msg,
                "LayObject::ERR",
            );

        return json_decode($data, $return_array) ?? $post;
    }

    /**
     * @param string $token JWT token
     * @param bool $assoc_array return the object as an associative array
     * @return mixed Returns what json_decode returns
     */
    public function jwt_decode(string $token, bool $assoc_array = false): mixed
    {
        return json_decode(
            base64_decode(
                str_replace(
                    '_',
                    '/',
                    str_replace(
                        '-',
                        '+',
                        explode('.', $token)[1]
                    )
                )
            ),
            $assoc_array
        );
    }
}
