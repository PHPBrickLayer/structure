<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Orm\Traits;


trait Functions
{
    final public function uuid(): string
    {
        return $this->query("SELECT UUID()")[0];
    }

    final public function contains(string $column, mixed $value) : string
    {
        return "JSON_CONTAINS($column, '\"$value\"', '$')";
    }

    final public function extract(string $column, mixed $key, bool $unquote = true) : string
    {
        $x = "JSON_EXTRACT($column, '$.$key')";

        if($unquote)
            return "JSON_UNQUOTE($x)";

        return $x;
    }
}