<?php
namespace BrickLayer\Lay\Libs\Primitives\Traits;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\Primitives\Abstracts\BaseModelHelper;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
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

    private int $join_index = -1;

    /**
     * @var array
     * @readonly
     */
    protected array $columns;

    /**
     * An array of table and columns that should be joined when filling model
     * @var array{ int, array{
     *     column: string,
     *     type: string,
     *     child_table: string,
     *     child_col: string,
     * } }
     */
    protected array $joinery = [];

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
        return $this->fill($record_id, $invalidate);
    }

    /**
     * Same as calling a new instance of the model
     * @param string|array|null|self $record_or_id
     * @param bool $invalidate
     * @return self
     */
    public function fill(string|array|null|self $record_or_id = null, bool $invalidate = false) : static
    {
        if(empty($record_or_id))
            return $this;

        if($record_or_id instanceof self)
            $record_or_id = $record_or_id->props();

        if(is_array($record_or_id) && !$invalidate) {
            $by_id = fn() => $this->set_columns($record_or_id);
        }
        else {
            $by_id = function () use ($record_or_id) {
                $db = static::db();

                $db->column($this->model_cols($db));

                $db->where(static::$table . "." . static::$primary_key_col, Escape::clean($record_or_id, EscapeType::STRIP_TRIM_ESCAPE));

                return $this->set_columns($db->then_select());
            };
        }

        if($invalidate)
            return $by_id();

        if(!isset($this->columns))
            return $by_id();

        if(@$this->columns[static::$primary_key_col] != $record_or_id)
            return $by_id();

        return $this;
    }

    /**
     * This returns the columns the models collects to form a record.
     * So all the joint tables, aliased columns are all here.
     * Best to use this while making a custom query select to have a consistent result
     *
     * @param SQL $db
     * @return string
     */
    protected function model_cols(SQL $db) : string
    {
        $this->relationships();
        $aliases = $this->props_alias();

        if (!empty($this->joinery)) {
            foreach ($this->joinery as $joint) {
                $db->join($joint['child_table'], $joint['type'])
                    ->on(
                        $joint['child_table'] . "." . $joint['child_col'],
                        static::$table . "." . $joint['column']
                    );
            }
        }

        $cols = static::$table . ".*";

        if (!empty($aliases)) {
            foreach ($aliases as $alias) {
                $cols .= "," . $alias[0] . (isset($alias[1]) ? " as " . $alias[1] : '');
            }
        }

        return $cols;
    }

    protected function set_columns(array $data) : static
    {
        $this->columns = $data;

        if(self::$use_delete)
            $this->cast(self::$primary_delete_col, "bool", false);

        //TODO: Delete depreciated code
        $this->props_schema($this->columns);
        //TODO: END Delete depreciated code

        $this->cast_schema();

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
     * @deprecated use cast_schema
     */
    protected function props_schema(array &$props) : void
    {
        // You can use `parse_prop` here
//        $this->cast("deleted", "bool", false);
//        $this->cast("permissions", "array", []);
    }

    /**
     * An alias for cast
     * @see cast
     * @deprecated use cast
     */
    protected function parse_prop(
        string          $key, string $type,
        mixed           $default_value = "@same@",
        string|callable $parser = "@nothing@"
    ) : void
    {
        $this->cast($key, $type, $default_value, $parser);
    }

    /**
     * Where to run your cast operations
     * @return void
     */
    protected function cast_schema() : void {}

    /**
     * A helper method used inside the `props_schema` method to parse props to a specific data type
     *
     * @param string $key Prop key
     * @param string $type Primitive datatypes like bool, objective, etc. Class string like an Enum can be used too.
     * @param mixed $default_value If prop key is not set or value is empty.
     * @param string|callable(mixed $value):mixed $parser When using a custom type, you must this and return the parsed value
     * @return void
     */
    protected function cast(
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

        if(($type == "array" || $type == "object") && $parser === "@nothing@") {
            $this->columns[$key] = json_decode($this->columns[$key], $type == "array");
            return;
        }

        $primitives = ["bool", "boolean", "int", "integer", "float", "double", "string"];

        if(!in_array($type, $primitives)) {
            if($parser === "@nothing@")
                LayException::throw("Using a custom type [$type], but no parser implemented for your model: [" . static::class . "]");

            if($this->columns[$key] === null) {
                $this->columns[$key] = $default_value;
                return;
            }

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

    /**
     * Define an alias for properties.
     * This is especially useful if you are creating a relationship between two models
     * @return array<int, array<int, string>>
     */
    protected function props_alias() : array
    {
        return [];

        /**
         * Example
         */
        return [
            [static::$table . ".created_by", "creator"],
            [static::$table . ".created_at", "submitted"],
        ];
    }

    /**
     * Define the relationships this model has if any.
     *
     * Take note: After creating relationships, you need to create props_alias for each column you want,
     * because Lay will not fetch any columns of the joint or children tables
     * @return void
     */
    protected function relationships() : void
    {
        return;

        /**
         * This is just an example of how to define a relationship
         */
        $this->join("dp")->to("Lay\\File\\Model");
        $this->join("auth_id")->to("Lay\\User\\Model", "my_id");
    }

    /**
     * This is the start of a relationship definition.
     * Specify the column to be joints and call the `->to` method to complete the process
     * @param string $column A column of this model
     */
    protected function join(string $column, string $type = "left") : static
    {
        $this->join_index++;

        $this->joinery[$this->join_index] = [
            "column" => $column,
            "type" => $type
        ];

        return $this;
    }

    /**
     * Connect this model to a dependent model based on a particular column (joint_col)
     * @param BaseModelHelper|string $model
     * @param string $joint_col
     * @return void
     */
    protected function to(BaseModelHelper|string $model, string $joint_col = "id") : void
    {
        $this->joinery[$this->join_index]['child_table'] = $model::$table;
        $this->joinery[$this->join_index]['child_col'] = $joint_col;
    }

}