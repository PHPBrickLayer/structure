<?php
namespace BrickLayer\Lay\Libs\Primitives\Traits;
use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Orm\SQL;
use JetBrains\PhpStorm\ExpectedValues;

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
     * This is basically the column you use for soft delete in your app
     * @var string
     * @abstract Overwrite when necessary
     */
    protected static string $primary_delete_col = "deleted";

    /**
     * Use this to let your model know when running a select query,
     * if it should fetch only rows that have not been "deleted" [true] or every row [false]
     *
     * @var bool
     */
    protected static bool $use_delete = true;

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
    public function fill(string|array|null $record_or_id = null, bool $invalidate = false) : static
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

        if(@$this->columns[static::$primary_key_col] != $record_or_id)
            $this->columns = $by_id();

        return $this;
    }

    protected function unfill() : static
    {
        $this->columns = [];
        return  $this;
    }

    public function refresh(): static
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
        if(self::$use_delete)
            $this->parse_prop(self::$primary_delete_col, "bool", false);

        $this->props_schema($this->columns);

        return $this->columns;
    }

    public function exists(): bool
    {
        return !empty($this->columns);
    }

    public function is_empty(): bool
    {
        return !isset($this->columns[static::$primary_key_col]);
    }

    /**
     * Use this to format the final props. It is good practice to ensure your props name and data type match
     * what you defined at the beginning of your class in the `property` attribution.
     *
     * @param array $props
     * @return void
     * @abstract
     */
    protected function props_schema(array &$props) : void
    {
        // You can use `parse_prop` here
    }

    /**
     * A helper class used inside the props_schema to parse props to a specific data type
     * @param string $key
     * @param string $type
     * @param mixed $default
     * @return void
     */
    protected function parse_prop(
        string $key,
        #[ExpectedValues(["bool", "boolean", "int", "integer", "float", "double", "string", "array", "object", "null"])] string $type,
        mixed $default = "@same@"
    ) : void
    {
        if(!isset($this->columns[$key])) {
            if($default === "@same@")
                LayException::throw("Key [$key] is not set, and a default value was not presented. '@same@' cannot be used as a default value");

            $this->columns[$key] = $default;
            return;
        }

        $old_type = gettype($this->columns[$key]);

        if($old_type == $type)
            return;

        if($type == "array") {
            $this->columns[$key] = json_decode($this->columns[$key], true);
            return;
        }

        if($type == "object") {
            $this->columns[$key] = json_decode($this->columns[$key]);
            return;
        }

        settype($this->columns[$key], $type);
    }

}