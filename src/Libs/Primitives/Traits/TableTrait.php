<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Primitives\Traits;

use BrickLayer\Lay\Core\LayConfig;
use BrickLayer\Lay\Libs\Cron\CronController;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Orm\SQL;
use JetBrains\PhpStorm\ExpectedValues;

trait TableTrait
{
    use ControllerHelper;

    protected static ?string $created_by;
    protected static string $created_table_name;

    final protected static function create_table() : void
    {
        $project_identity = LayConfig::get_project_identity();
        $table_exist = $_SESSION[self::$SESSION_KEY][self::$created_table_name]['table_exists'] ?? null;

        if($table_exist and $table_exist == $project_identity)
            return;

        // check if table exists, but catch the error
        self::orm()->open(self::$table)->catch()->clause("LIMIT 1")->just_exec()->select();

        $query_info = self::orm()->query_info;

        // Check if the above query had an error. If no error, table exists, else table doesn't exist
        // Or if it found at least one row on the table, then it exists
        if ($query_info['has_error'] === false || $query_info['rows'] > 0) {
            $_SESSION[self::$SESSION_KEY][self::$created_table_name]['table_exists'] = $project_identity;
            return;
        }

        self::table_creation_query();

        $_SESSION[self::$SESSION_KEY][self::$created_table_name]["table_exists"] = $project_identity;
    }

    protected static function init(string $table): void
    {
        $_SESSION[self::$SESSION_KEY]  = $_SESSION[self::$SESSION_KEY]  ?? [];
        self::$created_table_name = $table;

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
        self::init(self::$table);

        return self::orm(self::$table)->where("deleted", "1")->delete();
    }

    public function delete_record(string $id, ?string $act_by = null) : bool
    {
        self::init(self::$table);

        return self::orm(self::$table)->column([
            "deleted" => 1,
            "deleted_by" => $act_by,
            "deleted_at" => LayDate::date(),
        ])->where("id='$id'")->edit();
    }

    public function record_list(int $page = 1, int $limit = 100) : array|\Generator
    {
        self::init(self::$table);

        return self::orm(self::$table)->loop()
            ->where("deleted=0")
            ->sort("created_at", "desc")
            ->limit($limit, $page)
            ->then_select();
    }

    public function record_by_id(string $id, bool $even_deleted = false) : array|\Generator
    {
        self::init(self::$table);

        $even_deleted = $even_deleted ? "" : "AND deleted=0";

        return self::orm(self::$table)
            ->where("(id='$id') $even_deleted")
            ->then_select();
    }

    public function new_record(array $columns) : bool {
        self::init(self::$table);

        return (bool) self::orm(self::$table)->insert($columns);
    }

    public function edit_record(string $job_id, array $columns, ?string $updated_by = null) : bool
    {
        self::init(self::$table);

        $columns['updated_by'] ??= $updated_by ?? null;

        return self::orm(self::$table)->column($columns)
            ->where("id='$job_id'")
            ->edit();
    }


    ////               ////
    ///     HELPERS    ////
    ///                ////

    public static function created_by(?string $actor_id) : void
    {
        self::$created_by = $actor_id;
    }

    /**
     * This response is for functions like cookieStorage that doesn't need to set http_response_code,
     * yet it needs to return an array
     *
     * @param int $code
     * @param string $message
     * @param array $data
     *
     * @return (array|int|string)[]
     *
     * @psalm-return array{code: int, message: string, data: array}
     */
    public static function response(#[ExpectedValues([0,1,2])] int $code, string $message, array $data = []) : array
    {
        return [
            "code" => $code,
            "message" => $message,
            "data" => $data
        ];
    }

}