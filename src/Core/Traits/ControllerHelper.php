<?php

namespace  BrickLayer\Lay\Core\Traits;

use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\CoreException;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayObject;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use Throwable;

trait ControllerHelper {
    public static function cleanse(mixed &$value, EscapeType $type = EscapeType::STRIP_TRIM_ESCAPE, bool $strict = true)
    {
        $value = $value ? Escape::clean($value, $type, ['strict' => $strict]) : "";
        return $value;
    }

    public static function request(bool $throw_error = true): bool|null|object
    {
        return LayObject::new()->get_json($throw_error);
    }

    /**
     * Send an array response with a specific status code.
     *
     * @param mixed $data The data to be sent in the response.
     * @param int|ApiStatus $code The HTTP status code for the response.
     * @return array
     */
    private static function __res_send(array $data, ApiStatus|int $code = ApiStatus::OK) : array
    {
        $code = is_int($code) ? $code : $code->value;

        http_response_code($code);

        $data['code'] = $code;
        return $data;
    }

    public static function res_success(string $message = "Successful", array $data = null, ApiStatus|int $code = ApiStatus::OK) : array
    {
        return self::__res_send([
            "status" => "success",
            "message" => $message,
            "data" => $data,
        ], $code);
    }

    public static function res_warning(string $message = "Something went wrong", ApiStatus|int $code = ApiStatus::NOT_ACCEPTABLE) : ?array
    {
        return self::__res_send([
            "status" => "warning",
            "message" => $message,
        ], $code);
    }

    public static function res_error(string $message = "An internal server error occurred", ?array $errors = null, ApiStatus|int $code = ApiStatus::INTERNAL_SERVER_ERROR, ?Throwable $exception = null) : ?array
    {
        if(CoreException::$DISPLAYED_ERROR) return null;

        if($code == ApiStatus::INTERNAL_SERVER_ERROR && $errors === null) {
            $last_error = error_get_last();

            if(!empty($last_error) && @$last_error['type'] != E_USER_WARNING){
                $msg = <<<BDY
                [Response Error]
                                
                {$last_error['message']} 
                <div style="font-weight: bold; color: cyan">{$last_error['file']} ({$last_error['line']})</div>
                BDY;

                LayException::log($msg, $exception, "CaughtJSONError");
            }
        }

        return self::__res_send([
            "status" => "error",
            "message" => $message,
            "errors" => $errors,
        ], $code);
    }

}