<?php
declare(strict_types=1);

namespace BrickLayer\Lay\Libs\Cron;

use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Orm\SQL;

class CronModel
{
    use IsSingleton;

    public static string $table = "lay_cron_jobs";

    private const SESSION_KEY = "LAY_CRON_JOBS";

    private static function init(): void
    {
        $_SESSION[self::SESSION_KEY]  = $_SESSION[self::SESSION_KEY]  ?? [];

        self::create_table();
    }

    private static function create_table() : void
    {
        if(@$_SESSION[self::SESSION_KEY]['table_exists'] || @$_SESSION[self::SESSION_KEY]['table_created'])
            return;

        // check if table exists, but catch the error
        self::orm()->open(self::$table)->catch()->clause("LIMIT 1")->just_exec()->select();

        $query_info = self::orm()->query_info;

        // Check if the above query had an error. If no error, table exists, else table doesn't exist
        if ($query_info['has_error'] === false) {
            $_SESSION[self::SESSION_KEY]["table_created"] = true;
            return;
        }

        if($query_info['rows'] > 0) {
            $_SESSION[self::SESSION_KEY]["table_exists"] = true;
            return;
        }

        self::orm()->query("CREATE TABLE IF NOT EXISTS `" . self::$table . "` (
              `id` char(36) UNIQUE PRIMARY KEY,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
              `created_by` char(36) DEFAULT NULL,
              `updated_by` char(36) DEFAULT NULL,
              `deleted` int(1) DEFAULT 0,
              `deleted_at` datetime DEFAULT NULL,
              `deleted_by` char(36) DEFAULT NULL,
              `schedule` varchar(100) DEFAULT NULL,
              `script` varchar(200) DEFAULT NULL,
              `use_php` int(1) DEFAULT 1,
              `active` int(11) DEFAULT 1,
              `last_run` datetime DEFAULT NULL
            )
        ");

        $_SESSION[self::SESSION_KEY]["table_exists"] = true;
    }

    public static function orm(?string $table = null) : SQL {
        if($table)
            return SQL::new()->open($table);

        return SQL::new();
    }

    public function uuid() : string {
        return self::orm()->uuid();
    }

    public function job_exists(string $script, string $schedule) : bool
    {
        self::init();

        return (bool) self::orm(self::$table)
            ->where("deleted=0 AND `script`='$script' AND schedule='$schedule'")
            ->count_row("id");
    }

    public function job_list() : array
    {
        self::init();

        return self::orm(self::$table)->loop()
            ->where("deleted=0")
            ->sort("created_at", "desc")
            ->limit(100)
            ->then_select();
    }

    public function job_by_id(string $id, bool $even_deleted = false) : array
    {
        self::init();

        $even_deleted = $even_deleted ? "" : "AND deleted=0";

        return self::orm(self::$table)
            ->where("(id='$id') $even_deleted")
            ->then_select();
    }

    public function delete_job(string $job_id, ?string $act_by = null) : bool
    {
        self::init();

        return self::orm(self::$table)->column([
            "deleted" => 1,
            "deleted_by" => $act_by,
            "deleted_at" => LayDate::date(),
        ])->where("id='$job_id'")->edit();
    }

    public function new_job(array $columns) : bool {
        self::init();

        return self::orm(self::$table)->insert($columns);
    }

    public function edit_job(string $job_id, array $columns, ?string $updated_by = null) : bool
    {
        self::init();

        $columns['updated_by'] ??= $updated_by ?? null;

        return self::orm(self::$table)->column($columns)
            ->no_false()
            ->where("id='$job_id'")
            ->edit();
    }
}