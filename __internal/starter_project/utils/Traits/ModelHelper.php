<?php

namespace Utils\Traits;

use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Orm\SQL;
use JetBrains\PhpStorm\ArrayShape;

trait ModelHelper {
    private static bool $use_delete = true;

    public function uuid() : string
    {
        return self::orm()->uuid();
    }

    private static function attach_delete_column(bool $append_AND = true) : string
    {
        return self::$use_delete ?
            self::$table . ".deleted=0 " . ($append_AND ? 'AND ' : '')
            : "";
    }

    public static function orm(?string $table = null) : SQL
    {
        if($table)
            return SQL::new()->open($table);

        return SQL::new();
    }

    protected static function exists(string $where) : int
    {
        return self::orm(self::$table)
            ->where(self::attach_delete_column() . "($where)")
            ->count_row("id");
    }

    public function add(array $columns) : bool
    {
        $columns['id'] = $columns['id'] ?? 'UUID()';

        return self::orm(self::$table)->insert($columns, false);
    }

    public function list_100(string $sort_column = "name") : array
    {
        return self::orm(self::$table)->loop()
            ->where(self::attach_delete_column(false))
            ->sort($sort_column)
            ->limit(100)
            ->then_select();
    }

    public function get_by(string $where) : array
    {
        return self::orm(self::$table)
            ->where(self::attach_delete_column() . "($where)")
            ->then_select();
    }

    public function get_by_list(string $where) : array
    {
        return self::orm(self::$table)
            ->where(self::attach_delete_column() . "($where)")
            ->loop()->then_select();
    }

    public function edit(string $record_id, array $columns) : bool
    {
        return self::orm(self::$table)->column($columns)
            ->no_false()
            ->where("`id`='$record_id'")
            ->edit();
    }

    public function delete(string $act_by, string $record_id = null) : bool
    {
        return self::orm(self::$table)->column([
            "deleted" => 1,
            "deleted_by" => $act_by,
            "deleted_at" => LayDate::date(),
        ])->where("`id`='$record_id'")->edit();
    }
}