<?php

namespace BrickLayer\Lay\Core\Api\Enums;

use BrickLayer\Lay\Libs\LayFn;

enum ApiStatus : int
{
    case SWITCHING_PROTOCOLS = 101;
    case OK = 200;
    case CREATED = 201;
    case ACCEPTED = 202;
    case NON_AUTHORITATIVE_INFORMATION = 203;
    case NO_CONTENT = 204;
    case RESET_CONTENT = 205;
    case PARTIAL_CONTENT = 206;
    case MULTIPLE_CHOICES = 300;
    case MOVED_PERMANENTLY = 301;
    case MOVED_TEMPORARILY = 302;
    case SEE_OTHER = 303;
    case NOT_MODIFIED = 304;
    case USE_PROXY = 305;
    case BAD_REQUEST = 400;
    case UNAUTHORIZED = 401;
    case PAYMENT_REQUIRED = 402;
    case FORBIDDEN = 403;
    case NOT_FOUND = 404;
    case METHOD_NOT_ALLOWED = 405;
    case NOT_ACCEPTABLE = 406;
    case PROXY_AUTHENTICATION_REQUIRED = 407;
    case REQUEST_TIMEOUT = 408;
    case CONFLICT = 409;
    case GONE = 410;
    case LENGTH_REQUIRED = 411;
    case PRECONDITION_FAILED = 412;
    case PAYLOAD_TOO_LARGE = 413;
    case URI_TOO_LARGE = 414;
    case UNSUPPORTED_MEDIA_TYPE = 415;
    case RANGE_NOT_SATISFIABLE = 416;
    case EXPECTATION_FAILED = 417;
    case IM_A_TEAPOT = 418;
    case MISDIRECTED_REQUEST = 421;
    case UNPROCESSABLE_CONTENT = 422;
    case LOCKED = 423;
    case FAILED_DEPENDENCY = 424;
    case TOO_EARLY = 425;
    case UPGRADE_REQUIRED = 426;
    case PRECONDITION_REQUIRED = 428;
    case TOO_MANY_REQUESTS = 429;
    case INTERNAL_SERVER_ERROR = 500;
    case NOT_IMPLEMENTED = 501;
    case BAD_GATEWAY = 502;
    case SERVICE_UNAVAILABLE = 503;
    case GATEWAY_TIMEOUT = 504;
    case HTTP_VERSION_NOT_SUPPORTED = 505;

    // Custom http code
    case SESSION_EXPIRED = 909;

    public static function extract_status(int|self $code, ?string $message = null) : string
    {
        if(!is_int($code)) {
            $name = $code->name;
            return $code->value . " " . str_replace("_", " ", $name);
        }

        $name = self::tryFrom($code)?->name;

        if($name)
            return "$code " . str_replace("_", " ", $name);

        return "$code $message";
    }

    public static function is_ok(int|self $code) : bool
    {
        if(is_int($code))
            return $code == self::OK->value;

        return $code == self::OK;
    }

    public static function is_okay(int|self $code) : bool
    {
        return self::is_ok($code);
    }

    public static function get_code(int|self $code) : int
    {
        return is_int($code) ? $code : $code->value;
    }

    public static function is_value(int|self $code, self $value) : bool
    {
        $code = is_int($code) ? $code : $code->value;

        return $code == $value->value;
    }

    public function respond(bool $overwrite = true, bool $log_sent = true) : false|int
    {
        return LayFn::http_response_code($this->value, $overwrite, $log_sent);
    }
}
