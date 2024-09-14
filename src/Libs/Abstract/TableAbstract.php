<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Abstract;

use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Libs\Cron\CronController;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayObject;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;
use BrickLayer\Lay\Orm\SQL;

abstract class TableAbstract
{
    protected static string $SESSION_KEY;
    protected static string $table;
    protected static string $created_by;

    final protected static function create_table() : void
    {
        if(@$_SESSION[self::$SESSION_KEY]['table_exists'] || @$_SESSION[self::$SESSION_KEY]['table_created'])
            return;

        // check if table exists, but catch the error
        self::orm()->open(self::$table)->catch()->clause("LIMIT 1")->just_exec()->select();

        $query_info = self::orm()->query_info;

        // Check if the above query had an error. If no error, table exists, else table doesn't exist
        if ($query_info['has_error'] === false) {
            $_SESSION[self::$SESSION_KEY]["table_created"] = true;
            return;
        }

        if($query_info['rows'] > 0) {
            $_SESSION[self::$SESSION_KEY]["table_exists"] = true;
            return;
        }

        self::table_creation_query();

        $_SESSION[self::$SESSION_KEY]["table_exists"] = true;
    }

    protected static function init(): void
    {
        $_SESSION[self::$SESSION_KEY]  = $_SESSION[self::$SESSION_KEY]  ?? [];

        self::create_table();
    }

    protected static function orm(?string $table = null) : SQL {
        if($table)
            return SQL::new()->open($table);

        return SQL::new();
    }

    public function uuid() : string {
        return self::orm()->uuid();
    }

    /**
     * Override this method and implement your own creation function
     * @see CronController
     * @return void
     */
    protected static function table_creation_query() : void {}

    public function empty_trash() : bool
    {
        self::init();

        return self::orm(self::$table)->delete("deleted=1'");
    }

    public function delete_record(string $id, ?string $act_by = null) : bool
    {
        self::init();

        return self::orm(self::$table)->column([
            "deleted" => 1,
            "deleted_by" => $act_by,
            "deleted_at" => LayDate::date(),
        ])->where("id='$id'")->edit();
    }

    public function record_list(int $page = 1, int $limit = 100) : array
    {
        self::init();

        return self::orm(self::$table)->loop()
            ->where("deleted=0")
            ->sort("created_at", "desc")
            ->limit($limit, $page)
            ->then_select();
    }

    public function record_by_id(string $id, bool $even_deleted = false) : array
    {
        self::init();

        $even_deleted = $even_deleted ? "" : "AND deleted=0";

        return self::orm(self::$table)
            ->where("(id='$id') $even_deleted")
            ->then_select();
    }

    public function new_record(array $columns) : bool {
        self::init();

        return self::orm(self::$table)->insert($columns);
    }

    public function edit_record(string $job_id, array $columns, ?string $updated_by = null) : bool
    {
        self::init();

        $columns['updated_by'] ??= $updated_by ?? null;

        return self::orm(self::$table)->column($columns)
            ->no_false()
            ->where("id='$job_id'")
            ->edit();
    }


    ////               ////
    ///     HELPERS    ////
    ///                ////

    protected static function resolve(int|ApiStatus $code = 409, ?string $message = null, ?array $data = null): array
    {
        $code = is_int($code) ? $code : $code->value;

        return [
            "code" => $code,
            "msg" => $message ?? "Request could not be processed at the moment, please try again later",
            "data" => $data
        ];
    }

    protected static function cleanse(mixed &$value, EscapeType $type = EscapeType::STRIP_TRIM_ESCAPE, bool $strict = true)
    {
        $value = $value ? Escape::clean($value, $type, ['strict' => $strict]) : "";
        return $value;
    }

    protected static function get_json(bool $throw_error = true): bool|null|object
    {
        return LayObject::new()->get_json($throw_error);
    }

    public static function created_by(string $actor_id) : void
    {
        self::$created_by = $actor_id;
    }

}