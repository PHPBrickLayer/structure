<?php

namespace BrickLayer\Lay\Libs\Cron;

use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Libs\Abstract\TableTrait;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayFn;

class CronController
{
    use IsSingleton;
    use TableTrait;

    public const JOB_CLI_KEY = "--job-uuid";


    ////                 ////
    ///     MODEL       ////
    ///                ////

    protected static string $table = "lay_cron_jobs";
    protected static string $SESSION_KEY = "LAY_CRON_JOBS";

    protected static function table_creation_query() : void
    {
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
    }

    public function job_exists(string $script, string $schedule) : array
    {
        self::init();

        return self::orm(self::$table)
            ->where("deleted=0 AND `script`='$script' AND schedule='$schedule'")
            ->then_select();
    }


    ////               ////
    ///     Methods    ////
    ///                ////

    public function delete(): array
    {
        $job_id = self::get_json()->id;

        self::cleanse($job_id);

        if (!LayCron::new()->unset($job_id)) {
            $this->delete_record($job_id, self::$created_by);
            return self::resolve(2, "Could not delete job, maybe job has been deleted already");
        }

        if ($this->delete_record($job_id, self::$created_by))
            return self::resolve(1, "Cron job deleted successfully");

        return self::resolve();
    }

    public function prune_table() : array
    {
        if($this->empty_trash())
            return self::resolve(1, "Deleted jobs have been removed from the DB");

        return self::resolve();
    }

    public function add(): array
    {
        $post = self::get_json();

        $raw_schedule = $post->schedule;
        $raw_script = $post->script;

        self::cleanse($post->schedule);
        self::cleanse($post->script);
        self::cleanse($post->use_php, strict: false);

        if ($job = $this->job_exists($post->script, $post->schedule)) {
            $this->play_script($job['id']);
            return self::resolve(1, "Job exists already! Reactivated successfully");
        }

        $id = $this->uuid();
        $raw_script .= " " . self::JOB_CLI_KEY . " " . $id;

        $cron = LayCron::new()->job_id($id);

        if ($post->use_php)
            $res = $cron->schedule(...explode(" ", $raw_schedule))->new_job($raw_script);
        else
            $res = $cron->exec($raw_schedule . " " . $raw_script)[''];

        if (!$res['exec'])
            return self::resolve(0, $res['msg']);

        if (
            !$this->new_record([
                "id" => $id,
                "script" => $post->script,
                "schedule" => $post->schedule,
                "use_php" => $post->use_php ? 1 : 0,
                "created_by" => self::$created_by
            ])
        ) return self::resolve();


        return self::resolve(1, "Job added successfully!");
    }

    public function extract_job_id(array $arg_values): ?string
    {
        return LayFn::extract_cli_tag(self::JOB_CLI_KEY, true);
    }

    public function update_last_run(string $job_id): bool
    {
        return $this->edit_record($job_id, [
            "last_run" => LayDate::date()
        ]);
    }

    public function run_script() : array
    {
        $job_id = self::get_json()->id;

        self::cleanse($job_id);

        $job = LayCron::new()->get_job($job_id);

        if($job) {
            $bin = $job['binary'];

            // Extract Job uuid and attach it to the tag variable
            $sc_frag = explode(self::JOB_CLI_KEY, $job['script']);
            $tag = self::JOB_CLI_KEY . " " . $sc_frag[1];

            // Extract any further tags attached to the script so we can safely wrap the script in a single quote
            $sc = explode(" ", $sc_frag[0], 2);
            $script = "'$sc[0]' " . ($sc[1] ?? '');
        }

        else {
            $job = $this->get_job($job_id);
            $bin = $job['use_php'] == 1 ? LayCron::php_bin() : "";
            $tag = self::JOB_CLI_KEY . " " . $job['id'];
            $script = $job['script'];
        }

        exec("'$bin' $script $tag", $out);

        return self::resolve(1, "Script executed!", ['output' => implode(PHP_EOL , $out ?? '')]);
    }

    public function pause_script() : array
    {
        $job_id = self::get_json()->id;

        if (!LayCron::new()->unset($job_id))
            return self::resolve(2, "Could not pause job, maybe job has been paused already");

        if(
            $this->edit_record($job_id, [
                "active" => '0'
            ], self::$created_by)
        ) return self::resolve(1, "Script paused successfully");

        return self::resolve();
    }

    public function play_script(?string $job_id = null) : array
    {
        $job_id ??= self::get_json()->id;
        $job = $this->get_job($job_id);

        $raw_script = $job['script'] . " " . self::JOB_CLI_KEY . " " . $job_id;
        $raw_schedule = $job['schedule'];

        $cron = LayCron::new()->job_id($job_id);

        if ($job['use_php'])
            $res = $cron->schedule(...explode(" ", $raw_schedule))->new_job($raw_script);
        else
            $res = $cron->exec($raw_schedule . " " . $raw_script)[''];

        if (!$res['exec'])
            return self::resolve(0, $res['msg']);

        if(
            $this->edit_record($job_id, [
                "active" => 1,
            ], self::$created_by)
        ) return self::resolve(1, "Script executed successfully");

        return self::resolve();
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