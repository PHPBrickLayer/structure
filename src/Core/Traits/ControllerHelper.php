<?php

namespace  BrickLayer\Lay\Core\Traits;

use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\CoreException;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use Throwable;

trait ControllerHelper {
    /**
     * Cached request form data of an already parsed $_POST data
     * @var array|object
     */
    private static array|object $cached_request_fd;

    /**
     * Quickly escape and replace a value without going through the VCM route or the `Escape::clean()` method.
     *
     * @see Escape::clean()
     * @see ValidateCleanMap
     *
     * @param mixed &$value
     * @param EscapeType $type
     * @param bool $strict
     * @return void
     */
    public static function cleanse(mixed &$value, EscapeType $type = EscapeType::STRIP_TRIM_ESCAPE, bool $strict = true): void
    {
        $value = $value ? Escape::clean($value, $type, ['strict' => $strict]) : "";
    }

    /**
     * Quickly escape and return the escaped value.
     *
     * @see Escape::clean()
     * @see ValidateCleanMap
     *
     * @param mixed $value
     * @param EscapeType $type
     * @param bool $strict
     * @return mixed
     */
    public static function clean(mixed $value, EscapeType $type = EscapeType::STRIP_TRIM_ESCAPE, bool $strict = true): mixed
    {
        if(!$value)
            return "";

        return Escape::clean($value, $type, ['strict' => $strict]);
    }

    /**
     * Get HTTP request form data.
     *
     * This method attempts to get form data once, and caches the response,
     * except when the $invalidate_cache is set to true
     *
     * @param bool $throw_error
     * @param bool $as_array
     * @param bool $invalidate_cache
     * @return array|object
     * @throws \Exception
     */
    public static function request(bool $throw_error = true, bool $as_array = false, bool $invalidate_cache = false): array|object
    {
        if(isset(self::$cached_request_fd) and !$invalidate_cache)
            return self::$cached_request_fd;

        if($_SERVER['REQUEST_METHOD'] != "POST") {
            parse_str(file_get_contents("php://input"), $data);

            if(!$data && $throw_error)
                Exception::throw_exception(
                    "Trying to access post data for a [" . $_SERVER['REQUEST_METHOD'] . "] method request",
                    "LayObject::ERR",
                );

            self::$cached_request_fd = $data;

            if($as_array)
                return self::$cached_request_fd;

            self::$cached_request_fd = (object) self::$cached_request_fd;

            return self::$cached_request_fd;
        }

        $data = file_get_contents("php://input");

        $msg = "No values found in request; check if you actually sent your values as \$_POST";
        $post = $as_array ? $_POST : (object) $_POST;

        if (!empty($data) && !str_starts_with($data, "{")) {
            if((is_object($post) && empty(get_object_vars($post))) || empty($post)) {
                $post = [];
                @parse_str($data, $post);
                $post = $as_array ? $post : (object) $post;
            }

            $msg = "JSON formatted \$_POST needed; but invalid JSON format was found";
        }

        if ($throw_error && empty($data) && empty($post))
            Exception::throw_exception(
                $msg,
                "LayObject::ERR",
            );

        self::$cached_request_fd =  json_decode($data, $as_array) ?? $post;

        return self::$cached_request_fd;

    }

    /**
     * Send an array response with a specific status code.
     *
     * @param mixed $data The data to be sent in the response.
     * @param int|ApiStatus $code The HTTP status code for the response.
     * @param bool $send_header
     * @return array{
     *     code: int,
     *     status: string,
     *     message: string,
     *     data: array|null
     * }
     */
    private static function __res_send(array $data, ApiStatus|int $code = ApiStatus::OK, bool $send_header = false) : array
    {
        $code = ApiStatus::get_code($code);

        http_response_code($code);

        if($send_header)
            LayFn::header("Content-Type: application/json");

        $data['code'] = $code;
        return $data;
    }

    /**
     * Send a success HTTP response body
     *
     * @param string $message
     * @param array|null $data
     * @param ApiStatus|int $code
     * @param bool $send_header
     * @return array{
     *    code: int,
     *    status: string,
     *    message: string,
     *    data: array|null
     * }
     */
    public static function res_success(string $message = "Successful", ?array $data = null, ApiStatus|int $code = ApiStatus::OK, bool $send_header = false) : array
    {
        return self::__res_send([
            "status" => "success",
            "message" => $message,
            "data" => $data,
        ], $code, $send_header);
    }

    /**
     * Send a warning HTTP response body
     *
     * @param string $message
     * @param array|null $data
     * @param ApiStatus|int $code
     * @param bool $send_header
     * @return array{
     *    code: int,
     *    status: string,
     *    message: string,
     *    data: array|null
     * }
     */
    public static function res_warning(string $message = "Something went wrong", ?array $data = null, ApiStatus|int $code = ApiStatus::NOT_ACCEPTABLE, bool $send_header = false) : array
    {
        return self::__res_send([
            "status" => "warning",
            "message" => $message,
            "data" => $data
        ], $code, $send_header);
    }

    /**
     * Send an error HTTP response body
     *
     * @param string $message
     * @param array|null $errors
     * @param ApiStatus|int $code
     * @param Throwable|null $exception
     * @param bool $send_header
     * @param bool $log_error
     * @return array{
     *    code: int,
     *    status: string,
     *    message: string,
     *    errors: array|null
     * }
     */
    public static function res_error(string $message = "An internal server error occurred", ?array $errors = null, ApiStatus|int $code = ApiStatus::CONFLICT, ?Throwable $exception = null, bool $send_header = false, bool $log_error =  true) : array
    {
        if((!CoreException::$DISPLAYED_ERROR && $code == ApiStatus::INTERNAL_SERVER_ERROR) && $log_error) {
            $last_error = error_get_last();
            $msg = "";

            if(!empty($last_error) && @$last_error['type'] != E_USER_WARNING){
                $msg = <<<BDY
                [LAST_ERROR]                
                {$last_error['message']} 
                <div style="font-weight: bold; color: cyan">{$last_error['file']} ({$last_error['line']})</div>
                BDY;
            }

            LayException::log($msg, $exception, self::class . "::res_error");
        }

        return self::__res_send([
            "status" => "error",
            "message" => $message,
            "errors" => $errors,
        ], $code, $send_header);
    }

}