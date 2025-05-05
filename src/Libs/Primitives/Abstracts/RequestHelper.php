<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Primitives\Abstracts;

use BrickLayer\Lay\Libs\Primitives\Traits\ControllerHelper;
use BrickLayer\Lay\Libs\Primitives\Traits\ValidateCleanMap;

abstract class RequestHelper
{
    use ValidateCleanMap, ControllerHelper;

    public readonly string $error;
    private array $data;

    abstract protected function rules(): void;

    public final function props() : array
    {
        return $this->data;
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

    public function __construct()
    {
        return $this->validate();
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