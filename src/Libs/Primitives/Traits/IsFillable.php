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
    protected static array $columns;

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
            fn() => $record_or_id :
            fn() => static::db()->where(static::$primary_key_col, $record_or_id)->then_select();

        if($invalidate) {
            static::$columns = $by_id();
            return $this;
        }

        if(!isset(static::$columns)) {
            static::$columns = $by_id();
            return $this;
        }

        if(static::$columns[static::$primary_key_col] != $record_or_id)
            static::$columns = $by_id();

        return $this;
    }

    public function refresh(): self
    {
        $id = static::$columns[static::$primary_key_col] ?? null;

        if ($id)
            return $this->fill($id, true);

        return $this;
    }

    public function __get(string $key) : mixed
    {
        return static::$columns[$key] ?? null;
    }

    public function __isset($key) : bool
    {
        return isset(static::$columns[$key]);
    }

    public function props(): array
    {
        return static::$columns;
    }

    public function exists(): bool
    {
        return !empty(static::$columns);
    }

    public function is_empty(): bool
    {
        return !isset(static::$columns[static::$primary_key_col]);
    }

}