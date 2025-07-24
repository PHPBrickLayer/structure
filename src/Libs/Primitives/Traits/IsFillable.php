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

        if(is_array($record_or_id) && !$invalidate) {
            $by_id = fn() => $this->set_columns($record_or_id);
        }
        else {
            $by_id = function () use ($record_or_id) {
                $db = static::db();

                $db->column($this->fillable($db));

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
        $this->join("auth_id")->to("Lay\\User\\Model", "my_id")
            ->use("last_name")                        // Forcing the inclusion of last_name in the props
            ->use("first_name", "name");        // Include a column as an alias
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

        if (!empty($this->joinery)) {
            foreach ($this->joinery as $joint) {
                $db->join($joint['child_table'] . " as " . $joint['child_table_alias'], $joint['type'])
                    ->on(
                        $joint['child_table_alias'] . "." . $joint['child_col'],
                        static::$table . "." . $joint['column']
                    );
            }
        }

        $cols = static::$table . ".*";

        if (!empty($this->aliases)) {
            foreach ($this->aliases as $alias) {
                $a = $alias['alias'] ? " as " . $alias['alias'] : '';

                $cols .= "," . $alias['table'] . "." . $alias['column'] . $a;
            }
        }

        return $cols;
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

        return $this;
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
     * @return void
     * @throws \Exception if you try to assign a new prop to the model
     */
    public final function update_prop(string $key, mixed $value): void
    {
        if(!isset($this->columns[$key]))
            LayException::throw_exception(
                "Trying to dynamically add a new property to your Model. This is an illegal operation"
            );

        $this->columns[$key] = $value;
    }

    /**
     * This is the start of a relationship definition.
     * Specify the column to be joints and call the `->to` method to complete the process
     * @param string $on A column of this model
     */
    protected final function join(string $on, string $type = "left") : static
    {
        $this->join_index++;

        $this->joinery[$this->join_index] = [
            "column" => $on,
            "type" => $type
        ];

        return $this;
    }

    /**
     * Connect this model to a dependent model based on a particular column (joint_col)
     * @param BaseModelHelper|string $model
     * @param string $on Column to join a child model on
     */
    protected final function to(BaseModelHelper|string $model, string $on = "id", ?string $table_alias = null) : self
    {
        $table_alias ??= "ct" . $this->join_index;

        $this->joinery[$this->join_index]['child_table'] = $model::$table;
        $this->joinery[$this->join_index]['child_table_alias'] = $table_alias;
        $this->joinery[$this->join_index]['child_col'] = $on;

        return $this;
    }

    /**
     * Depends on `->to`.
     * @requires $this->to()
     *
     * Define a column that should be included in the model after a child model has been joint.
     *
     * @param string $column The column of the new table/model being joint. Aka the child column
     * @param string|null $alias An alias this column should be returned as
     */
    protected final function use(string $column, ?string $alias = null) : self
    {
        $this->aliases[$this->join_index] = [
            "column" => $column,
            "alias" => $alias,
            "table" => $this->joinery[$this->join_index]['child_table_alias'],
        ];

        return $this;
    }

}