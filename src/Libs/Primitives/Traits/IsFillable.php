<?php
namespace BrickLayer\Lay\Libs\Primitives\Traits;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
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
     * @param string|array|null|self $record_id The primary key column value or an array columns related to the given model
     * @param bool $invalidate Records are cached by default, so this makes the model refetch data from the db
     */
    public function __construct(string|array|null|self $record_id = null, bool $invalidate = false)
    {
        if(empty($record_id))
            return $this;

        if($record_id instanceof self)
            $record_id = $record_id->props();

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
            fn() => $this->set_columns($record_or_id) :
            fn() => $this->set_columns(static::db()->where(static::$primary_key_col, $record_or_id)->then_select());

        if($invalidate)
            return $by_id();

        if(!isset($this->columns))
            return $by_id();

        if(@$this->columns[static::$primary_key_col] != $record_or_id)
            return $by_id();

        return $this;
    }

    protected function set_columns(array $data) : static
    {
        $this->columns = $data;

        if(self::$use_delete)
            $this->parse_prop(self::$primary_delete_col, "bool", false);

        $this->props_schema($this->columns);

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
        return $this->columns;
    }

    public function exists(): bool
    {
        return !$this->is_empty();
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
//        $this->parse_prop("deleted", "bool", false);
//        $this->parse_prop("permissions", "array", []);
    }

    /**
     * A helper method used inside the `props_schema` method to parse props to a specific data type
     *
     * @param string $key Prop key
     * @param string $type Primitive datatypes like bool, objective, etc. Class string like an Enum can be used too.
     * @param mixed $default_value If prop key is not set or value is empty.
     * @param string|callable(mixed $value):mixed $parser When using a custom type, you must this and return the parsed value
     * @return void
     */
    protected function parse_prop(
        string          $key, string $type,
        mixed           $default_value = "@same@",
        string|callable $parser = "@nothing@"
    ) : void
    {
        if(!isset($this->columns[$key])) {
            if($default_value === "@same@")
                LayException::throw("Key [$key] is not set, and a default value was not presented. '@same@' cannot be used as a default value");

            $this->columns[$key] = $default_value;
            return;
        }

        $old_type = gettype($this->columns[$key]);
        $is_same_type = $this->columns[$key] instanceof $type;

        if($old_type == $type || $is_same_type)
            return;

        if($type == "array" || $type == "object") {
            $this->columns[$key] = json_decode($this->columns[$key], $type == "array");
            return;
        }

        $primitives = ["bool", "boolean", "int", "integer", "float", "double", "string", "array", "object"];

        if(!in_array($type, $primitives)) {
            if($parser === "@nothing@")
                LayException::throw("Using a custom type [$type], but no parser implemented for your model: [" . static::class . "]");

            $this->columns[$key] = $parser($this->columns[$key]);
            return;
        }

        settype($this->columns[$key], $type);
    }

    /**
     * Use this to update the values of your model props
     * @param string $key
     * @param mixed $value
     * @return void
     * @throws \Exception if you try to assign a new prop to the model
     */
    public function update_prop(string $key, mixed $value): void
    {
        if(!isset($this->columns[$key]))
            LayException::throw_exception(
                "Trying to dynamically add a new property to your Model. This is an illegal operation"
            );

        $this->columns[$key] = $value;
    }

}