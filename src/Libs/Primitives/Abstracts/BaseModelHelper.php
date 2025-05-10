<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Primitives\Abstracts;

use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\Primitives\Traits\IsFillable;
use BrickLayer\Lay\Orm\SQL;

abstract class BaseModelHelper
{
    use IsFillable;

    /**
     * @var string
     * @abstract Overwrite when necessary
     */
    protected static string $primary_delete_col = "deleted";

    protected static bool $use_delete = true;

    public function uuid() : string
    {
        return static::db()->uuid();
    }

    public static function orm(?string $table = null) : SQL
    {
        return SQL::new()->open($table ?? static::$table);
    }

    /**
     * Should check the DB if a record exists already
     * @param array|RequestHelper $columns
     * @return mixed
     * @abstract Must override if you want to use it
     */
    public function is_duplicate(array|RequestHelper $columns) : bool
    {
        throw new \RuntimeException("Unimplemented Method");

        /**
         * This portion is just here to serve as an example of how to implement this method
         */

        if($columns instanceof RequestHelper)
            $columns = $columns->props();

        return self::db()
                ->where("title", $columns['title'])
                ->and_where("deleted", '0')
                ->count() > 0;
    }

    /**
     * @abstract Override this method to use your app's date pattern. Default is epoc-style date 1764222...
     * @return int|string
     */
    protected function timestamp() : int|string
    {
        return LayDate::now();
    }

    public function add(array|RequestHelper $columns) : ?static
    {
        if($columns instanceof RequestHelper)
            $columns = $columns->props();

        $columns[static::$primary_key_col] ??= 'UUID()';
        $columns['created_at'] ??= $this->timestamp();

        if($rtn = static::db()->insert($columns, true))
            return $this->fill($rtn);

        return null;
    }

    public static function create(array|RequestHelper $columns): ?static
    {
        return (new static())->add($columns);
    }

    // TODO: Ensure batch uploads uses transaction; also add an insert_conflict resolution strategy
    public function batch(array $columns) : bool
    {
        return static::db()->insert_multi($columns, true);
    }

    /**
     * @return array<int, array>
     */
    public function all(int $page = 1, int $limit = 100) : array
    {
        $orm = static::db();

        if(static::$use_delete)
            $orm->where(static::$primary_delete_col, '0');

        return $orm->loop()->limit($limit, $page)->then_select();
    }

    public function get_by(string $field, string $value_or_operator, ?string $value = null) : self
    {
        $orm = static::db();

        if(static::$use_delete)
            $orm->where(static::$primary_delete_col, '0')
                ->bracket(fn() => $orm->where($field, $value_or_operator, $value), 'and');
        else
            $orm->where($field, $value_or_operator, $value);

        if($res = $orm->assoc()->select())
            $this->fill($res);

        return $this;
    }


    /**
     * Get entries of multiple values from the same column.
     * This method can be important when you're trying to avoid n+1 queries.
     * You can aggregate the values you want to query, send them once and get an array result
     *
     * @param array $aggregate
     * @param string|null $column Default column is the primary column set in the child Model
     * @return array
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

        return $db->loop()->then_select();
    }

    public function by_id(string $id, bool $useCache = true): ?static
    {
        if (
            $useCache &&
            isset($this->columns[static::$primary_key_col]) &&
            $this->columns[static::$primary_key_col] === $id
        ) {
            return $this;
        }

        return $this->get_by(static::$primary_key_col, $id);
    }

    public function all_by_id(string $column, string $value_or_operator, ?string $value = null) : array
    {
        $orm = static::db();

        if(static::$use_delete)
            $orm->where(static::$primary_delete_col, '0')
                ->bracket(fn() => $orm->where($column, $value_or_operator, $value), 'and');
        else
            $orm->where($column, $value_or_operator, $value);

        return $orm->loop()->then_select();
    }

    /**
     * Edit the db record of a specified record entry
     * @param string $record_id
     * @param array|RequestHelper $columns
     * @return bool
     */
    public function edit(string $record_id, array|RequestHelper $columns) : bool
    {
        if($columns instanceof RequestHelper)
            $columns = $columns->props();

        $orm = static::db()->column($columns)->no_false();

        $orm->where(static::$primary_key_col, $record_id);

        return $orm->edit();
    }

    /**
     * Edit the db record of the current model property
     * @param array|RequestHelper $columns
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
     * @param string $act_by
     * @param string|null $record_id
     * @return bool
     */
    public function delete(string $act_by, ?string $record_id = null) : bool
    {
        $orm = static::db()->column([
            static::$primary_delete_col => 1,
            static::$primary_delete_col . "_by" => $act_by,
            static::$primary_delete_col . "_at" => $this->timestamp(),
        ]);

        return $orm->where(static::$primary_key_col, $record_id)->edit();
    }

    /**
     * Soft delete from the table of the current model record
     *
     * @param string $act_by
     * @return bool
     */
    public function delete_self(string $act_by) : bool
    {
        $record_id = $this->{static::$primary_key_col};
        return $this->delete($act_by, $record_id);
    }
}