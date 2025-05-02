<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Primitives\Abstracts;

use BrickLayer\Lay\Libs\LayArray;

abstract readonly class ResourceHelper
{
    /**
     * Define how you want the resource to be mapped
     * @return array
     */
    abstract public function props(): array;

    /**
     * Maps a 2D array to the defined schema and returns the formatted array
     * @param array $data
     * @return array<int|string, array>
     */
    public final function collection(array $data): array
    {
        return LayArray::map($data, fn($d) => new static($d));
    }

    /**
     * @param array|object $data Can accept an array, stdclass, or you model class, since classes are objects in php
     * Then you can access them directly like a property in your props method
     */
    public function __construct(private array|object $data)
    {
        return $this->props();
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