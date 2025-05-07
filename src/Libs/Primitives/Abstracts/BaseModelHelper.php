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

    public function add(array $columns) : ?static
    {
        $columns[static::$primary_key_col] ??= 'UUID()';
        $columns['created_at'] ??= LayDate::now();

        $rtn = static::db()->insert($columns, true);

        if(!$rtn)
            return null;

        return $this->fill($rtn);
    }

    public static function create(array $columns): ?static
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

    public function by_id(string $id, bool $useCache = true): ?static
    {
        if (
            $useCache &&
            isset(static::$columns[static::$primary_key_col]) &&
            static::$columns[static::$primary_key_col] === $id
        ) {
            return $this;
        }

        return $this->get_by(static::$primary_key_col, $id);
    }

    public function all_by_id(string $field, string $value_or_operator, ?string $value = null) : array
    {
        $orm = static::db();

        if(static::$use_delete)
            $orm->where(static::$primary_delete_col, '0')
                ->bracket(fn() => $orm->where($field, $value_or_operator, $value), 'and');
        else
            $orm->where($field, $value_or_operator, $value);

        return $orm->loop()->then_select();
    }

    public function edit(string $record_id, array $columns) : bool
    {
        $orm = static::db()->column($columns)->no_false();

        $orm->where(static::$primary_key_col, $record_id);

        return $orm->edit();
    }

    public function self_edit(array $columns) : bool
    {
        $record_id = $this->{static::$primary_key_col};

        return $this->edit((string) $record_id, $columns);
    }

    /**
     * Soft delete from the table
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
            static::$primary_delete_col . "_at" => LayDate::now(),
        ]);

        return $orm->where(static::$primary_key_col, $record_id)->edit();
    }
}