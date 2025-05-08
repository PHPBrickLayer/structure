<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Primitives\Abstracts;

use BrickLayer\Lay\Libs\Primitives\Traits\ControllerHelper;
use BrickLayer\Lay\Libs\Primitives\Traits\ValidateCleanMap;

abstract class RequestHelper
{
    use ValidateCleanMap, ControllerHelper;

    public readonly ?string $error;
    private array $data;

    abstract protected function rules(): void;

    public final function props() : array
    {
        return $this->data;
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

    /**
     * By default, it's responsible for digesting the request and setting the default rules
     */
    protected function pre_validate() : void
    {
        self::vcm_start(self::request(), [
            'required' => false,
        ]);
    }

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

        $this->data = $this->post_validate(self::vcm_data());

        return $this;
    }

    public function __construct(bool $validate = true)
    {
        static::$VCM_INSTANCE = $this;

        if($validate)
            return $this->validate();

        return $this;
    }

    public final function __get(string $key) : mixed
    {
        return $this->data[$key] ?? null;
    }

    public final function __isset($key) : bool
    {
        return isset($this->data[$key]);
    }

}