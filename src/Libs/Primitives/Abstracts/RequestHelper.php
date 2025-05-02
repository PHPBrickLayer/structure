<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Primitives\Abstracts;

use BrickLayer\Lay\Libs\Primitives\Traits\ControllerHelper;
use BrickLayer\Lay\Libs\Primitives\Traits\ValidateCleanMap;

abstract class RequestHelper
{
    use ValidateCleanMap, ControllerHelper;

    public ?string $error = null;
    public array $data;

    abstract protected function validation_rules(): void;

    /**
     * By default, it's responsible for digesting the request and setting the default rules
     */
    protected function before_validation() : void
    {
        self::vcm_start(self::request(), [
            'required' => true,
        ]);
    }

    protected function after_validation(array $data): array
    {
        return $data;
    }

    public final function validate(): static
    {
        $this->before_validation();

        $this->validation_rules();

        if ($errors = self::vcm_errors()) {
            $this->error = $errors;
            return $this;
        }

        $this->data = $this->after_validation(self::vcm_data());

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