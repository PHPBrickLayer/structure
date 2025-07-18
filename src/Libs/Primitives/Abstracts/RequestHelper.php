<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Primitives\Abstracts;

use BrickLayer\Lay\Core\Exception;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\Primitives\Traits\ValidateCleanMap;

abstract class RequestHelper
{
    use ValidateCleanMap;

    /**
     * @var array<string, mixed>
     */
    private array $data;

    public readonly ?string $error;

    /**
     * Cached request form data of an already parsed $_POST data
     * @var array<string, mixed>|object
     */
    private static array|object $cached_request_fd;

    abstract protected function rules(): void;

    /**
     * @return array<string, mixed>
     */
    public final function props() : array
    {
        return $this->data;
    }

    /**
     * Add a new property dynamically to the props of this resource.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public final function new_key(string $key, mixed $value) : void
    {
        $append = str_contains($key, "[]");

        if($append)
            $key = str_replace("[]", "", $key);

        if(isset($this->data[$key]))
            LayException::throw_exception(
                "Trying to update an existing property to your Request. You can only do that in the `update` function"
            );

        if($append)
            $this->data[$key][] = $value;
        else
            $this->data[$key] = $value;
    }

    /**
     * Update the value of the Request property. You can't add a new key.
     *
     * @param string $key If you want to append a value to an array property, attach [] to the key
     * @param mixed $value
     * @return void
     */
    public final function update(string $key, mixed $value) : void
    {
        $append = str_contains($key, "[]");

        if ($append)
            $key = str_replace("[]", "", $key);

        if(!isset($this->data[$key]))
            LayException::throw_exception(
                "Trying to dynamically add a new property to your Resource. You can only do that in the `post_validate` or `new_key` function"
            );

        if($append)
            $this->data[$key][] = $value;
        else
            $this->data[$key] = $value;
    }

    /**
     * Properties to exclude from `props()` result
     * @param string ...$keys
     * @return $this
     */
    public final function except(string ...$keys) : static
    {
        foreach ($keys as $key) {
            if(isset($this->data[$key]))
                unset($this->data[$key]);
        }

        return $this;
    }

    public final function unset(string ...$keys) : static
    {
        return $this->except(...$keys);
    }

    /**
     * Quickly get an already validate value safely from the `vcm_data` array inside the request helper class.
     * @param string $key
     * @return mixed
     */
    protected final function get(string $key) : mixed
    {
        return self::vcm_data()[$key] ?? null;
    }

    /**
     * By default, it's responsible for digesting the request and setting the default rules
     */
    protected function pre_validate() : void
    {
        self::vcm_start(self::request(as_array: true), [
            'required' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function post_validate(array $data): array
    {
        return $data;
    }

    protected final function validate(): static
    {
        $this->pre_validate();

        $this->rules();

        if ($this->error = self::vcm_errors())
            return $this;

        $this->data = self::vcm_data();

        $this->data = $this->post_validate($this->data);

        return $this;
    }

    public function __construct(bool $validate = true)
    {
        static::$VCM_INSTANCE = $this;

        if($validate)
            $this->validate();
    }

    public final function __get(string $key) : mixed
    {
        return $this->data[$key] ?? null;
    }

    public final function __isset(string $key) : bool
    {
        return isset($this->data[$key]);
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
     * @param null|array<string|int, mixed> $mock_data You can use this to mock data for testing and more
     * @return array<string, mixed>|object
     * @throws \Exception
     */
    public static function request(
        bool $throw_error = true,
        bool $as_array = false,
        bool $invalidate_cache = false,
        ?array $mock_data = null
    ): array|object
    {
        if(isset(self::$cached_request_fd) and !$invalidate_cache)
            return self::$cached_request_fd;

        if($mock_data)
            return self::$cached_request_fd = $as_array ? $mock_data : (object) $mock_data;

        $req_method = $_SERVER['REQUEST_METHOD'] ?? 'NON-SPECIFIED';

        $data = file_get_contents("php://input");

        if($req_method != "POST") {
            $content_type = explode(";", LayConfig::get_header('Content-Type'))[0];

            if($req_method == "PUT" && $content_type == "multipart/form-data")
                Exception::throw_exception("PUT method is not allowed for multipart/form-data requests", "LayObject::ERR");

//            parse_str($data, $data);
        }

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
            Exception::throw_exception($msg, "LayObject::ERR");

        self::$cached_request_fd =  json_decode($data, $as_array) ?? $post;

        return self::$cached_request_fd;
    }
}