<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Primitives\Abstracts;

use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayArray;

abstract class ResourceHelper
{
    /**
     * @var array<string, mixed>
     */
    private array $mapped;

    /**
     * Define how you want the resource to be mapped
     * @param array<string, mixed>|object $data
     * @return array<string, mixed>
     */
    abstract protected function schema(array|object $data): array;

    /**
     * Returns the mapped schema for external usage
     * @return array<string, mixed>
     */
    public final function props(): array
    {
        if(!isset($this->data))
            LayException::throw_exception(
                "Trying to get props without setting `data`. You need to reinit " . static::class . " and set your data."
            );

        if(isset($this->mapped)) return $this->mapped;

        return $this->mapped = $this->schema($this->data);
    }

    /**
     * Properties to exclude from `props()` result
     * @param string ...$keys
     * @return $this
     */
    public final function except(string ...$keys) : static
    {
        $this->props();

        foreach ($keys as $key) {
            if(isset($this->mapped[$key]))
                unset($this->mapped[$key]);
        }

        return $this;
    }

    /**
     * Update the value of the Resource property only. You can't attach a new key.
     *
     * @param string $key If you want to append a value to an array property, attach [] to the key
     * @param mixed $value
     * @return void
     */
    public final function update(string $key, mixed $value) : void
    {
        $this->props();

        $append = str_contains($key, "[]");

        if ($append)
            $key = str_replace("[]", "", $key);

        if(!isset($this->mapped[$key]))
            LayException::throw_exception(
                "Trying to dynamically add a new property to your Resource. You can only do that in the `schema` function"
            );

        if($append)
            $this->mapped[$key][] = $value;
        else
            $this->mapped[$key] = $value;
    }

    /**
     * Maps a 2D array to the defined schema and returns the formatted array in 2D format
     * @param array<int, array<string, mixed>> $data
     * @param array<string> $except
     * @param null|callable $callback The result of this callback is returned rather than the props of the collection when used
     * @return array<int|string, array<string, mixed>>
     */
    public static final function collect(array $data, array $except = [], ?callable $callback = null): array
    {
        return LayArray::map($data, function($d) use ($except, $callback) {
            $data = (new static($d, false))->except(...$except)->props();

            if($callback)
                return $callback($data);

            return $data;
        });
    }

    /**
     * @param array<string, mixed>|object|null $data Can accept an array, stdclass, or you model class, since classes are objects in php
     * @param bool $fill Autofill the props value
     * Then you can access them directly like a property in your props method
     */
    public function __construct(protected array|object|null $data = null, bool $fill = true)
    {
        if($this->data && $fill)
            $this->props();
    }

    public function __get(string $name) : mixed
    {
        return $this->mapped[$name] ?? null;
    }

    public function __isset(string $name) : bool
    {
        return isset($this->mapped[$name]);
    }
}