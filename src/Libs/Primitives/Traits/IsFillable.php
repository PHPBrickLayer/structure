<?php
namespace BrickLayer\Lay\Libs\Primitives\Traits;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Orm\SQL;

/**
 * Used in models to mark them as fillable.
 * A model cannot be fillable and also a singleton
 */
trait IsFillable {
    /**
     * @var string
     * @abstract Must overwrite
     */
    public static string $table;

    /**
     * @var string
     * @abstract Overwrite when necessary
     */
    protected static string $primary_key_col = "id";

    /**
     * @var array
     * @readonly
     */
    protected array $columns;

    public static function db() : SQL
    {
        return LayConfig::get_orm()->open(static::$table);
    }

    /**
     * @param string|array|null $record_id The primary key column value or an array columns related to the given model
     * @param bool $invalidate Records are cached by default, so this makes the model refetch data from the db
     */
    public function __construct(string|array|null $record_id = null, bool $invalidate = false)
    {
        if(empty($record_id))
            return $this;

        return $this->fill($record_id, $invalidate);
    }

    /**
     * Same as calling a new instance of the model
     * @param string|array|null $record_or_id
     * @param bool $invalidate
     * @return self
     */
    public function fill(string|array|null $record_or_id = null, bool $invalidate = false) : self
    {
        if(empty($record_or_id))
            return $this;

        $by_id = is_array($record_or_id) && !$invalidate ?
            fn() => $this->props_schema($record_or_id) :
            fn() => $this->props_schema(static::db()->where(static::$primary_key_col, $record_or_id)->then_select());

        if($invalidate) {
            $this->columns = $by_id();
            return $this;
        }

        if(!isset($this->columns)) {
            $this->columns = $by_id();
            return $this;
        }

        if($this->columns[static::$primary_key_col] != $record_or_id)
            $this->columns = $by_id();

        return $this;
    }

    public function refresh(): self
    {
        $id = $this->columns[static::$primary_key_col] ?? null;

        if ($id)
            return $this->fill($id, true);

        return $this;
    }

    public function __get(string $key) : mixed
    {
        return $this->columns[$key] ?? null;
    }

    public function __isset($key) : bool
    {
        return isset($this->columns[$key]);
    }

    public function props(): array
    {
        return $this->props_schema($this->columns);
    }

    public function exists(): bool
    {
        return !empty($this->columns);
    }

    public function is_empty(): bool
    {
        return !isset($this->columns[static::$primary_key_col]);
    }

    protected function props_schema(array $props) : array
    {
        return $props;
    }

}