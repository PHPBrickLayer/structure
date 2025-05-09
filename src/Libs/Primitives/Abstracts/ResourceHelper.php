<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Primitives\Abstracts;

use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayArray;

abstract class ResourceHelper
{
    /**
     * Define how you want the resource to be mapped
     * @param array|object $data
     * @return array
     */
    abstract protected function schema(array|object $data): array;

    /**
     * Returns the mapped schema for external usage
     * @return array
     */
    public final function props(): array
    {
        if(!isset($this->data))
            LayException::throw_exception(
                "Trying to get props without setting `data`. You need to reinit " . static::class . " and set your data."
            );

        return $this->data = $this->schema($this->data);
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
     * Maps a 2D array to the defined schema and returns the formatted array in 2D format
     * @return array<int|string, array>
     */
    public final function collect(): array
    {
        if(!isset($this->data))
            LayException::throw_exception(
                "Trying to get collection without setting `data`. You need to reinit " . static::class . " and set your data."
            );

        return LayArray::map($this->data, fn($d) => $this->schema($d));
    }

    /**
     * @param array|object $data Can accept an array, stdclass, or you model class, since classes are objects in php
     * @param bool $fill Autofill the props value
     * Then you can access them directly like a property in your props method
     */
    public function __construct(protected array|object $data, bool $fill = true)
    {
        if($fill)
            $this->props();

        return $this;
    }

    public function __get(string $name) : mixed
    {
        if(is_object($this->data))
            return $this->data->$name ?? null;

        return $this->data[$name] ?? null;
    }

    public function __isset($name) : bool
    {
        if(is_object($this->data))
            return isset($this->data->$name);

        return isset($this->data[$name]);
    }
}