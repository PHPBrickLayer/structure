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
     * @param callable(self):array<int|string, mixed> $each
     * @return self
     */
    public function each(callable $each) : self
    {
        return $this->pre_run(
            function(SQL $db) use ($each) {
                $db->each(
                    fn($data): array => $each($this->fill($data))
                );
            }
        );
    }

    public function pre_run(callable $db_callback) : self
    {
        $this->pre_run[] = $db_callback;
        return $this;
    }

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
        throw new \RuntimeException("Unimplemented Method: Must override resolve conflict");

        /**
         * This is a sample code for implementation ideas
         */
        $db->on_conflict(
            unique_columns: ['id'],
            update_columns: ['deleted'],
            action: 'UPDATE'
        );
    }

    private function exec_pre_run(SQL $db) : void
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
        if($columns instanceof RequestHelper)
            $columns = $columns->props();

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

        $this->exec_pre_run($db);

        if($rtn = $db->insert($columns, true))
            return $this->fill($rtn);

        return $this->unfill();
    }

    // TODO: Ensure batch uploads uses transaction; also add an insert_conflict resolution strategy
    public function batch(array $columns) : bool
    {
        return static::db()->insert_multi($columns, true);
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

        $this->exec_pre_run($db);

        return $db->loop()->limit($limit, $page)->then_select();
    }

    public function count(string $field, string $value_or_operator, ?string $value = null) : int
    {
        $db = static::db();

        if(static::$use_delete)
            $db->where(static::$table . "." . static::$primary_delete_col, '0')
                ->bracket(fn(SQL $sql) => $db->where($field, $value_or_operator, $value), 'and');
        else
            $db->where($field, $value_or_operator, $value);

        if($this->debug_mode)
            $db->debug_full();

        $this->exec_pre_run($db);

        return $db->count();
    }

    public function get_by(string $field, string $value_or_operator, ?string $value = null) : static
    {
        $db = static::db();

        if(static::$use_delete)
            $db->where(static::$table . "." . static::$primary_delete_col, '0')
                ->bracket(fn(SQL $sql) => $db->where($field, $value_or_operator, $value), 'and');
        else
            $db->where($field, $value_or_operator, $value);

        if($this->debug_mode)
            $db->debug_full();

        $this->exec_pre_run($db);

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

        $this->exec_pre_run($db);

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
                ->bracket(fn() => $db->where($column, $value_or_operator, $value), 'and');
        else
            $db->where($column, $value_or_operator, $value);

        if($this->debug_mode)
            $db->debug_full();

        $this->exec_pre_run($db);

        return $db->loop()->then_select();
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

        if($columns instanceof RequestHelper)
            $columns = $columns->props();

        if($this->enable_created_by)
            $columns[static::$primary_updated_by_col] ??= $this->created_by();

        $columns[static::$primary_updated_at_col] ??= $this->timestamp();

        $db = static::db()->column($columns)->no_false();

        $db->where(static::$primary_key_col, $record_id);

        if($this->debug_mode)
            $db->debug();

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

        $cols = [
            static::$primary_delete_col => 1,
            static::$primary_delete_col . "_at" => $this->timestamp(),
        ];

        if($this->enable_created_by)
            $cols[static::$primary_delete_col . "_by"] = $act_by ?? $this->created_by();

        $db = static::db()->column($cols);

        if($this->debug_mode)
            $db->debug();

        $this->exec_pre_run($db);

        return $db->where(static::$primary_key_col, $record_id)->edit();
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