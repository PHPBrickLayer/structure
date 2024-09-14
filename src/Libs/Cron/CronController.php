<?php

namespace BrickLayer\Lay\Libs\Cron;

use BrickLayer\Lay\Core\Api\Enums\ApiStatus;
use BrickLayer\Lay\Core\Traits\IsSingleton;
use BrickLayer\Lay\Libs\LayDate;
use BrickLayer\Lay\Libs\LayObject;
use BrickLayer\Lay\Libs\String\Enum\EscapeType;
use BrickLayer\Lay\Libs\String\Escape;

class CronController
{
    use IsSingleton;

    private const JOB_CLI_KEY = "--job-uuid";
    private static string $created_by;

    ////               ////
    ///     HELPERS    ////
    ///                ////

    private static function resolve(int|ApiStatus $code = 409, ?string $message = null, ?array $data = null): array
    {
        $code = is_int($code) ? $code : $code->value;

        return [
            "code" => $code,
            "msg" => $message ?? "Request could not be processed at the moment, please try again later",
            "data" => $data
        ];
    }

    private static function cleanse(mixed &$value, EscapeType $type = EscapeType::STRIP_TRIM_ESCAPE, bool $strict = true)
    {
        $value = $value ? Escape::clean($value, $type, ['strict' => $strict]) : "";
        return $value;
    }

    private static function get_json(bool $throw_error = true): bool|null|object
    {
        return LayObject::new()->get_json($throw_error);
    }

    public static function created_by(string $actor_id) : void
    {
        self::$created_by = $actor_id;
    }

    public static function model(): CronModel
    {
        return CronModel::new();
    }

    ////               ////
    ///     Methods    ////
    ///                ////

    public function delete(): array
    {
        $job_id = self::get_json()->id;

        self::cleanse($job_id);

        if (!LayCron::new()->unset($job_id)) {
            self::model()->delete_job($job_id, self::$created_by);
            return self::resolve(2, "Could not delete job, maybe job has been deleted already");
        }

        if (self::model()->delete_job($job_id, self::$created_by))
            return self::resolve(1, "Cron job deleted successfully");

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

        if (self::model()->job_exists($post->script, $post->schedule))
            return self::resolve(1, "Job exists already!");

        $id = self::model()->uuid();
        $raw_script .= " " . self::JOB_CLI_KEY . " " . $id;

        $cron = LayCron::new()->job_id($id);

        if ($post->use_php)
            $res = $cron->schedule(...explode(" ", $raw_schedule))->new_job($raw_script);
        else
            $res = $cron->exec($raw_schedule . " " . $raw_script)[''];

        if (!$res['exec'])
            return self::resolve(0, $res['msg']);

        if (
            !self::model()->new_job([
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
        $job_key = array_search(self::JOB_CLI_KEY, $arg_values);
        $job_id = null;

        if ($job_key !== false)
            $job_id = $arg_values[$job_key + 1];

        return $job_id;
    }

    public function update_last_run(string $job_id): void
    {
        self::model()->edit_job($job_id, [
            "last_run" => LayDate::date()
        ]);
    }

    public function run_script() : array
    {
        $job_id = self::get_json()->id;

        self::cleanse($job_id);

        $job = LayCron::new()->get_job($job_id);

        return self::resolve(1, "Script executed successfully. Response: " . exec($job['binary'] . " " . $job['script']));
    }

    public function pause_script() : array
    {
        $job_id = self::get_json()->id;

        if (!LayCron::new()->unset($job_id))
            return self::resolve(2, "Could not pause job, maybe job has been paused already");

        if(
            self::model()->edit_job($job_id, [
                "active" => '0'
            ], self::$created_by)
        ) return self::resolve(1, "Script paused successfully");

        return self::resolve();
    }

    public function play_script() : array
    {
        $job_id = self::get_json()->id;
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
            self::model()->edit_job($job_id, [
                "active" => 1,
            ], self::$created_by)
        ) return self::resolve(1, "Script executed successfully");

        return self::resolve();
    }

    public function list(): array
    {
        return self::model()->job_list();
    }

    public function get_job(string $id) : array
    {
        self::cleanse($id);
        return self::model()->job_by_id($id);
    }


}