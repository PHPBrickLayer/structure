<?php

namespace BrickLayer\Lay\Libs\Cron;

use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\Primitives\Abstracts\RequestHelper;
use BrickLayer\Lay\Libs\Primitives\Traits\IsSingleton;
use BrickLayer\Lay\Libs\Primitives\Traits\TableTrait;

final class CronController
{
    use IsSingleton;
    use TableTrait;


    ////                 ////
    ///     MODEL       ////
    ///                ////

    protected static string $table = "lay_cron_jobs";
    protected static string $SESSION_KEY = "LAY_CRON_JOBS";

    protected static function table_creation_query() : void
    {
        self::orm()->query("CREATE TABLE IF NOT EXISTS " . self::$table . " (
              id char(36) UNIQUE PRIMARY KEY,
              created_at timestamp NOT NULL,
              updated_at timestamp NULL DEFAULT NULL,
              created_by char(36) DEFAULT NULL,
              updated_by char(36) DEFAULT NULL,
              deleted int DEFAULT 0,
              deleted_at timestamp DEFAULT NULL,
              deleted_by char(36) DEFAULT NULL,
              schedule varchar(100) DEFAULT NULL,
              script varchar(200) DEFAULT NULL,
              use_php int DEFAULT 1,
              active int DEFAULT 1,
              last_run timestamp DEFAULT NULL
            )
        ");
    }

    public function job_exists(string $script, string $schedule) : array
    {
        self::init(self::$table);

        return self::orm(self::$table)
            ->where("deleted","0")
            ->and_where("script","$script")
            ->and_where("schedule","$schedule")
            ->then_select();
    }


    ////               ////
    ///     Methods    ////
    ///                ////

    /**
     * @return (array|int|null|string)[]
     *
     * @psalm-return array{code: int, status: string, message: string, data: array|null}
     */
    public function delete(): array
    {
        $job_id = RequestHelper::request()->id;

        self::cleanse($job_id);

        if (!LayCron::new()->unset($job_id)) {
            $this->delete_record($job_id, self::$created_by);
            return self::res_warning("Could not delete job, maybe job has been deleted already");
        }

        if ($this->delete_record($job_id, self::$created_by))
            return self::res_success( "Cron job deleted successfully");

        return self::res_warning();
    }

    /**
     * @return (array|int|null|string)[]
     *
     * @psalm-return array{code: int, status: string, message: string, data: array|null}
     */
    public function prune_table() : array
    {
        if($this->empty_trash())
            return self::res_warning("Deleted jobs have been removed from the DB");

        return self::res_warning();
    }

    /**
     * @return (array|int|null|string)[]
     *
     * @psalm-return array{code: int, status: string, message: string, data: array|null}
     */
    public function add(): array
    {
        $post = RequestHelper::request();

        $raw_schedule = $post->schedule;
        $raw_script = $post->script;

        self::cleanse($post->schedule);
        self::cleanse($post->script);
        self::cleanse($post->use_php, strict: false);

        if ($job = $this->job_exists($post->script, $post->schedule)) {
            $this->play_script($job['id']);
            return self::res_success("Job exists already! Reactivated successfully");
        }

        $id = $this->uuid();

        $cron = LayCron::new()->job_id($id);

        $raw_schedule = trim($raw_schedule);

        if ($post->use_php)
            $res = $cron->schedule(...explode(" ", $raw_schedule))->new_job($raw_script);
        else
            $res = $cron->exec($raw_schedule . " " . $raw_script);

        if (!$res['exec'])
            return self::res_warning($res['msg']);

        if (
            !$this->new_record([
                "id" => $id,
                "script" => $post->script,
                "schedule" => $post->schedule,
                "use_php" => $post->use_php ? 1 : 0,
                "created_by" => self::$created_by,
                "created_at" => LayDate::date()
            ])
        ) return self::res_warning();


        return self::res_success( "Job added successfully!");
    }

    public function update_last_run(string $job_id): bool
    {
        return $this->edit_record($job_id, [
            "last_run" => LayDate::date()
        ]);
    }

    /**
     *
     * @return array{code: int, status: string, message: string, data: array|null}
     */
    public function run_script() : array
    {
        $job_id = RequestHelper::request()->id;

        self::cleanse($job_id);

        $job = LayCron::new()->get_job($job_id);

        if($job) {
            $bin = $job['binary'];

            // Extract Job uuid and attach it to the tag variable
            $sc_frag = explode("--id", $job['script']);

            // Extract any further tags attached to the script so we can safely wrap the script in a single quote
            $sc = explode(" ", $sc_frag[0], 2);
            $script = "'$sc[0]' " . ($sc[1] ?? '');
        }

        else {
            $job = $this->get_job($job_id);
            $bin = $job['use_php'] == 1 ? LayCron::php_bin() : "";
            $script = $job['script'];
        }

        exec("'$bin' $script", $out);

        return self::res_success( "Script executed!", ['output' => implode(PHP_EOL , $out ?? '')]);
    }

    /**
     * @return (array|int|null|string)[]
     *
     * @psalm-return array{code: int, status: string, message: string, data: array|null}
     */
    public function pause_script() : array
    {
        $job_id = RequestHelper::request()->id;

        if (!LayCron::new()->unset($job_id))
            return self::res_warning( "Could not pause job, maybe job has been paused already");

        if(
            $this->edit_record($job_id, [
                "active" => '0'
            ], self::$created_by)
        ) return self::res_success( "Script paused successfully");

        return self::res_warning();
    }

    /**
     * @return (array|int|null|string)[]
     *
     * @psalm-return array{code: int, status: string, message: string, data: array|null}
     */
    public function play_script(?string $job_id = null) : array
    {
        $job_id ??= RequestHelper::request()->id;
        $job = $this->get_job($job_id);

        $raw_script = $job['script'];
        $raw_schedule = $job['schedule'];

        $cron = LayCron::new()->job_id($job_id);

        if ($job['use_php'])
            $res = $cron->schedule(...explode(" ", $raw_schedule))->new_job($raw_script);
        else
            $res = $cron->exec($raw_schedule . " " . $raw_script);

        if (!$res['exec'])
            return self::res_warning($res['message']);

        if(
            $this->edit_record($job_id, [
                "active" => 1,
            ], self::$created_by)
        ) return self::res_success( "Script executed successfully");

        return self::res_warning();
    }

    public function list(): array
    {
        return $this->record_list();
    }

    public function list_by_page(int $page = 1): array
    {
        return $this->record_list($page);
    }

    public function get_job(string $id) : array
    {
        self::cleanse($id);
        return $this->record_by_id($id);
    }


}