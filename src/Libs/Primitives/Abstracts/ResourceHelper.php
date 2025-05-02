<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Primitives\Abstracts;

use BrickLayer\Lay\Libs\LayArray;

abstract class ResourceHelper
{
    private static array $resource;

    /**
     * Define how you want the resource to be mapped
     * @return array
     */
    abstract protected function schema(): array;

    /**
     * Returns a 1D array of the mapped resource
     * @return array
     */
    public final function props(): array
    {
        return self::$resource;
    }

    /**
     * Maps a 2D array to the defined schema and returns the formatted array
     * @param array $data
     * @return array<int|string, array>
     */
    public static final function collection(array $data): array
    {
        return LayArray::map($data, fn($d) => new static($d));
    }

    public function __construct(protected array|object $data)
    {
        self::$resource = $this->schema();
    }

    public function __get(string $name) : mixed
    {
        return self::$resource[$name] ?? null;
    }

    public function __isset($name) : bool
    {
        return isset(self::$resource[$name]);
    }
}