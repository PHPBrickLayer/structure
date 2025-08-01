<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Primitives\Abstracts;

use BrickLayer\Lay\Core\LayException;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\Primitives\Traits\IsFillable;
use BrickLayer\Lay\Orm\SQL;
use Closure;

abstract class BaseModelHelper
{
    use IsFillable;

    protected bool $enable_created_by = true;

    protected static string $primary_created_by_col = "created_by";
    protected static string $primary_created_at_col = "created_at";

    protected static string $primary_updated_by_col = "updated_by";
    protected static string $primary_updated_at_col = "updated_at";

    private bool $debug_mode = false;

    /**
     * @var array<int, callable(SQL):self>
     */
    private array $pre_run = [];

    public function uuid() : string
    {
        return static::db()->uuid();
    }

    /**
     * This instructs the model to echo whatever base model query so you can inspect it.
     * @return $this
     */
    public function debug() : static
    {
        $this->debug_mode = true;
        return $this;
    }

    /**
     * Pass the instance of the model to the return value of a select record, so that classes like ResourceHelper will use it
     * @param SQL $db
     * @return void
     */
    public function as_model(SQL $db) : void
    {
        $db->each(function($x) {
            $x['__LAY_MODEL__'] = $this;

            return $x;
        });
    }

    /**
     * @param callable(self):mixed $each
     * @return self
     */
    public function each(callable $each) : self
    {
        return $this->pre_run(
            function(SQL $db) use ($each) {
                $db->each(
                    fn($data): mixed => $each($this->fill($data))
                );
            }
        );
    }

    public function pre_run(callable $db_callback) : self
    {
        $this->pre_run[] = $db_callback;
        return $this;
    }

    protected function pre_get(SQL $db) : void {}
    protected function pre_add(SQL $db, array &$columns) : void {}
    protected function pre_edit(SQL $db, array &$columns) : void {}

    public static function orm(?string $table = null) : SQL
    {
        return SQL::new()->open($table ?? static::$table);
    }

    /**
     * Convert a Request class to an array
     * @param array<string, mixed>|RequestHelper $columns
     * @return array<string, mixed>
     */
    protected function req_2_array(array|RequestHelper $columns) : array
    {
        if($columns instanceof RequestHelper)
            return $columns->props();

        return $columns;
    }

    /**
     * Should check the DB if a record exists already
     * @param array<string,mixed>|RequestHelper $columns
     * @return bool
     * @abstract Must override if you want to use it
     */
    public function is_duplicate(array|RequestHelper $columns) : bool
    {
        LayException::unimplemented("is_duplicate");

        /**
         * This portion is just here to serve as an example of how to implement this method
         */

        $columns = $this->req_2_array($columns);
        return $this->count("title", $columns['title']) > 0;
    }

    /**
     * @abstract Override this method to use your app's date pattern. Default is epoc-style date 1764222...
     * @return int|string
     */
    protected function timestamp() : int|string
    {
        return LayDate::now();
    }

    /**
     * This should return the id of the user making a database change
     * @return null|string
     */
    public function created_by() : string|null
    {
        if($this->enable_created_by)
            LayException::unimplemented(
                "created_by",
                "If you have no need for this method, specify: `protected bool \$enable_created_by = false;`"
            );

        return null;
    }

    /**
     * Define how insertion (add) will handle conflict when a duplicate unique column is encountered
     * @param SQL $db
     * @return void
     * @abstract Must override if you want conflict resolution
     */
    protected function resolve_conflict(SQL $db) : void
    {
        LayException::unimplemented("resolve_conflict");

        /**
         * This is a sample code for implementation ideas
         */
        $db->on_conflict(
            unique_columns: ['id'],
            update_columns: ['deleted'],
            action: 'UPDATE'
        );
    }

    /**
     * ## Execute every cached pre_run actions
     *
     * This method is protected and not private because we want to give access to implementors to plug the pre_run
     * so their methods can feel native
     *
     * @param SQL $db
     * @return void
     * @throws \Exception
     */
    protected final function exec_pre_run(SQL $db) : void
    {
        foreach($this->pre_run as $query) {
            if(!($query instanceof Closure))
                LayException::throw(
                    "One of the pre query functions is not a callable: " . gettype($query),
                    "ModelPreQueryError"
                );

            $query($db);
        }

        if(!empty($this->pre_run))
            $this->pre_run = [];
    }

    /**
     * @param array<string, string|null>|RequestHelper $columns
     * @param bool $resolve_conflict
     * @return static
     */
    public function add(array|RequestHelper $columns, bool $resolve_conflict = false) : static
    {
        $columns = $this->req_2_array($columns);

        $columns[static::$primary_key_col] ??= 'UUID()';
        $columns[static::$primary_delete_col] ??= "0";

        if($this->enable_created_by)
            $columns[static::$primary_created_by_col] ??= $this->created_by();

        $columns[static::$primary_created_at_col] ??= $this->timestamp();

        $db = static::db();

        if($resolve_conflict)
            $this->resolve_conflict($db);

        if($this->debug_mode)
            $db->debug();

        $this->pre_add($db, $columns);
        $this->exec_pre_run($db);

        if($rtn = $db->insert($columns, true))
            return $this->fill($rtn);

        return $this->unfill();
    }

    /**
     * Add a batch of columns in one go
     * @param array<int,array<string,mixed>> $columns
     * @param null|callable(array<string,mixed>):array<string,mixed> $fun a callback to run inside the batch insert run function for each entry of the row
     * @return bool
     */
    public function add_batch(array|RequestHelper $columns, ?callable $fun = null) : bool
    {
        $columns = $this->req_2_array($columns);

        $db = static::db();

        $this->resolve_conflict($db);

        if($this->debug_mode)
            $db->debug();

        $this->exec_pre_run($db);

        return $db->fun(function ($columns) use ($fun, $db) {
            $columns[static::$primary_key_col] ??= 'UUID()';
            $columns[static::$primary_delete_col] ??= "0";

            if($this->enable_created_by)
                $columns[static::$primary_created_by_col] ??= $this->created_by();

            $columns[static::$primary_created_at_col] ??= $this->timestamp();

            foreach ($columns as $key => $val) {
                if (is_array($val)) $columns[$key] = json_encode($val);
            }

            $this->pre_add($db, $columns);

            if($fun) return $fun($columns);

            return $columns;
        })->insert_multi($columns);
    }

    /**
     * An alias for add_batch
     * @see add_batch
     */
    public function batch(array|RequestHelper $columns, ?callable $fun = null) : bool
    {
        return $this->add_batch($columns, $fun);
    }

    public function count(string $field, string $value_or_operator, ?string $value = null) : int
    {
        $db = static::db();

        if(static::$use_delete)
            $db->where(static::$table . "." . static::$primary_delete_col, '0')
                ->wrap("and", fn(SQL $sql) => $db->where($field, $value_or_operator, $value));
        else
            $db->where($field, $value_or_operator, $value);

        if($this->debug_mode)
            $db->debug_full();

        $this->pre_get($db);
        $this->exec_pre_run($db);

        return $db->count();
    }

    public function one_item(bool $first = true) : static
    {
        $db = static::db();

        if(static::$use_delete)
            $db->where(static::$table . "." . static::$primary_delete_col, '0');

        if($this->debug_mode)
            $db->debug_full();

        if($first)
            $db->sort(static::$primary_key_col, "asc");
        else
            $db->sort(static::$primary_key_col, "desc");

        $this->pre_get($db);
        $this->exec_pre_run($db);

        $db->column($this->fillable($db));

        if($res = $db->assoc()->select())
            return $this->fill($res);

        return $this;
    }

    public function get_by(string $field, string $value_or_operator, ?string $value = null) : static
    {
        $db = static::db();

        if(static::$use_delete)
            $db->where(static::$table . "." . static::$primary_delete_col, '0')
                ->wrap("and", fn(SQL $sql) => $db->where($field, $value_or_operator, $value));
        else
            $db->where($field, $value_or_operator, $value);

        if($this->debug_mode)
            $db->debug_full();

        $this->pre_get($db);
        $this->exec_pre_run($db);

        $db->column($this->fillable($db));

        if($res = $db->assoc()->select())
            return $this->fill($res);

        return $this;
    }

    /**
     * Get entries of multiple values from the same column.
     * This method can be important when you're trying to avoid n+1 queries.
     * You can aggregate the values you want to query, send them once and get an array result
     *
     * @param array<int, string|null|bool> $aggregate
     * @param string|null $column Default column is the primary column set in the child Model
     * @return array<int, array<string, mixed>>
     */
    public function get_by_agr(array $aggregate, ?string $column = null) : array
    {
        $db = self::db();
        $column ??= static::$primary_key_col;

        foreach ($aggregate as $i => $tool) {
            if($i == 0) {
                $db->where($column, $tool);
                continue;
            }

            $db->or_where($column, $tool);
        }

        if($this->debug_mode)
            $db->debug_full();

        $this->as_model($db);

        $this->pre_get($db);
        $this->exec_pre_run($db);

        $db->column($this->fillable($db));

        return $db->loop()->then_select();
    }

    public function by_id(string $id, bool $invalidate = false): static
    {
        if ($invalidate || !isset($this->columns[static::$primary_key_col]) || $this->columns[static::$primary_key_col] !== $id) {
            return $this->get_by(static::$table . "." . static::$primary_key_col, $id);
        }

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(int $page = 1, int $limit = 100) : array
    {
        $db = static::db();

        if(static::$use_delete)
            $db->where(static::$table . "." . static::$primary_delete_col, '0');

        if($this->debug_mode)
            $db->debug_full();

        $this->as_model($db);

        $this->pre_get($db);
        $this->exec_pre_run($db);

        $db->column($this->fillable($db));

        return $db->loop()->limit($limit, $page)->then_select();
    }

    /**
     * An alias for all_by_col.
     * @deprecated use all_by_col
     * @see all_by_col
     * @return array<int, array<string, mixed>>
     */
    public function all_by_id(string $column, string $value_or_operator, ?string $value = null) : array
    {
        return $this->all_by_col($column, $value_or_operator, $value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all_by_col(string $column, string $value_or_operator, ?string $value = null) : array
    {
        $db = static::db();

        if(static::$use_delete)
            $db->where(static::$table . "." . static::$primary_delete_col, '0')
                ->wrap("and", fn() => $db->where($column, $value_or_operator, $value));
        else
            $db->where($column, $value_or_operator, $value);

        if($this->debug_mode)
            $db->debug_full();

        $this->as_model($db);

        $this->pre_get($db);

        $this->exec_pre_run($db);

        $db->column($this->fillable($db));
        return $db->loop()->then_select();
    }

    /**
     * Checks if all the value received exists in the database, hence valid or not.
     * This operation only works on the primary model. It doesn't check the joint models
     *
     * @param array<string|int> $values
     * @param string $column
     * @return bool
     */
    public function all_valid(array $values, string $column) : bool
    {
        $db = self::db();

        $vals = implode(",", $values);

        $db->where($column, "IN", "($vals)");

        if($this->debug_mode)
            $db->debug_full();

        $this->pre_get($db);
        $this->exec_pre_run($db);

        return empty(array_diff(
            $db->column($column)->loop_row(),
            $values
        ));
    }

    /**
     * ### Update a batch of records at once using a where clause for each update.
     * This method uses transaction under the hood, so there's no need for you to wrap it in a transaction.
     *
     * @param array<int, mixed>|RequestHelper $columns
     * @param string $where_col The column to use in our where clause. This method assumes the column value is inside the `$columns` array,
     * and it will extract the value from the array to use properly for the statements, and remove it from the `$columns` to avoid conflict.
     * @param string|null $key_in_columns An alias to the `$where_col` in case the key name in the `$column` is different from the db column name
     * @param callable|null $fun A function that should run on every entry
     * @return bool
     */
    public function edit_batch(array|RequestHelper $columns, string $where_col, ?string $key_in_columns = null, ?callable $fun = null) : bool
    {
        $columns = $this->req_2_array($columns);

        $db = static::db();

        $this->resolve_conflict($db);

        if($this->debug_mode)
            $db->debug();

        $this->exec_pre_run($db);

        $timestamp = $this->timestamp();
        $created_by = $this->created_by();

        $actual_cols = [];
        $cal_ids = [];

        foreach ($columns as $prop) {
            $key = $key_in_columns ?? $where_col;

            $cal_ids[] = $prop[$key];
            unset($prop[$key]);
            $actual_cols[] = $prop;
        }

        $db->where_agr($where_col, $cal_ids);

        return $db->fun(function ($columns) use ($timestamp, $created_by, $fun, $db) {
            if($this->enable_created_by)
                $columns[static::$primary_updated_by_col] ??= $created_by;

            $columns[static::$primary_updated_at_col] ??= $timestamp;

            foreach ($columns as $key => $val) {
                if (is_array($val)) $columns[$key] = json_encode($val);
            }

            $this->pre_edit($db, $columns);
            if($fun) return $fun($columns);

            return $columns;
        })->no_false()->edit_multi($actual_cols);
    }

    /**
     * Edit the db record of a specified record entry
     * @param string $record_id
     * @param array<string, string|null|bool>|RequestHelper $columns
     * @return bool
     */
    public function edit(string $record_id, array|RequestHelper $columns) : bool
    {
        if(empty($record_id))
            LayException::throw("Trying to edit a record but no record id specified", "NoIdEdit");

        $columns = $this->req_2_array($columns);

        if($this->enable_created_by)
            $columns[static::$primary_updated_by_col] ??= $this->created_by();

        $columns[static::$primary_updated_at_col] ??= $this->timestamp();

        $db = static::db()->column($columns)->no_false();

        $db->where(static::$primary_key_col, $record_id);

        if($this->debug_mode)
            $db->debug();

        $this->pre_edit($db, $columns);
        $this->exec_pre_run($db);

        return $db->edit();
    }

    /**
     * Edit the db record of the current model property
     * @param array<string, string|null|bool>|RequestHelper $columns
     * @return bool
     */
    public function edit_self(array|RequestHelper $columns) : bool
    {
        $record_id = $this->{static::$primary_key_col};

        return $this->edit((string) $record_id, $columns);
    }

    /**
     * Soft delete from the table of the specified record
     *
     * @param string $record_id
     * @param string|null $act_by
     * @return bool
     */
    public function delete(string $record_id, ?string $act_by = null) : bool
    {
        $columns = [
            static::$primary_delete_col => 1,
            static::$primary_delete_col . "_at" => $this->timestamp(),
        ];

        if($this->enable_created_by)
            $columns[static::$primary_delete_col . "_by"] = $act_by ?? $this->created_by();

        $db = static::db();

        if($this->debug_mode)
            $db->debug();

        $this->pre_edit($db, $columns);
        $this->exec_pre_run($db);

        return $db->column($columns)->where(static::$primary_key_col, $record_id)->edit();
    }

    /**
     * Soft delete from the table of the current model record
     *
     * @param string|null $act_by
     * @return bool
     */
    public function delete_self(?string $act_by = null) : bool
    {
        $record_id = $this->{static::$primary_key_col};
        return $this->delete($record_id, $act_by);
    }
}