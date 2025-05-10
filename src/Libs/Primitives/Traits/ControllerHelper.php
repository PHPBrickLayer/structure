<?php

namespace  BrickLayer\Lay\Libs\Primitives\Traits;

use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\CoreException;
use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayFn;
use BrickLayer\Lay\Libs\Primitives\Abstracts\RequestHelper;
use BrickLayer\Lay\Libs\Primitives\Abstracts\ResourceHelper;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use Throwable;

trait ControllerHelper {
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
     * @see RequestHelper::request()
     */
    public static function request(bool $throw_error = true, bool $as_array = false, bool $invalidate_cache = false): array|object
    {
        return RequestHelper::request($throw_error, $as_array, $invalidate_cache);
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
     * @param array|null|ResourceHelper $data
     * @param ApiStatus|int $code
     * @param bool $send_header
     * @return array{
     *    code: int,
     *    status: string,
     *    message: string,
     *    data: array|null
     * }
     */
    public static function res_success(string $message = "Successful", array|null|ResourceHelper $data = null, ApiStatus|int $code = ApiStatus::OK, bool $send_header = false) : array
    {
        return self::__res_send([
            "status" => "success",
            "message" => $message,
            "data" => $data instanceOf ResourceHelper ? $data->props() : $data,
        ], $code, $send_header);
    }

    /**
     * Send a warning HTTP response body
     *
     * @param string $message
     * @param array|null|ResourceHelper $data
     * @param ApiStatus|int $code
     * @param bool $send_header
     * @return array{
     *    code: int,
     *    status: string,
     *    message: string,
     *    data: array|null
     * }
     */
    public static function res_warning(string $message = "Something went wrong", array|null|ResourceHelper $data = null, ApiStatus|int $code = ApiStatus::NOT_ACCEPTABLE, bool $send_header = false) : array
    {
        return self::__res_send([
            "status" => "warning",
            "message" => $message,
            "data" => $data instanceOf ResourceHelper ? $data->props() : $data,
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
     *
     * @return (array|int|null|string)[]
     *
     * @psalm-return array{code: int, status: string, message: string, data: array|null}
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