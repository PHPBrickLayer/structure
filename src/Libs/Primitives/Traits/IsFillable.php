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

    /**
     * @var array
     * @readonly
     */
    protected array $columns;

    /**
     * An array of table and columns that should be joined when filling model
     * @var array<int, array{
     *     column: string,
     *     type: string,
     *     child_table: string,
     *     child_table_alias: string,
     *     child_col: string,
     * }>
     */
    private array $joinery = [];
    private int $join_index = -1;

    protected string $join_table;

    /**
     * The cached columns selection and all the necessary aliases. To avoid looping all the time
     * @var string
     */
    private string $cached_cols;

    /**
     * @var array<int, array{
     *     column: string,
     *     alias: string,
     *     table: string
     * }>
     */
    private array $aliases = [];

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

        if(is_array($record_or_id)) {
            if($invalidate)
                LayException::throw("Trying to invalidate Model: " . static::class . " but sent an array instead of a string as the record_id");

            return $this->set_columns($record_or_id);
        }

        $db = static::db();

        $db->column($this->fillable($db));

        $db->where(static::$table . "." . static::$primary_key_col, Escape::clean($record_or_id, EscapeType::STRIP_TRIM_ESCAPE));

        return $this->set_columns($db->then_select());
    }


    /**
     * Every action that should take place before a model is filled.
     *
     * You can:
     *  - Define the relationships this model has with other models if any.
     *  - Alias column names if necessary
     *
     * Take note: After defining relationships, you need to include the columns you want using the alias method,
     * because Lay will not fetch any columns of the joint or children models
     * @return void
     */
    protected function prefill() : void
    {
        return;

        /**
         * This is just an example of how to define a relationship
         */

        // Joining a child table to the primary model table
        $this->join("Lay\\User\\Model", "auth_id")
            ->use("last_name")
            ->use("first_name", "name");

        // Joining a child table to another table that isn't the primary model table
        $this->join("Lay\\User\\Model", "auth_id")->to("Lay\\User\\Model", "my_id")
            ->use("last_name")
            ->use("first_name", "name");
    }

    /**
     * This returns a string of the columns the models requests from the db when trying to fill a model.
     * So all the joint tables, aliased columns are all here.
     * Best to use this while making a custom query select to have a consistent result
     *
     * @param SQL $db
     * @return string
     */
    protected final function fillable(SQL $db) : string
    {
        $this->prefill();

        $cols = $this->cached_cols ?? static::$table . ".*";

        if (!empty($this->joinery)) {
            foreach ($this->joinery as $index => $joint) {
                $child = $joint['child'];
                $parent = $joint['parent'];

                $db->join($child['table'] . " as " . $child['table_alias'], $joint['type'])
                    ->on(
                        $child['table_alias'] . "." . $child['on'],
                        $parent['table'] . "." . $parent['on']
                    );

                if (!isset($this->cached_cols) && !empty($this->aliases[$index])) {

                    foreach ($this->aliases[$index] as $alias) {
                        $a = $alias['alias'] ? " as " . $alias['alias'] : '';

                        $cols .= "," . $alias['table'] . "." . $alias['column'] . $a;
                    }
                }
            }
        }

        return $this->cached_cols = $cols;
    }

    private function set_columns(array $data) : static
    {
        $this->columns = $data;

        if(self::$use_delete)
            $this->cast(self::$primary_delete_col, "bool", false);

        $this->cast_schema();

        return $this;
    }

    private function unfill() : static
    {
        $this->columns = [];
        return  $this;
    }

    public final function refresh(): static
    {
        $id = $this->columns[static::$primary_key_col] ?? null;

        if ($id)
            return $this->fill($id, true);

        return $this->unfill();
    }

    public final function __get(string $key) : mixed
    {
        return $this->columns[$key] ?? null;
    }

    public final function __isset($key) : bool
    {
        return isset($this->columns[$key]);
    }

    public final function props(): array
    {
        return $this->columns;
    }

    public final function exists(): bool
    {
        return !$this->is_empty();
    }

    public final function is_empty(): bool
    {
        return !isset($this->columns[static::$primary_key_col]);
    }

    /**
     * Where to run your cast operations
     * @return void
     */
    protected function cast_schema() : void {}

    /**
     * A helper method used inside the `cast_schema` method to cast a prop to a specific data type
     *
     * @param string $key Prop key
     * @param string $type Primitive datatypes like bool, objective, etc. Class string like an Enum can be used too.
     * @param mixed $default_value If prop key is not set or value is empty.
     * @param string|callable(mixed $value):mixed $parser When using a custom type, you must this and return the parsed value
     * @return void
     */
    protected final function cast(
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
     * @param bool $throw
     * @return void
     * @throws \Exception if you try to assign a new prop to the model
     */
    public final function update_prop(string $key, mixed $value, bool $throw = true): void
    {
        if(!isset($this->columns[$key]) && $throw)
            LayException::throw_exception(
                "Trying to dynamically add a new property to your Model. This is an illegal operation"
            );

        $this->columns[$key] = $value;
    }

    /**
     * Connect your model to dependent models or tables, so that the necessary props can be populated
     *
     * @param BaseModelHelper|string $model Child table/model to join.
     * It's a model/class-string with the static property `::$table` or a regular table string
     *
     * @param string $on The anchor column on the primary table/model the child table should be joint on.
     * @param string $to The column the child table should be joint to. The default is id
     * @param string $type Type of join (left, right, inner)
     */
    protected final function join(BaseModelHelper|string $model, string $on, string $to = "id", string $type = "left", ?string $table_alias = null) : static
    {
        $this->join_index++;

        $table_alias ??= "ct" . $this->join_index;

        if(is_string($model) && !str_contains("\\", $model))
            $table = $model;
        else
            $table = $model::$table;

        $this->join_table = $table_alias;

        $this->joinery[$this->join_index] = [
            "type" => $type,
            "child" => [
                "table" => $table,
                "table_alias" => $table_alias,
                "on" => $to,
            ],
            "parent" => [
                "on" => $on,
                "table" => static::$table
            ]
        ];

        return $this;
    }

    /**
     * Use this to connect a child joining table to its parent.
     *
     * If you are not joining a table to a primary model table,
     * then this method will help Lay understand the model you wish to make the parent table
     *
     * This method cannot be called on its own, it must be called after a `->join()` call
     * and before a `->use()` call.
     * So it's `->join()->to()->use()`
     *
     * @param BaseModelHelper|string $model The new primary model or table.
     * It's a model/class-string with the static property `::$table` or a regular table string
     *
     * @param string $on The column the child table will be joint on. The default is `id` even on the `->join()` method
     */
    protected final function to(BaseModelHelper|string $model, string $on = "id") : self
    {
        if(is_string($model) && !str_contains($model, "\\",))
            $table = $model;
        else
            $table = $model::$table;

        $this->joinery[$this->join_index]['parent']['table'] = $table;
        $this->joinery[$this->join_index]['child']['on'] = $on;

        return $this;
    }

    /**
     * Depends on `->to` || `->join`.
     * @requires $this->to() || $this->join()
     *
     * Define a column that should be included in the model after a child model has been joint.
     *
     * @param string $column The column of the new table/model being joint. Aka the child column
     * @param string|null $alias An alias this column should be returned as
     */
    protected final function use(string $column, ?string $alias = null) : self
    {
        $this->aliases[$this->join_index][] = [
            "column" => $column,
            "alias" => $alias,
            "table" => $this->joinery[$this->join_index]['child']['table_alias'],
        ];

        return $this;
    }

}
